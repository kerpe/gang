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
            // "Atsijungti visuose įrenginiuose" raises session_epoch: older sessions stop working.
            if ($user && (int) ($_SESSION['epoch'] ?? 0) !== (int) $user['session_epoch']) {
                $user = null;
                $_SESSION = [];
            }
        }
    }
    return $user;
}

// Admin rights come only from the /admin login (see admin.php), never from a member account.
function is_admin(?array $user = null): bool
{
    return admin_signed_in();
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
    if (needs_consent($user)) {
        if (!is_post()) {
            $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/mano';
        }
        redirect('/sutikimas');
    }
    return $user;
}

function profile_complete(array $u): bool
{
    return $u['name'] !== '' && $u['phone'] !== '' && ($u['kind'] === 'manager' || ($u['roles'] !== '' && $u['cities'] !== '' && (int) $u['experience'] > 0));
}

// One network (IP address) may ask for this many sign-in codes, to any addresses, so the form
// cannot be used to send GANG mail to strangers. Mobile networks share addresses, so the limits are loose.
const LOGIN_CODES_PER_IP_HOUR = 10;
const LOGIN_CODES_PER_IP_DAY = 30;

// How many of the newest unexpired codes for one address still work. Asking for a code again
// (or a double click on "Siųsti kodą") sends a second email, and the person often types the code
// from the first one, so it has to keep working. A wrong guess counts against every live code,
// so there are still at most 5 guesses before a new code is needed.
const LOGIN_CODES_LIVE = 3;
const LOGIN_CODE_TRIES = 5;

// The code input: digits only, so a pasted code with spaces or a line break still fits.
function login_code_input(): string
{
    return '<input name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus class="code"'
        .' oninput="this.value=this.value.replace(/\\D/g,\'\').slice(0,6)">';
}

// Checks a typed code against $key's live codes: 'ok' (the codes are then used up), 'wrong' or 'expired'.
function check_login_code(string $key, string $typed): string
{
    $code = preg_replace('/\D/', '', $typed);
    $rows = sql('SELECT * FROM login_codes WHERE email = ? AND expires_at > ? AND attempts < ? ORDER BY id DESC LIMIT '.LOGIN_CODES_LIVE,
        [$key, now(), LOGIN_CODE_TRIES])->fetchAll();
    if (!$rows) {
        return 'expired';
    }
    foreach ($rows as $row) {
        if (strlen($code) === 6 && hash_equals($row['code_hash'], hash('sha256', $key.$code))) {
            sql('DELETE FROM login_codes WHERE email = ?', [$key]);
            return 'ok';
        }
    }
    sql('UPDATE login_codes SET attempts = attempts + 1 WHERE email = ? AND expires_at > ?', [$key, now()]);
    return 'wrong';
}

function login_codes_from_ip(string $ipHash, int $seconds): int
{
    return (int) sql('SELECT COUNT(*) FROM login_codes WHERE ip_hash = ? AND created_at > ?', [$ipHash, date('Y-m-d H:i:s', time() - $seconds)])->fetchColumn();
}

