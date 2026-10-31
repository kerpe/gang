<?php

declare(strict_types=1);

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        start_session();
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $user = sql('SELECT * FROM users WHERE id = ? AND blocked = 0', [$_SESSION['uid']])->fetch() ?: null;
        }
    }
    return $user;
}

function is_admin(?array $user = null): bool
{
    $user ??= current_user();
    return $user !== null && strcasecmp($user['email'], (string) config()['admin_email']) === 0;
}

function require_user(?string $kind = null): array
{
    $user = current_user();
    if (!$user) {
        start_session();
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/mano';
        redirect('/prisijungti');
    }
    if ($kind !== null && $user['kind'] !== $kind) {
        flash($kind === 'manager' ? 'Šis veiksmas skirtas darbdaviams.' : 'Šis veiksmas skirtas darbuotojams.');
        redirect('/mano');
    }
    return $user;
}

function profile_complete(array $u): bool
{
    return $u['name'] !== '' && $u['phone'] !== '' && ($u['kind'] === 'manager' || ($u['roles'] !== '' && $u['cities'] !== '' && (int) $u['experience'] > 0));
}

function login_page(): never
{
    start_session();
    if (isset($_GET['kaip']) && in_array($_GET['kaip'], ['darbuotojas', 'vadovas'], true)) {
        $_SESSION['signup_kind'] = $_GET['kaip'] === 'vadovas' ? 'manager' : 'worker';
    }
    if (current_user()) {
        redirect('/mano');
    }
    $error = '';
    $email = '';
    if (is_post()) {
        check_csrf();
        $email = mb_strtolower(input('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Patikrinkite el. pašto adresą.';
        } else {
            $recent = (int) sql('SELECT COUNT(*) FROM login_codes WHERE email = ? AND created_at > ?', [$email, date('Y-m-d H:i:s', time() - 3600)])->fetchColumn();
            if ($recent >= 5) {
                $error = 'Viršytas bandymų skaičius. Bandykite dar kartą po valandos.';
            } else {
                $code = (string) random_int(100000, 999999);
                insert('login_codes', ['email' => $email, 'code_hash' => hash('sha256', $email.$code), 'expires_at' => date('Y-m-d H:i:s', time() + 900), 'created_at' => now()]);
                send_mail($email, "GANG prisijungimo kodas: $code", "Laba diena,\n\njūsų prisijungimo prie GANG.lt kodas:\n\n$code\n\nKodas galioja 15 minučių. Jei kodo neprašėte, šį laišką galite ištrinti.");
                $_SESSION['login_email'] = $email;
                redirect('/prisijungti/kodas');
            }
        }
    }
    $kind = $_SESSION['signup_kind'] ?? '';
    $title = match ($kind) {
        'worker' => 'Registracija darbuotojams',
        'manager' => 'Registracija darbdaviams',
        default => 'Prisijungti arba užsiregistruoti',
    };
    $lead = match ($kind) {
        'worker' => 'Įveskite el. pašto adresą ir atsiųsime prisijungimo kodą. Tada užpildysite trumpą profilį ir galėsite gauti darbo pasiūlymus iš barų, restoranų ir kavinių.',
        'manager' => 'Įveskite el. pašto adresą ir atsiųsime prisijungimo kodą. Tada užregistruosite savo barą, restoraną ar kavinę ir galėsite skelbti darbuotojų poreikius.',
        default => 'Slaptažodžio nereikia: įveskite el. pašto adresą ir atsiųsime 6 skaitmenų prisijungimo kodą.',
    };
    page($title, '<section class="wrap narrow"><h1>'.$title.'</h1><p class="lead">'.$lead.'</p>'
        .'<form method="post" class="form" novalidate>'.csrf_field()
        .'<label>El. paštas <input type="email" name="email" value="'.e($email).'" autocomplete="email" inputmode="email" required autofocus>'.($error ? '<span class="err">'.e($error).'</span>' : '').'</label>'
        .'<button class="btn">Siųsti kodą</button></form></section>', ['noindex' => true]);
}

function login_code_page(): never
{
    start_session();
    $email = $_SESSION['login_email'] ?? '';
    if ($email === '') {
        redirect('/prisijungti');
    }
    $error = '';
    if (is_post()) {
        check_csrf();
        $code = preg_replace('/\D/', '', input('code'));
        $row = sql('SELECT * FROM login_codes WHERE email = ? AND expires_at > ? ORDER BY id DESC LIMIT 1', [$email, now()])->fetch();
        if (!$row || $row['attempts'] >= 5) {
            $error = 'Kodas nebegalioja. Paprašykite naujo kodo.';
        } elseif (!hash_equals($row['code_hash'], hash('sha256', $email.$code))) {
            sql('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            $error = 'Kodas neteisingas. Patikrinkite ir įveskite dar kartą.';
        } else {
            sql('DELETE FROM login_codes WHERE email = ?', [$email]);
            $user = sql('SELECT * FROM users WHERE email = ?', [$email])->fetch();
            if ($user && $user['blocked']) {
                $error = 'Ši paskyra užblokuota. Jei manote, kad tai klaida, susisiekite su mumis.';
            } else {
                if (!$user) {
                    $kind = $_SESSION['signup_kind'] ?? '';
                    if ($kind === '') {
                        $_SESSION['verified_email'] = $email;
                        redirect('/prisijungti/kas');
                    }
                    $user = ['id' => insert('users', ['kind' => $kind, 'email' => $email, 'created_at' => now()])];
                }
                finish_login((int) $user['id']);
            }
        }
    }
    page('Įveskite kodą', '<section class="wrap narrow"><h1>Patikrinkite el. paštą</h1><p class="lead">Išsiuntėme 6 skaitmenų kodą adresu <strong>'.e($email).'</strong>. Jei laiško nematote, patikrinkite brukalo („Spam“) aplanką.</p>'
        .'<form method="post" class="form" novalidate>'.csrf_field()
        .'<label>Kodas <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]*" required autofocus class="code">'.($error ? '<span class="err">'.e($error).'</span>' : '').'</label>'
        .'<button class="btn">Prisijungti</button></form><p><a href="/prisijungti">Siųsti kodą iš naujo arba pakeisti el. paštą</a></p></section>', ['noindex' => true]);
}

// A new user who came through the plain "Prisijungti" link picks a side once.
function choose_kind_page(): never
{
    start_session();
    $email = $_SESSION['verified_email'] ?? '';
    if ($email === '') {
        redirect('/prisijungti');
    }
    if (is_post()) {
        check_csrf();
        $kind = input('kind');
        if (in_array($kind, ['worker', 'manager'], true)) {
            $existing = sql('SELECT id FROM users WHERE email = ?', [$email])->fetchColumn();
            unset($_SESSION['verified_email']);
            finish_login($existing ? (int) $existing : insert('users', ['kind' => $kind, 'email' => $email, 'created_at' => now()]));
        }
    }
    page('Kas jūs esate?', '<section class="wrap narrow"><h1>Sveiki atvykę į GANG</h1><p class="lead">Pasirinkite, kaip naudositės GANG.</p>'
        .'<form method="post" class="pick">'.csrf_field()
        .'<button name="kind" value="worker" class="pick-card"><strong>Ieškau darbo</strong><span>Barmenas, padavėjas, virėjas ar kitas hospitality darbuotojas</span></button>'
        .'<button name="kind" value="manager" class="pick-card"><strong>Ieškau darbuotojų</strong><span>Baro, restorano, kavinės ar viešbučio vadovas</span></button>'
        .'</form></section>', ['noindex' => true]);
}

function finish_login(int $userId): never
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    unset($_SESSION['login_email'], $_SESSION['signup_kind']);
    sql('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $userId]);
    $user = sql('SELECT * FROM users WHERE id = ?', [$userId])->fetch();
    if (!profile_complete($user)) {
        redirect('/profilis');
    }
    $next = $_SESSION['after_login'] ?? '/mano';
    unset($_SESSION['after_login']);
    redirect(str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/mano');
}

function logout(): never
{
    start_session();
    check_csrf();
    $_SESSION = [];
    session_destroy();
    redirect('/');
}