function login_page(): never
{
    start_session();
    // Every visit to the page sets the mode from the link alone: ?kaip=… registers with a role,
    // ?registruotis registers without one, and the plain "Prisijungti" link is a sign-in, even if an
    // earlier visit in this session went through a registration link. The POST keeps the mode it was shown in.
    if (!is_post()) {
        $kaip = $_GET['kaip'] ?? '';
        $_SESSION['signup_kind'] = match ($kaip) {
            'vadovas' => 'manager',
            'darbuotojas' => 'worker',
            default => '',
        };
        // "Registruotis" in the menu has no role yet; remember that the visitor meant to sign up,
        // so an existing member can be told they already have an account.
        $_SESSION['signup_intent'] = isset($_GET['registruotis']) || $_SESSION['signup_kind'] !== '';
    }
    if (current_user()) {
        redirect('/mano');
    }
    $kind = $_SESSION['signup_kind'] ?? '';
    $signup = $kind !== '' || !empty($_SESSION['signup_intent']);
    $error = '';
    $termsError = '';
    $email = '';
    if (is_post()) {
        check_csrf();
        $email = mb_strtolower(input('email'));
        if (input('prisijungti') === '1') {
            // "Prisijungti" on the "you already have an account" notice: from here on this is a sign-in.
            $_SESSION['signup_kind'] = '';
            $_SESSION['signup_intent'] = false;
            $kind = '';
            $signup = false;
        }
        $member = $signup && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? sql('SELECT kind, blocked FROM users WHERE email = ?', [$email])->fetch()
            : false;
        if ($member && !$member['blocked']) {
            // "Registruotis" with an address that already has an account: no new account and no code.
            // Say so plainly and offer to sign in (clarity for the member matters more here than hiding
            // which addresses are registered).
            already_member_page($email, $member['kind'], $kind);
        }
        if ($kind !== '' && !consent_given()) {
            $termsError = CONSENT_ERROR;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Patikrinkite el. pašto adresą.';
        } elseif ($termsError === '') {
            $recent = (int) sql('SELECT COUNT(*) FROM login_codes WHERE email = ? AND created_at > ?', [$email, date('Y-m-d H:i:s', time() - 3600)])->fetchColumn();
            $ipHash = hash_hmac('sha256', client_ip(), mail_secret());
            if ($recent >= 5) {
                $error = 'Viršytas bandymų skaičius. Bandykite dar kartą po valandos.';
            } elseif (login_codes_from_ip($ipHash, 3600) >= LOGIN_CODES_PER_IP_HOUR || login_codes_from_ip($ipHash, 86400) >= LOGIN_CODES_PER_IP_DAY) {
                $error = 'Iš šio tinklo per trumpą laiką paprašyta per daug kodų. Bandykite vėliau arba parašykite '.OPERATOR_EMAIL.'.';
            } else {
                $code = (string) random_int(100000, 999999);
                insert('login_codes', ['email' => $email, 'code_hash' => hash('sha256', $email.$code), 'expires_at' => date('Y-m-d H:i:s', time() + 900), 'created_at' => now(), 'ip_hash' => $ipHash]);
                send_mail($email, "GANG prisijungimo kodas: $code", [
                    'kicker' => 'Prisijungimas',
                    'title' => 'Jūsų prisijungimo kodas',
                    'lead' => 'norėdami prisijungti prie GANG.lt, įveskite šį kodą:',
                    'code' => $code,
                    'after' => 'Kodas galioja 15 minučių. Jei kodo neprašėte, šį laišką galite ištrinti.',
                ]);
                $_SESSION['login_email'] = $email;
                if ($kind !== '') {
                    $_SESSION['terms_ok'] = TERMS_VERSION;
                }
                redirect('/prisijungti/kodas');
            }
        }
    }
    $title = match ($kind) {
        'worker' => 'Registracija darbuotojams',
        'manager' => 'Registracija darbdaviams',
        default => $signup ? 'Registracija' : 'Prisijungimas',
    };
    $lead = match ($kind) {
        'worker' => 'Įveskite el. pašto adresą ir atsiųsime prisijungimo kodą. Tada užpildysite trumpą profilį ir galėsite gauti darbo pasiūlymus iš restoranų, kavinių, barų ir klubų.',
        'manager' => 'Įveskite el. pašto adresą ir atsiųsime prisijungimo kodą. Tada užregistruosite savo barą, restoraną ar kavinę ir galėsite skelbti darbuotojų paieškas.',
        default => $signup
            ? 'Slaptažodžio nereikia: įveskite el. pašto adresą ir atsiųsime 6 skaitmenų kodą. Tada pasirinksite, ar ieškote darbo, ar darbuotojų.'
            : 'Slaptažodžio nereikia: įveskite el. pašto adresą ir atsiųsime 6 skaitmenų prisijungimo kodą.',
    };
    page($title, '<section class="wrap narrow"><h1>'.$title.'</h1><p class="lead">'.$lead.'</p>'
        .'<form method="post" class="form" novalidate'.ONE_SUBMIT.'>'.csrf_field()
        .'<label>El. paštas <input type="email" name="email" value="'.e($email).'" autocomplete="email" inputmode="email" required autofocus>'.($error ? '<span class="err">'.e($error).'</span>' : '').'</label>'
        .($kind !== '' ? consent_field($termsError) : '')
        .'<button class="btn">Siųsti kodą</button></form></section>', ['noindex' => true]);
}

// Shown instead of sending a code when "Registruotis" is used with an address that already has an account.
function already_member_page(string $email, string $memberKind, string $wantedKind): never
{
    $role = $memberKind === 'manager' ? 'darbdavio' : 'darbuotojo';
    $other = $wantedKind !== '' && $wantedKind !== $memberKind
        ? '<p>Viena paskyra turi vieną vaidmenį. Jei norite '.($wantedKind === 'manager' ? 'darbdavio' : 'darbuotojo').' paskyros, registruokitės kitu el. pašto adresu.</p>'
        : '';
    page('Paskyra jau yra', '<section class="wrap narrow"><h1>Jūs jau turite GANG paskyrą</h1>'
        .'<p class="lead">Adresu <strong>'.e($email).'</strong> jau užregistruota '.$role.' paskyra, todėl naujos kurti nereikia. Prisijunkite prie esamos paskyros.</p>'
        .$other
        .'<form method="post" action="/prisijungti" class="form" novalidate'.ONE_SUBMIT.'>'.csrf_field()
        .'<input type="hidden" name="email" value="'.e($email).'"><input type="hidden" name="prisijungti" value="1">'
        .'<button class="btn">Prisijungti</button></form>'
        .'<p><a href="'.login_again_url().'">Registruotis kitu el. pašto adresu</a></p></section>', ['noindex' => true]);
}

// Back to the email form in the same mode the visitor came in with (sign-in or registration).
function login_again_url(): string
{
    $kind = $_SESSION['signup_kind'] ?? '';
    return match (true) {
        $kind === 'worker' => '/prisijungti?kaip=darbuotojas',
        $kind === 'manager' => '/prisijungti?kaip=vadovas',
        !empty($_SESSION['signup_intent']) => '/prisijungti?registruotis',
        default => '/prisijungti',
    };
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
        $check = check_login_code($email, input('code'));
        if ($check === 'expired') {
            $error = 'Kodas nebegalioja. Paprašykite naujo kodo.';
        } elseif ($check === 'wrong') {
            $error = 'Kodas neteisingas. Patikrinkite ir įveskite dar kartą.';
        } else {
            $user = sql('SELECT * FROM users WHERE email = ?', [$email])->fetch();
            if ($user && $user['blocked']) {
                $error = 'Ši paskyra užblokuota. Jei manote, kad tai klaida, susisiekite su mumis.';
            } else {
                $kind = $_SESSION['signup_kind'] ?? '';
                if ($user && ($kind !== '' || !empty($_SESSION['signup_intent']))) {
                    // Came through "Registruotis" but already a member: sign in and say so.
                    flash($kind === '' || $user['kind'] === $kind
                        ? 'Šiuo el. paštu jau turite GANG paskyrą, todėl prisijungėte prie jos.'
                        : 'Šiuo el. paštu jau turite '.($user['kind'] === 'manager' ? 'darbdavio' : 'darbuotojo').' paskyrą, todėl prisijungėte prie jos. Viena paskyra turi vieną vaidmenį: '.($kind === 'manager' ? 'darbdavio' : 'darbuotojo').' paskyrai registruokitės kitu el. pašto adresu.');
                }
                if (!$user) {
                    if ($kind === '') {
                        $_SESSION['verified_email'] = $email;
                        redirect('/prisijungti/kas');
                    }
                    $user = ['id' => create_user($kind, $email)];
                }
                finish_login((int) $user['id']);
            }
        }
    }
    page('Įveskite kodą', '<section class="wrap narrow"><h1>Patikrinkite el. paštą</h1><p class="lead">Išsiuntėme 6 skaitmenų kodą adresu <strong>'.e($email).'</strong>. Jei laiško nematote, patikrinkite brukalo („Spam“) aplanką.</p>'
        .'<form method="post" class="form" novalidate'.ONE_SUBMIT.'>'.csrf_field()
        .'<label>Kodas '.login_code_input().($error ? '<span class="err">'.e($error).'</span>' : '').'</label>'
        .'<button class="btn">Prisijungti</button></form><p><a href="'.login_again_url().'">Siųsti kodą iš naujo arba pakeisti el. paštą</a></p></section>', ['noindex' => true]);
}

// A new user who came through the plain "Prisijungti" link picks a side once.
function choose_kind_page(): never
{
    start_session();
    $email = $_SESSION['verified_email'] ?? '';
    if ($email === '') {
        redirect('/prisijungti');
    }
    $error = '';
    if (is_post()) {
        check_csrf();
        $kind = input('kind');
        if (!consent_given()) {
            $error = CONSENT_ERROR;
        } elseif (in_array($kind, ['worker', 'manager'], true)) {
            $_SESSION['terms_ok'] = TERMS_VERSION;
            $existing = sql('SELECT id FROM users WHERE email = ?', [$email])->fetchColumn();
            unset($_SESSION['verified_email']);
            finish_login($existing ? (int) $existing : create_user($kind, $email));
        }
    }
    page('Kas jūs esate?', '<section class="wrap narrow"><h1>Sveiki atvykę į GANG</h1><p class="lead">Pasirinkite, ko ieškote GANG – vėliau pakeisti nebegalėsite.</p>'
        .'<form method="post" class="pick">'.csrf_field()
        .'<div class="pick-consent">'.consent_field($error).'</div>'
        .'<button name="kind" value="worker" class="pick-card"><strong>Ieškau darbo</strong><span>Barmenas, padavėjas, virėjas ar kitas hospitality darbuotojas</span></button>'
        .'<button name="kind" value="manager" class="pick-card"><strong>Ieškau darbuotojų</strong><span>Restorano, kavinės, baro, klubo ar viešbučio vadovas</span></button>'
        .'</form></section>', ['noindex' => true]);
}

// Accounts made after launch never need the launch-day email.
function create_user(string $kind, string $email): int
{
    return insert('users', ['kind' => $kind, 'email' => $email, 'created_at' => now(), 'launch_mail_at' => now()]);
}

function finish_login(int $userId): never
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    $_SESSION['epoch'] = (int) sql('SELECT session_epoch FROM users WHERE id = ?', [$userId])->fetchColumn();
    unset($_SESSION['login_email'], $_SESSION['signup_kind'], $_SESSION['signup_intent']);
    $new = !sql('SELECT last_login_at FROM users WHERE id = ?', [$userId])->fetchColumn();
    sql('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $userId]);
    $user = sql('SELECT * FROM users WHERE id = ?', [$userId])->fetch();
    // Ticked on the sign-up form before the code was sent; stored once the email is verified.
    if (($_SESSION['terms_ok'] ?? '') === TERMS_VERSION && needs_consent($user)) {
        record_consent($userId);
    }
    unset($_SESSION['terms_ok']);
    apply_invite($userId, $new);
    $user = sql('SELECT * FROM users WHERE id = ?', [$userId])->fetch();
    if (needs_consent($user)) {
        redirect('/sutikimas');
    }
    if (!profile_complete($user)) {
        redirect('/profilis');
    }
    $next = $_SESSION['after_login'] ?? '/mano';
    unset($_SESSION['after_login']);
    redirect(str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/mano');
}

// Signs the member out on every device, this one included.
function logout_everywhere(): never
{
    $u = require_user();
    check_csrf();
    sql('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?', [$u['id']]);
    $_SESSION = [];
    session_regenerate_id(true);
    flash('Atsijungėte visuose įrenginiuose. Prisijunkite iš naujo.');
    redirect('/prisijungti');
}

function logout(): never
{
    start_session();
    check_csrf();
    $_SESSION = [];
    session_destroy();
    redirect('/');
}
