<?php

declare(strict_types=1);

// gang.lt/admin is reached only by typing the address: no link points to it, robots.txt does not
// name it, and it is never indexed. It works even while the site is locked. Signing in takes two
// steps: ADMIN_USER + the admin password, then a 6-digit code sent to config admin_email.
// The admin password is set in Nustatymai (stored as a hash in settings); until then it is the
// "GANG komandos prieiga" password (config preview_password).
const ADMIN_USER = 'gangster';
const ADMIN_PASSWORD_MIN = 12;

function setting(string $name): string
{
    return (string) (sql('SELECT value FROM settings WHERE name = ?', [$name])->fetchColumn() ?: '');
}

function set_setting(string $name, string $value): void
{
    sql('DELETE FROM settings WHERE name = ?', [$name]);
    insert('settings', ['name' => $name, 'value' => $value]);
}

// The stored password hash, or the team password while no admin password is set.
function admin_secret(): string
{
    return setting('admin_password') ?: (string) (config()['preview_password'] ?? '');
}

function admin_password_ok(string $password): bool
{
    $hash = setting('admin_password');
    if ($hash !== '') {
        return password_verify($password, $hash);
    }
    $team = (string) (config()['preview_password'] ?? '');
    return $team !== '' && hash_equals($team, $password);
}

function admin_token(): string
{
    $secret = admin_secret();
    return $secret === '' ? '' : hash_hmac('sha256', 'admin', $secret);
}

// Where the second step's code goes; login_codes keeps admin codes apart from members' codes.
function admin_email(): string
{
    $email = mb_strtolower(trim((string) (config()['admin_email'] ?? '')));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function admin_email_hint(string $email): string
{
    [$name, $domain] = explode('@', $email, 2);
    return mb_substr($name, 0, 2).str_repeat('•', max(3, mb_strlen($name) - 2)).'@'.$domain;
}

// True when this browser signed in at /admin. Changing the password signs everyone out.
function admin_signed_in(): bool
{
    static $on = null;
    if ($on === null) {
        $token = admin_token();
        $on = false;
        if ($token !== '' && isset($_COOKIE[session_name()])) {
            start_session();
            $on = hash_equals($token, (string) ($_SESSION['admin'] ?? ''));
        }
    }
    return $on;
}

// Step 1 checked: send the code. Returns an error to show, or '' when the code went out.
function admin_send_code(): string
{
    $email = admin_email();
    if ($email === '') {
        return 'Nenustatytas administratoriaus el. paštas (ADMIN_EMAIL), todėl kodo išsiųsti negalime.';
    }
    $key = 'admin:'.$email;
    $recent = (int) sql('SELECT COUNT(*) FROM login_codes WHERE email = ? AND created_at > ?', [$key, date('Y-m-d H:i:s', time() - 3600)])->fetchColumn();
    if ($recent >= 5) {
        return 'Viršytas bandymų skaičius. Bandykite dar kartą po valandos.';
    }
    $code = (string) random_int(100000, 999999);
    insert('login_codes', ['email' => $key, 'code_hash' => hash('sha256', $key.$code), 'expires_at' => date('Y-m-d H:i:s', time() + 600), 'created_at' => now()]);
    send_mail($email, "GANG administravimo kodas: $code", [
        'kicker' => 'Administravimas',
        'title' => 'Prisijungimo prie administravimo kodas',
        'lead' => 'kažkas ką tik įvedė teisingą administravimo slaptažodį. Norėdami prisijungti, įveskite šį kodą:',
        'code' => $code,
        'after' => 'Kodas galioja 10 minučių. Jei jungėtės ne jūs, kuo greičiau pakeiskite administravimo slaptažodį skiltyje „Nustatymai“.',
    ]);
    return '';
}

function admin_login_page(): never
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    start_session();
    $token = admin_token();
    $pending = $token !== '' && hash_equals($token, (string) ($_SESSION['admin_pending'] ?? ''))
        && (int) ($_SESSION['admin_pending_at'] ?? 0) > time() - 600;
    $error = '';
    if (is_post()) {
        check_csrf();
        usleep(800000);
        if (input('action') === 'restart') {
            unset($_SESSION['admin_pending'], $_SESSION['admin_pending_at']);
            redirect('/admin');
        }
        if (!$pending && isset($_POST['code'])) {
            $error = 'Kodas nebegalioja. Prisijunkite iš naujo.';
        } elseif ($pending) {
            $key = 'admin:'.admin_email();
            $code = preg_replace('/\D/', '', input('code'));
            $row = sql('SELECT * FROM login_codes WHERE email = ? AND expires_at > ? ORDER BY id DESC LIMIT 1', [$key, now()])->fetch();
            if (!$row || $row['attempts'] >= 5) {
                $error = 'Kodas nebegalioja. Prisijunkite iš naujo.';
            } elseif (!hash_equals($row['code_hash'], hash('sha256', $key.$code))) {
                sql('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
                $error = 'Kodas neteisingas. Patikrinkite ir įveskite dar kartą.';
            } else {
                sql('DELETE FROM login_codes WHERE email = ?', [$key]);
                unset($_SESSION['admin_pending'], $_SESSION['admin_pending_at']);
                session_regenerate_id(true);
                $_SESSION['admin'] = $token;
                redirect('/admin');
            }
        } else {
            $userOk = hash_equals(ADMIN_USER, mb_strtolower(trim((string) ($_POST['user'] ?? ''))));
            if ($token !== '' && $userOk && admin_password_ok((string) ($_POST['password'] ?? ''))) {
                $error = admin_send_code();
                if ($error === '') {
                    session_regenerate_id(true);
                    $_SESSION['admin_pending'] = $token;
                    $_SESSION['admin_pending_at'] = time();
                    redirect('/admin');
                }
            } else {
                $error = 'Neteisingas vartotojo vardas arba slaptažodis.';
            }
        }
        http_response_code(401);
    }
    $error = $error ? '<p class="err">'.e($error).'</p>' : '';
    $form = $pending
        ? '<p class="lead">Išsiuntėme 6 skaitmenų kodą adresu <strong>'.e(admin_email_hint(admin_email())).'</strong>. Kodas galioja 10 minučių.</p>'
            .'<form method="post" action="/admin" class="form">'.csrf_field()
            .'<label>Kodas <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]*" required autofocus class="code"></label>'
            .$error.'<button class="btn">Prisijungti</button></form>'
            .post_button('/admin', 'Siųsti kodą iš naujo', ['action' => 'restart'], 'btn small ghost')
        : '<form method="post" action="/admin" class="form">'.csrf_field()
            .'<label>Vartotojas <input name="user" autocomplete="username" autocapitalize="none" required></label>'
            .'<label>Slaptažodis <input type="password" name="password" autocomplete="current-password" required></label>'
            .$error.'<button class="btn">Toliau</button></form>';
    echo '<!doctype html><html lang="lt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
        .'<title>GANG</title><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/style.css?v=59"></head>'
        .'<body><main><section class="wrap narrow soon"><span class="logo">'.knives().'GANG<span>.lt</span></span>'
        .'<h1>Administravimas</h1>'.$form.'</section></main></body></html>';
    exit;
}

// Nustatymai → "Pakeisti slaptažodį". Signs out every other admin browser.
function admin_change_password(): never
{
    $back = '/admin?tab=settings';
    $new = (string) ($_POST['new_password'] ?? '');
    $team = (string) (config()['preview_password'] ?? '');
    $error = match (true) {
        !admin_password_ok((string) ($_POST['current_password'] ?? '')) => 'Dabartinis slaptažodis neteisingas.',
        mb_strlen($new) < ADMIN_PASSWORD_MIN => 'Naujas slaptažodis turi būti bent '.ADMIN_PASSWORD_MIN.' simbolių.',
        $new !== (string) ($_POST['new_password2'] ?? '') => 'Nauji slaptažodžiai nesutampa.',
        $team !== '' && hash_equals($team, $new) => 'Naujas slaptažodis turi skirtis nuo GANG komandos slaptažodžio.',
        default => '',
    };
    if ($error !== '') {
        flash($error);
        redirect($back);
    }
    set_setting('admin_password', password_hash($new, PASSWORD_DEFAULT));
    set_setting('admin_password_at', now());
    session_regenerate_id(true);
    $_SESSION['admin'] = admin_token();
    flash('Slaptažodis pakeistas. Kitose naršyklėse administravimas atjungtas.');
    redirect($back);
}

function admin_page(): never
{
    if (!admin_signed_in()) {
        admin_login_page();
    }
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    if (is_post()) {
        check_csrf();
        if (input('action') === 'logout') {
            unset($_SESSION['admin']);
            session_regenerate_id(true);
            redirect('/admin');
        }
        if (input('action') === 'admin_password') {
            admin_change_password();
        }
        $id = (int) input('id');
        $back = '/admin?'.http_build_query(array_intersect_key($_GET, ['tab' => 1, 'q' => 1, 'kind' => 1, 'status' => 1]));
        match (input('action')) {
            'block' => sql('UPDATE users SET blocked = 1 WHERE id = ?', [$id]),
            'unblock' => sql('UPDATE users SET blocked = 0 WHERE id = ?', [$id]),
            'verify' => flash(admin_verify_manager($id) ? 'Darbdavys patvirtintas, jam išsiųstas laiškas.' : 'Darbdavys jau patvirtintas.') ?: redirect('/admin?tab=user&id='.$id),
            'delete_user' => flash(admin_delete_user($id) ? 'Narys ir visi jo duomenys ištrinti.' : 'Narys nerastas.') ?: redirect($back),
            'delete_request' => flash(admin_delete_requests([$id]) ? 'Paieška ištrinta.' : 'Paieška nerasta.') ?: redirect($back),
            'delete_venue' => flash(admin_delete_venue($id) ? 'Darbovietė ir jos paieškos ištrintos.' : 'Darbovietė nerasta.') ?: redirect($back),
            'message' => admin_send_message($id),
            'test_mail' => admin_test_mail(),
            'delete_review' => sql('DELETE FROM reviews WHERE id = ?', [$id]),
            'close_request' => sql("UPDATE requests SET status = 'cancelled' WHERE id = ?", [$id]),
            'invite_waitlist' => flash(invite_waitlist().' laiškų išsiųsta.') ?: redirect('/admin?tab=waitlist'),
            default => null,
        };
        flash('Pakeitimas atliktas.');
        redirect($back);
    }
    $tab = (string) ($_GET['tab'] ?? 'stats');
    $count = fn (string $q, array $p = []) => (int) sql($q, $p)->fetchColumn();
    [$openWhere, $openParams] = open_where();
    $tabs = [
        'stats' => ['Rodikliai', null],
        'users' => ['Nariai', $count('SELECT COUNT(*) FROM users')],
        'venues' => ['Darbovietės', $count('SELECT COUNT(*) FROM venues')],
        'requests' => ['Darbo skelbimai', $count("SELECT COUNT(*) FROM requests r WHERE $openWhere", $openParams)],
        'reviews' => ['Įvertinimai', $count('SELECT COUNT(*) FROM reviews')],
        'waitlist' => ['Laukiantieji', $count('SELECT COUNT(*) FROM waitlist WHERE invited_at IS NULL')],
        'settings' => ['Nustatymai', null],
    ];
    $nav = '<nav class="admin-nav" aria-label="Administravimas">';
    foreach ($tabs as $k => [$label, $n]) {
        $active = $tab === $k || ($tab === 'user' && $k === 'users');
        $nav .= '<a href="/admin?tab='.$k.'"'.($active ? ' class="active" aria-current="page"' : '').'><span>'.$label.'</span>'.($n ? '<small>'.$n.'</small>' : '').'</a>';
    }
    $nav .= '</nav>';
    $body = match ($tab) {
        'requests' => admin_requests(),
        'users' => admin_users(),
        'user' => admin_user((int) ($_GET['id'] ?? 0)),
        'venues' => admin_venues(),
        'reviews' => admin_reviews(),
        'waitlist' => admin_waitlist(),
        'settings' => admin_settings(),
        default => admin_attention().admin_stats(),
    };
    $title = $tab === 'user' ? 'Narys' : ($tabs[$tab][0] ?? 'Rodikliai');
    $logout = post_button('/admin', 'Atsijungti', ['action' => 'logout'], 'btn small ghost');
    page('Administravimas', '<div class="admin-layout"><div class="admin-side">'.$nav.'<div class="admin-out">'.$logout.'</div></div>'
        .'<section class="admin-main"><h1>'.e($title).'</h1>'.$body.'</section></div>'.admin_table_script(), ['noindex' => true, 'shell' => 'admin']);
}

// What needs a look today, on top of Rodikliai. Nothing to show = a calm line.
function admin_attention(): string
{
    [$where, $params] = open_where();
    $items = [];
    $urgent = (int) sql("SELECT COUNT(*) FROM requests r WHERE $where AND r.kind = 'skubu' AND NOT EXISTS (SELECT 1 FROM responses s WHERE s.request_id = r.id)", $params)->fetchColumn();
    if ($urgent) {
        $items[] = '<a href="/admin?tab=requests">Skubios paieškos be kandidatų: '.$urgent.'</a>';
    }
    $pending = (int) sql("SELECT COUNT(*) FROM users u WHERE u.kind = 'manager' AND u.verified_at IS NULL AND u.blocked = 0 AND EXISTS (SELECT 1 FROM venues v WHERE v.manager_id = u.id)")->fetchColumn();
    if ($pending) {
        $items[] = '<a href="/admin?tab=users&amp;kind=manager">Darbdaviai laukia patvirtinimo: '.$pending.'</a>';
    }
    $shifts = (int) sql('SELECT COUNT(*) FROM shift_checks WHERE shift_date >= ? AND confirmed_at IS NULL AND (declined_at IS NOT NULL OR alerted_at IS NOT NULL)', [today()])->fetchColumn();
    if ($shifts) {
        $items[] = 'Nepatvirtintos pamainos: '.$shifts;
    }
    $waiting = (int) sql('SELECT COUNT(*) FROM waitlist WHERE invited_at IS NULL')->fetchColumn();
    if ($waiting) {
        $items[] = '<a href="/admin?tab=waitlist">Nepakviesti laukiantieji: '.$waiting.'</a>';
    }
    return $items
        ? '<div class="attention"><strong>Šiandien reikia dėmesio</strong><span>'.implode('</span><span>', $items).'</span></div>'
        : '<div class="attention calm"><strong>Šiandien viskas tvarkoje</strong><span>Skubių paieškų be kandidatų ir nepatvirtintų pamainų nėra.</span></div>';
}

// Daily counts for the last 14 days as a small line; $table must have created_at.
function admin_spark(string $table, string $where = '1 = 1'): string
{
    $from = date('Y-m-d', strtotime('-13 days'));
    $rows = sql("SELECT substr(created_at, 1, 10) AS d, COUNT(*) AS n FROM $table WHERE $where AND created_at >= ? GROUP BY d", [$from])->fetchAll();
    $byDay = array_column($rows, 'n', 'd');
    $values = [];
    for ($i = 13; $i >= 0; $i--) {
        $values[] = (int) ($byDay[date('Y-m-d', strtotime("-$i days"))] ?? 0);
    }
    $max = max(1, max($values));
    $points = [];
    foreach ($values as $i => $v) {
        $points[] = round($i * 100 / 13, 1).','.round(26 - $v / $max * 22, 1);
    }
    return '<svg class="spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true"><polyline points="'.implode(' ', $points).'"/></svg>';
}

// Phones: every data table reads as cards; each cell gets its column name.
function admin_table_script(): string
{
    return '<script>document.querySelectorAll(".admin-main table.data").forEach(function (t) { var h = []; t.querySelectorAll("tr:first-child th").forEach(function (th) { h.push(th.textContent.trim()); });'
        .' t.querySelectorAll("tr").forEach(function (tr) { tr.querySelectorAll("td").forEach(function (td, i) { if (h[i]) td.setAttribute("data-label", h[i]); }); }); });</script>';
}

// Search box above a list: free text plus an optional select (kind or status).
function admin_filter(string $tab, string $placeholder, string $selectName = '', array $choices = []): string
{
    $select = $selectName === '' ? '' : '<select name="'.$selectName.'"><option value="">Visi</option>'.options($choices, (string) ($_GET[$selectName] ?? '')).'</select>';
    return '<form method="get" action="/admin" class="admin-filter"><input type="hidden" name="tab" value="'.$tab.'">'
        .'<input type="search" name="q" value="'.e((string) ($_GET['q'] ?? '')).'" placeholder="'.e($placeholder).'">'.$select
        .'<button class="btn small">Rodyti</button></form>';
}

function admin_like(): string
{
    return '%'.trim((string) ($_GET['q'] ?? '')).'%';
}

// Everything a member left behind: profile, availability, responses, contracts with their reviews
// and arrival checks; for an employer also their venues and every request posted there.
function admin_delete_user(int $id): bool
{
    $u = sql('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    if (!$u) {
        return false;
    }
    db()->beginTransaction();
    foreach (sql('SELECT id FROM venues WHERE manager_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN) as $venueId) {
        admin_delete_venue((int) $venueId, false);
    }
    admin_delete_contracts(sql('SELECT id FROM contracts WHERE worker_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN));
    foreach (['availability' => 'user_id', 'worker_roles' => 'user_id', 'responses' => 'worker_id', 'invites' => 'worker_id'] as $table => $col) {
        sql("DELETE FROM $table WHERE $col = ?", [$id]);
    }
    sql('DELETE FROM team_links WHERE manager_id = ? OR worker_id = ?', [$id, $id]);
    sql('DELETE FROM invite_aliases WHERE user_id = ?', [$id]);
    sql('DELETE FROM consents WHERE user_id = ?', [$id]);
    sql('DELETE FROM team_mails WHERE manager_id = ? OR email = ?', [$id, $u['email']]);
    sql('DELETE FROM job_mails WHERE worker_id = ?', [$id]);
    sql('UPDATE users SET referred_by = NULL WHERE referred_by = ?', [$id]);
    sql('DELETE FROM login_codes WHERE email = ?', [$u['email']]);
    sql('DELETE FROM waitlist WHERE email = ?', [$u['email']]);
    sql('DELETE FROM users WHERE id = ?', [$id]);
    db()->commit();
    delete_photo($u['photo']);
    return true;
}

function admin_delete_venue(int $id, bool $own = true): bool
{
    if (!sql('SELECT 1 FROM venues WHERE id = ?', [$id])->fetchColumn()) {
        return false;
    }
    $own && db()->beginTransaction();
    admin_delete_requests(sql('SELECT id FROM requests WHERE venue_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN), false);
    admin_delete_contracts(sql('SELECT id FROM contracts WHERE venue_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN));
    sql('DELETE FROM venues WHERE id = ?', [$id]);
    $own && db()->commit();
    return true;
}

function admin_delete_requests(array $ids, bool $own = true): bool
{
    $ids = array_map('intval', $ids);
    if (!$ids) {
        return false;
    }
    $in = implode(',', $ids);
    if (!sql("SELECT COUNT(*) FROM requests WHERE id IN ($in)")->fetchColumn()) {
        return false;
    }
    $own && db()->beginTransaction();
    admin_delete_contracts(sql("SELECT id FROM contracts WHERE request_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
    sql("DELETE FROM responses WHERE request_id IN ($in)");
    sql("DELETE FROM invites WHERE request_id IN ($in)");
    sql("DELETE FROM requests WHERE id IN ($in)");
    $own && db()->commit();
    return true;
}

function admin_delete_contracts(array $ids): void
{
    $ids = array_map('intval', $ids);
    if ($ids) {
        $in = implode(',', $ids);
        sql("DELETE FROM reviews WHERE contract_id IN ($in)");
        sql("DELETE FROM shift_checks WHERE contract_id IN ($in)");
        sql("DELETE FROM messages WHERE contract_id IN ($in)");
        sql("DELETE FROM chat_reads WHERE contract_id IN ($in)");
        sql("DELETE FROM contracts WHERE id IN ($in)");
    }
}

// A sample email to any address, e.g. the one mail-tester.com gives, to see how inboxes score GANG's mail.
function admin_test_mail(): never
{
    $to = strtolower(trim(input('email')));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        flash('Įrašykite teisingą el. pašto adresą.');
        redirect('/admin?tab=settings');
    }
    $ok = send_mail($to, 'GANG bandomasis laiškas', [
        'title' => 'Bandomasis laiškas',
        'lead' => 'Tai bandomasis GANG laiškas. Juo tikriname, ar laiškai pasiekia gautuosius.',
        'cta' => ['Atidaryti GANG', url('/')],
        'footnote' => 'Laišką išsiuntė GANG administratorius iš gang.lt/admin.',
    ]);
    flash($ok ? 'Bandomasis laiškas išsiųstas: '.$to.'.' : 'Laiško išsiųsti nepavyko.');
    redirect('/admin?tab=settings');
}

// A personal email from the GANG team; replies go to hello@gang.lt.
function admin_send_message(int $id): never
{
    $u = sql('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    $subject = trim(input('subject'));
    $text = trim(input('text'));
    if (!$u || $subject === '' || $text === '') {
        flash('Įrašykite laiško temą ir tekstą.');
        redirect('/admin?tab=user&id='.$id);
    }
    $paras = preg_split('/\R\s*\R/u', $text);
    $from = (string) config()['mail_from'];
    $reply = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $from;
    flash(send_mail($u['email'], $subject, ['title' => $subject, 'lead' => $paras], $reply) ? 'Laiškas išsiųstas: '.$u['email'].'.' : 'Laiško išsiųsti nepavyko.');
    redirect('/admin?tab=user&id='.$id);
}

function admin_stats(): string
{
    $count = fn (string $q, array $p = []) => (int) sql($q, $p)->fetchColumn();
    $since = date('Y-m-d H:i:s', strtotime('-30 days'));
    $week = date('Y-m-d H:i:s', strtotime('-7 days'));
    $tiles = [
        ['Darbuotojai', $count("SELECT COUNT(*) FROM users WHERE kind = 'worker'"), $count("SELECT COUNT(*) FROM users WHERE kind = 'worker' AND created_at > ?", [$week]), admin_spark('users', "kind = 'worker'")],
        ['Darbovietės', $count('SELECT COUNT(*) FROM venues'), $count('SELECT COUNT(*) FROM venues WHERE created_at > ?', [$week]), admin_spark('venues')],
        ['Darbo skelbimai (30 d.)', $count('SELECT COUNT(*) FROM requests WHERE created_at > ?', [$since]), $count('SELECT COUNT(*) FROM requests WHERE created_at > ?', [$week]), admin_spark('requests')],
        ['Kontraktai (30 d.)', $count('SELECT COUNT(*) FROM contracts WHERE created_at > ?', [$since]), $count('SELECT COUNT(*) FROM contracts WHERE created_at > ?', [$week]), admin_spark('contracts')],
        ['Įvertinimai', $count('SELECT COUNT(*) FROM reviews'), $count('SELECT COUNT(*) FROM reviews WHERE created_at > ?', [$week]), admin_spark('reviews')],
    ];
    $html = '<div class="tiles kpis">';
    foreach ($tiles as [$label, $n, $delta, $spark]) {
        $html .= '<div class="tile"><span>'.$label.'</span><strong>'.$n.($delta ? ' <i>+'.$delta.' per 7 d.</i>' : '').'</strong>'.$spark.'</div>';
    }
    $html .= '</div>';
    // Fill rate and time to first contract, per kind: the numbers that later sell subscriptions.
    $rows = sql('SELECT r.kind, r.created_at, r.status, (SELECT MIN(c.created_at) FROM contracts c WHERE c.request_id = r.id) AS first_contract FROM requests r WHERE r.created_at > ?', [date('Y-m-d H:i:s', strtotime('-90 days'))])->fetchAll();
    $table = '<h2>Kaip greitai užpildoma (90 d.)</h2><table class="data"><tr><th>Tipas</th><th>Paieškų</th><th>Užpildyta</th><th>Mediana iki patvirtinimo</th></tr>';
    foreach (KINDS as $k => $label) {
        $mine = array_filter($rows, fn ($r) => $r['kind'] === $k);
        $times = [];
        foreach ($mine as $r) {
            if ($r['first_contract']) {
                $times[] = (strtotime($r['first_contract']) - strtotime($r['created_at'])) / 3600;
            }
        }
        sort($times);
        $median = $times ? $times[intdiv(count($times), 2)] : null;
        $medianText = $median === null ? '–' : ($median < 1 ? round($median * 60).' min.' : number_format($median, 1, ',', '').' val.');
        $filledPct = $mine ? round(count($times) / count($mine) * 100).' %' : '–';
        $table .= '<tr><td>'.e($label).'</td><td>'.count($mine).'</td><td>'.$filledPct.'</td><td>'.$medianText.'</td></tr>';
    }
    return $html.$table.'</table>';
}

const REQUEST_STATUSES = ['open' => 'Atvira', 'filled' => 'Užpildyta', 'cancelled' => 'Uždaryta', 'expired' => 'Pasibaigusi'];

function admin_requests(): string
{
    $where = ['(v.name LIKE ? OR r.description LIKE ?)'];
    $params = [admin_like(), admin_like()];
    if (isset(REQUEST_STATUSES[$_GET['status'] ?? ''])) {
        $where[] = 'r.status = ?';
        $params[] = $_GET['status'];
    }
    $rows = sql('SELECT r.*, v.name AS venue_name, v.city, v.manager_id, (SELECT COUNT(*) FROM responses s WHERE s.request_id = r.id) AS responses, (SELECT COUNT(*) FROM contracts c WHERE c.request_id = r.id) AS contracts
        FROM requests r JOIN venues v ON v.id = r.venue_id WHERE '.implode(' AND ', $where).' ORDER BY r.created_at DESC LIMIT 500', $params)->fetchAll();
    $back = '/admin?'.http_build_query(array_intersect_key($_GET, ['tab' => 1, 'q' => 1, 'status' => 1]));
    $html = admin_filter('requests', 'Darbovietė arba aprašymo žodis', 'status', REQUEST_STATUSES).'<p class="meta">Rasta: '.count($rows).'</p>'
        .'<div class="scroll"><table class="data"><tr><th>Sukurta</th><th>Darbovietė</th><th>Pareigos</th><th>Trukmė</th><th>Kada</th><th>Atlygis</th><th>Laiškų</th><th>Atsil.</th><th>Kontr.</th><th>Būsena</th><th></th></tr>';
    foreach ($rows as $r) {
        $close = $r['status'] === 'open' ? post_button($back, 'Uždaryti', ['action' => 'close_request', 'id' => $r['id']], 'btn small ghost', 'Ar tikrai norite uždaryti šią paiešką?') : '';
        $delete = post_button($back, 'Ištrinti', ['action' => 'delete_request', 'id' => $r['id']], 'btn small ghost', 'Ištrinti šią paiešką su visomis kandidatūromis, kontraktais ir įvertinimais? To atšaukti negalima.');
        $html .= '<tr><td>'.e(substr($r['created_at'], 0, 16)).'</td><td><a href="/admin?tab=user&amp;id='.(int) $r['manager_id'].'">'.e($r['venue_name']).'</a>, '.e(CITIES[$r['city']] ?? '').'</td><td><a href="/darbas/'.(int) $r['id'].'">'.e(ROLES[$r['role']] ?? $r['role']).'</a></td>'
            .'<td>'.e(KINDS[$r['kind']] ?? $r['kind']).'</td><td>'.e(period($r)).'</td><td>'.e($r['pay']).'</td><td>'.(int) $r['notified'].'</td><td>'.(int) $r['responses'].'</td><td>'.(int) $r['contracts'].'</td><td>'.e(REQUEST_STATUSES[$r['status']] ?? $r['status']).'</td>'
            .'<td class="actions">'.$close.$delete.'</td></tr>';
    }
    return $html.($rows ? '' : '<tr><td colspan="11">Paieškų nėra.</td></tr>').'</table></div>';
}

function admin_users(): string
{
    $where = ['(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR EXISTS (SELECT 1 FROM venues v WHERE v.manager_id = u.id AND v.name LIKE ?))'];
    $params = array_fill(0, 4, admin_like());
    $kinds = ['worker' => 'Darbuotojai', 'manager' => 'Darbdaviai'];
    if (isset($kinds[$_GET['kind'] ?? ''])) {
        $where[] = 'u.kind = ?';
        $params[] = $_GET['kind'];
    }
    $rows = sql('SELECT u.*, (SELECT COUNT(*) FROM contracts c WHERE c.worker_id = u.id OR c.venue_id IN (SELECT id FROM venues WHERE manager_id = u.id)) AS contracts,
        (SELECT GROUP_CONCAT(v.name) FROM venues v WHERE v.manager_id = u.id) AS venues
        FROM users u WHERE '.implode(' AND ', $where).' ORDER BY u.created_at DESC LIMIT 1000', $params)->fetchAll();
    $html = admin_filter('users', 'Vardas, el. paštas, telefonas ar darbovietė', 'kind', $kinds).'<p class="meta">Rasta: '.count($rows).'. Paspauskite vardą: matysite visus duomenis, galėsite parašyti laišką ar ištrinti narį.</p>'
        .'<div class="scroll"><table class="data"><tr><th>Registruotas</th><th>Tipas</th><th>Vardas</th><th>El. paštas</th><th>Telefonas</th><th>Miestai / pareigos / darbovietės</th><th>Kontr.</th><th>Paskutinį kartą</th></tr>';
    foreach ($rows as $r) {
        $what = $r['kind'] === 'worker' ? e(labels($r['cities'], CITIES)).'<br><small>'.e(labels($r['roles'], ROLES)).'</small>' : e((string) $r['venues']);
        $html .= '<tr'.($r['blocked'] ? ' class="blocked"' : '').'><td>'.e(substr($r['created_at'], 0, 10)).'</td><td>'.($r['kind'] === 'worker' ? 'Darbuotojas' : 'Darbdavys').'</td>'
            .'<td><a href="/admin?tab=user&amp;id='.(int) $r['id'].'">'.e($r['name'] !== '' ? $r['name'] : '(be vardo)').'</a>'.($r['blocked'] ? ' <small>užblokuotas</small>' : '').($r['kind'] === 'manager' && !$r['verified_at'] ? ' <small><strong>laukia patvirtinimo</strong></small>' : '').'</td><td>'.e($r['email']).'</td><td>'.e($r['phone']).'</td>'
            .'<td>'.$what.'</td><td>'.(int) $r['contracts'].'</td><td>'.e($r['last_login_at'] ? substr($r['last_login_at'], 0, 10) : '–').'</td></tr>';
    }
    return $html.($rows ? '' : '<tr><td colspan="8">Narių nerasta.</td></tr>').'</table></div>';
}

// The employer may publish searches and see the profiles of workers who apply. Mails them once.
function admin_verify_manager(int $id): bool
{
    $u = sql("SELECT * FROM users WHERE id = ? AND kind = 'manager' AND verified_at IS NULL", [$id])->fetch();
    if (!$u) {
        return false;
    }
    sql('UPDATE users SET verified_at = ? WHERE id = ?', [now(), $id]);
    send_mail($u['email'], 'Jūsų darbovietė patvirtinta', [
        'kicker' => 'GANG',
        'title' => 'Darbovietė patvirtinta',
        'lead' => 'patikrinome jūsų darbovietę. Dabar galite skelbti darbuotojų paieškas: pavadavimui, sezonui, renginiui ar nuolatiniam darbui.',
        'body' => 'Paskelbta paieška iš karto pasiekia tinkamus darbuotojus jūsų mieste, o atsiliepusių darbuotojų profilius matysite paieškos puslapyje.',
        'cta' => ['Paskelbti paiešką', url('/poreikis/naujas')],
    ]);
    return true;
}

// One member: every detail, their venues and requests, a message form and delete.
function admin_user(int $id): string
{
    $u = sql('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    if (!$u) {
        return '<p>Narys nerastas. <a href="/admin?tab=users">Visi nariai</a></p>';
    }
    $worker = $u['kind'] === 'worker';
    $back = '/admin?tab=user&id='.$id;
    $facts = [
        'Tipas' => $worker ? 'Darbuotojas' : 'Darbdavys',
        'El. paštas' => e($u['email']),
        'Telefonas' => e($u['phone'] ?: '–'),
        'Registruotas' => e(substr($u['created_at'], 0, 16)),
        'Paskutinį kartą prisijungė' => e($u['last_login_at'] ? substr($u['last_login_at'], 0, 16) : '–'),
        'Pranešimai el. paštu' => $u['notify'] ? 'Taip' : 'Ne',
        'Sutiko su taisyklėmis' => $u['terms_accepted_at'] ? e(substr($u['terms_accepted_at'], 0, 16).' (redakcija '.$u['terms_version'].')').($u['terms_version'] !== TERMS_VERSION ? ' – <strong>senesnė redakcija</strong>' : '') : 'Dar nesutiko',
        'Būsena' => $u['blocked'] ? 'Užblokuotas' : 'Aktyvus',
    ];
    if (!$worker) {
        $facts['Patvirtinimas'] = $u['verified_at'] ? 'Patvirtintas '.e(substr($u['verified_at'], 0, 10))
            : '<strong>Laukia patvirtinimo</strong> '.post_button($back, 'Patvirtinti darbdavį', ['action' => 'verify', 'id' => $id], 'btn small', 'Patvirtinti šį darbdavį? Jis galės skelbti paieškas ir matyti į jas atsiliepusių darbuotojų profilius.')
                .'<br><small>Prieš tvirtindami patikrinkite: ar darbovietė tikrai egzistuoja (Google Maps, Instagram, svetainė), ar darbdavio vardas ir telefonas atrodo tikri. Jei abejojate, paskambinkite.</small>';
    }
    if ($worker) {
        $facts += [
            'Miestai' => e(labels($u['cities'], CITIES) ?: '–'),
            'Pareigos' => e(labels($u['roles'], ROLES) ?: '–'),
            'Domina darbas' => e($u['durations'] !== '' ? labels($u['durations'], KINDS) : 'Visi'),
            'Kontraktų' => (string) sql('SELECT COUNT(*) FROM contracts WHERE worker_id = ?', [$id])->fetchColumn(),
            'Kandidatūrų' => (string) sql('SELECT COUNT(*) FROM responses WHERE worker_id = ?', [$id])->fetchColumn(),
            'Profilis' => '<a href="/darbuotojas/'.$id.'">Atidaryti viešą profilį</a>',
        ];
    }
    $html = '<p><a href="/admin?tab=users">← Visi nariai</a></p><h2>'.e($u['name'] !== '' ? $u['name'] : '(be vardo)').'</h2><dl class="admin-facts">';
    foreach ($facts as $k => $v) {
        $html .= '<dt>'.$k.'</dt><dd>'.$v.'</dd>';
    }
    $html .= '</dl>'.($u['about'] ? '<p>'.nl2br(e($u['about'])).'</p>' : '');
    if (!$worker) {
        $venues = sql('SELECT v.*, (SELECT COUNT(*) FROM requests r WHERE r.venue_id = v.id) AS requests FROM venues v WHERE v.manager_id = ? ORDER BY v.id', [$id])->fetchAll();
        $html .= '<h3>Darbovietės</h3>'.($venues ? '' : '<p class="meta">Darbovietės dar neužregistruotos.</p>');
        foreach ($venues as $v) {
            $html .= '<p><a href="/darboviete/'.(int) $v['id'].'">'.e($v['name']).'</a>, '.e(CITIES[$v['city']] ?? '').($v['address'] ? ', '.e($v['address']) : '').' · paieškų: '.(int) $v['requests'].'</p>';
        }
    }
    $html .= '<h3>Parašyti laišką</h3><form method="post" action="'.e($back).'" class="form admin-message">'.csrf_field()
        .'<input type="hidden" name="action" value="message"><input type="hidden" name="id" value="'.$id.'">'
        .'<label>Tema <input name="subject" maxlength="150" required></label>'
        .'<label>Tekstas <textarea name="text" rows="7" required></textarea></label>'
        .'<p class="meta">Laiškas bus išsiųstas GANG laiškų stiliumi: prasidės „Laba diena,“ ir baigsis GANG komandos parašu. Pastraipas atskirkite tuščia eilute. Atsakymai ateis į hello@gang.lt.</p>'
        .'<button class="btn small">Siųsti laišką</button></form>';
    $block = $u['blocked']
        ? post_button($back, 'Atblokuoti', ['action' => 'unblock', 'id' => $id], 'btn small ghost')
        : post_button($back, 'Blokuoti', ['action' => 'block', 'id' => $id], 'btn small ghost', 'Užblokuotas narys negalės prisijungti. Tęsti?');
    $delete = post_button('/admin?tab=users', 'Ištrinti narį', ['action' => 'delete_user', 'id' => $id], 'btn small danger',
        'Ištrinti '.($u['name'] ?: $u['email']).' ir visus jo duomenis'.($worker ? '' : ', darbovietes ir paieškas').'? To atšaukti negalima.');
    return $html.'<h3>Paskyra</h3><div class="account-actions">'.$block.$delete.'</div>';
}

function admin_venues(): string
{
    $rows = sql('SELECT v.*, u.name AS manager_name, u.email, (SELECT COUNT(*) FROM requests r WHERE r.venue_id = v.id) AS requests, (SELECT COUNT(*) FROM contracts c WHERE c.venue_id = v.id) AS contracts
        FROM venues v JOIN users u ON u.id = v.manager_id WHERE v.name LIKE ? OR v.address LIKE ? OR u.name LIKE ? OR u.email LIKE ? ORDER BY v.created_at DESC LIMIT 1000', array_fill(0, 4, admin_like()))->fetchAll();
    $back = '/admin?'.http_build_query(array_intersect_key($_GET, ['tab' => 1, 'q' => 1]));
    $html = admin_filter('venues', 'Pavadinimas, adresas ar darbdavys').'<p class="meta">Rasta: '.count($rows).'</p>'
        .'<div class="scroll"><table class="data"><tr><th>Užregistruota</th><th>Pavadinimas</th><th>Miestas, adresas</th><th>Darbdavys</th><th>Paieškų</th><th>Kontr.</th><th></th></tr>';
    foreach ($rows as $v) {
        $html .= '<tr><td>'.e(substr($v['created_at'], 0, 10)).'</td><td><a href="/darboviete/'.(int) $v['id'].'">'.e($v['name']).'</a></td><td>'.e(CITIES[$v['city']] ?? '').($v['address'] ? ', '.e($v['address']) : '').'</td>'
            .'<td><a href="/admin?tab=user&amp;id='.(int) $v['manager_id'].'">'.e($v['manager_name'] ?: $v['email']).'</a></td><td>'.(int) $v['requests'].'</td><td>'.(int) $v['contracts'].'</td>'
            .'<td>'.post_button($back, 'Ištrinti', ['action' => 'delete_venue', 'id' => $v['id']], 'btn small ghost', 'Ištrinti šią darbovietę su visomis jos paieškomis? To atšaukti negalima.').'</td></tr>';
    }
    return $html.($rows ? '' : '<tr><td colspan="7">Darboviečių nėra.</td></tr>').'</table></div>';
}

// What is switched on right now. These live in GitHub settings; Claude changes them on request.
function admin_settings(): string
{
    $c = config();
    $yes = fn (bool $on, string $onText, string $offText) => '<strong>'.($on ? $onText : $offText).'</strong>';
    $mail = !empty($c['resend_key']) ? 'Resend' : 'Hostinger (PHP mail)';
    $changed = setting('admin_password_at');
    $rows = [
        'Svetainė' => $yes(SITE_OPEN, 'Atverta visiems', 'Užrakinta').' <small>– '.(SITE_OPEN ? 'registracija atvira visiems.' : 'lankytojai be komandos slaptažodžio mato „Netrukus“.').'</small>',
        'Laiškų siuntėjas' => '<strong>'.e((string) $c['mail_from']).'</strong> <small>per '.$mail.'</small>',
        'Laiškų eilė' => '<strong>'.mail_queue_pending().'</strong> <small>laukia išsiuntimo'.((int) sql('SELECT COUNT(*) FROM mail_queue WHERE sent_at IS NULL AND attempts >= ?', [MAIL_QUEUE_TRIES])->fetchColumn() ? ', '.(int) sql('SELECT COUNT(*) FROM mail_queue WHERE sent_at IS NULL AND attempts >= ?', [MAIL_QUEUE_TRIES])->fetchColumn().' nepavyko išsiųsti' : '').' · per 7 d. išsiųsta: '.(int) sql('SELECT COUNT(*) FROM mail_queue WHERE sent_at IS NOT NULL')->fetchColumn().'</small>',
        'SMS pranešimai' => $yes(($c['sms_provider'] ?? '') === 'twilio', 'Įjungti (Twilio)', 'Išjungti').' <small>– kol kas siunčiami tik el. laiškai.</small>',
        'Admin prisijungimas' => '<strong>'.ADMIN_USER.'</strong> <small>+ '.($changed ? 'administravimo slaptažodis (pakeistas '.e(substr($changed, 0, 10)).')' : 'GANG komandos slaptažodis – pakeiskite žemiau').' + kodas el. paštu'.(admin_email() ? ' '.e(admin_email_hint(admin_email())) : '').'</small>',
    ];
    $html = '<dl class="admin-facts">';
    foreach ($rows as $k => $v) {
        $html .= '<dt>'.$k.'</dt><dd>'.$v.'</dd>';
    }
    $test = (int) sql('SELECT COUNT(*) FROM users')->fetchColumn();
    $html .= '</dl><h2>Administravimo slaptažodis</h2><p class="meta">'.($changed ? 'Pakeitus slaptažodį, administravimas atjungiamas visose kitose naršyklėse.' : 'Dabar administravimui naudojamas GANG komandos slaptažodis, kurį žino visi, matantys svetainę iki paleidimo. Susikurkite atskirą.').' Bent '.ADMIN_PASSWORD_MIN.' simbolių.</p>'
        .'<form method="post" action="/admin?tab=settings" class="form">'.csrf_field().'<input type="hidden" name="action" value="admin_password">'
        .'<input type="text" name="user" value="'.ADMIN_USER.'" autocomplete="username" hidden>'
        .'<label>Dabartinis slaptažodis <input type="password" name="current_password" autocomplete="current-password" required></label>'
        .'<label>Naujas slaptažodis <input type="password" name="new_password" autocomplete="new-password" minlength="'.ADMIN_PASSWORD_MIN.'" required></label>'
        .'<label>Pakartokite naują slaptažodį <input type="password" name="new_password2" autocomplete="new-password" minlength="'.ADMIN_PASSWORD_MIN.'" required></label>'
        .'<button class="btn small">Pakeisti slaptažodį</button></form>'
        .'<h2>Bandomasis laiškas</h2><p class="meta">Atidarykite mail-tester.com, nukopijuokite ten rodomą adresą, išsiųskite į jį laišką ir grįžę į mail-tester.com pamatysite įvertinimą (tikslas – 9/10 ar daugiau). Galite išsiųsti ir sau, į Gmail ar Outlook.</p>'
        .'<form method="post" action="/admin?tab=settings" class="test-mail">'.csrf_field().'<input type="hidden" name="action" value="test_mail">'
        .'<input type="email" name="email" required placeholder="adresas@pavyzdys.lt" aria-label="El. paštas"> <button class="btn small">Siųsti</button></form>';
    return $html.'<p class="meta">Šiuos nustatymus pakeičia Claude, kai paprašote gijoje. Narių dabar: '.$test.'.</p>';
}

function admin_reviews(): string
{
    $rows = sql('SELECT rv.*, w.name AS worker_name, v.name AS venue_name FROM reviews rv JOIN contracts c ON c.id = rv.contract_id JOIN users w ON w.id = c.worker_id JOIN venues v ON v.id = c.venue_id ORDER BY rv.created_at DESC LIMIT 300')->fetchAll();
    $html = '<p class="meta">Čia matomi visi įvertinimai, įskaitant dar nepaskelbtus. Trinkite tik įžeidžiančius, melagingus ar asmeninę informaciją atskleidžiančius įvertinimus.</p><table class="data"><tr><th>Data</th><th>Kas ką</th><th>Balas</th><th>Komentaras</th><th></th></tr>';
    foreach ($rows as $r) {
        $who = $r['author'] === 'manager' ? e($r['venue_name']).' → '.e($r['worker_name']) : e($r['worker_name']).' → '.e($r['venue_name']);
        $html .= '<tr><td>'.e(substr($r['created_at'], 0, 10)).'</td><td>'.$who.'</td><td>'.(int) $r['rating'].'</td><td>'.nl2br(e($r['text'])).'</td><td>'
            .post_button('/admin?tab=reviews', 'Ištrinti', ['action' => 'delete_review', 'id' => $r['id']], 'btn small ghost', 'Ar tikrai norite ištrinti šį įvertinimą?').'</td></tr>';
    }
    return '<div class="scroll">'.$html.'</table></div>';
}

function admin_waitlist(): string
{
    $rows = sql('SELECT w.*, u.id AS user_id FROM waitlist w LEFT JOIN users u ON u.email = w.email ORDER BY w.created_at DESC LIMIT 2000')->fetchAll();
    $profiles = (int) sql("SELECT COUNT(*) FROM users WHERE kind = 'worker' AND blocked = 0 AND roles <> '' AND name <> ''")->fetchColumn();
    $public = SITE_OPEN;
    [$invites, $launchMails] = waitlist_pending();
    $pending = $invites + ($public ? $launchMails : 0);
    $label = $public ? 'Siųsti paleidimo laiškus ('.$pending.')' : 'Pakviesti užsiregistruoti ('.$pending.')';
    $confirm = $public
        ? 'Pranešti visiems užsiregistravusiems, kad GANG jau veikia?'
        : 'Išsiųsti kvietimą užsiregistruoti visiems sąraše esantiems, kurie paskyros dar nesusikūrė ir kvietimo negavo?';
    $invite = $pending ? post_button('/admin?tab=waitlist', $label, ['action' => 'invite_waitlist', 'id' => 0], 'btn small', $confirm) : '<p class="meta">Visi laiškai išsiųsti.</p>';
    $note = $public
        ? '<p class="meta">Paleidimo laiškai: darbuotojams – kvietimas peržiūrėti pasiūlymus, darbdaviams – kvietimas paskelbti pirmą paiešką; dar neužsiregistravusiems iš sąrašo – kvietimas registruotis. Kiekvienas laiškas siunčiamas tik kartą.</p>'
        : '<p class="meta">Iki paleidimo darbuotojai jau gali susikurti profilį, o darbdaviai – užregistruoti darbovietę. Senojo sąrašo nariams galite išsiųsti kvietimą užsiregistruoti. Kai svetainė bus atverta visiems, čia galėsite išsiųsti paleidimo laiškus ('.$launchMails.').</p>';
    $venues = (int) sql('SELECT COUNT(DISTINCT manager_id) FROM venues')->fetchColumn();
    $html = '<div class="tiles"><div class="tile"><span>Darbuotojų profilių</span><strong>'.$profiles.'</strong></div><div class="tile"><span>Darbdavių su darboviete</span><strong>'.$venues.'</strong></div>'
        .'<div class="tile"><span>Sąraše be paskyros</span><strong>'.count(array_filter($rows, fn ($r) => !$r['user_id'])).'</strong></div></div>'.$note.$invite
        .'<table class="data"><tr><th>Data</th><th>El. paštas</th><th>Ieško</th><th>Miestas</th><th>Pakviesta</th><th>Paskyra</th></tr>';
    foreach ($rows as $r) {
        $html .= '<tr><td>'.e(substr($r['created_at'], 0, 16)).'</td><td>'.e($r['email']).'</td><td>'.($r['kind'] === 'worker' ? 'Darbo' : 'Darbuotojų').'</td>'
            .'<td>'.e(CITIES[$r['city']] ?? '–').'</td><td>'.e($r['invited_at'] ? substr($r['invited_at'], 0, 10) : '–').'</td><td>'.($r['user_id'] ? 'Yra' : '–').'</td></tr>';
    }
    return '<div class="scroll">'.$html.'</table></div>';
}

// [invitations to people on the old email-only list, launch emails to accounts made before launch] still to send.
function waitlist_pending(): array
{
    return [
        (int) sql('SELECT COUNT(*) FROM waitlist w WHERE w.invited_at IS NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.email = w.email)')->fetchColumn(),
        (int) sql('SELECT COUNT(*) FROM users WHERE blocked = 0 AND launch_mail_at IS NULL')->fetchColumn(),
    ];
}

// People on the old email-only list are invited to sign up (before launch: to prepare their profile or venue).
// Launch day (public): everyone who signed up early hears that GANG is live.
// Every email is marked as sent first, so a second click never repeats it.
function invite_waitlist(): int
{
    $public = SITE_OPEN;
    $sent = 0;
    foreach (sql('SELECT w.* FROM waitlist w WHERE w.invited_at IS NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.email = w.email) ORDER BY w.id LIMIT 500')->fetchAll() as $r) {
        sql('UPDATE waitlist SET invited_at = ? WHERE id = ?', [now(), $r['id']]);
        $worker = $r['kind'] === 'worker';
        $intro = $public ? 'GANG jau veikia. Kaip ir žadėjome, kviečiame jus vienus pirmųjų ' : 'dėkojame, kad užsiregistravote GANG laukiančiųjų sąraše. Kviečiame jus jau dabar ';
        $mail = [
            'kicker' => $public ? 'GANG jau veikia' : 'Kvietimas',
            'title' => $worker ? 'Susikurkite profilį' : 'Užregistruokite darbovietę',
            'lead' => $intro.($worker
                ? ($public
                    ? 'susikurti profilį ir gauti darbo pasiūlymus iš restoranų, kavinių, barų ir klubų.'
                    : 'susikurti profilį: nurodyti pareigas, patirtį ir miestus, kuriuose galite dirbti.')
                : ($public
                    ? 'užregistruoti savo barą, restoraną ar kavinę ir rasti patikimų žmonių savo komandai pavadavimui, sezonui ar nuolatiniam darbui.'
                    : 'užregistruoti savo barą, restoraną ar kavinę.')),
            'body' => $public ? [] : ($worker
                ? ['Paleidimo dieną jūsų profilis jau bus matomas darbdaviams.',
                    'Nuo starto dienos gausite jums tinkančius darbo pasiūlymus.']
                : ['Nuo paleidimo dienos galėsite skelbti darbuotojų paieškas, o tinkami darbuotojai jas gaus iš karto.']),
            'cta' => [$worker ? 'Susikurti profilį' : 'Užregistruoti darbovietę', url('/prisijungti?kaip='.($worker ? 'darbuotojas' : 'vadovas'))],
            'after' => 'Registracija trunka kelias minutes.',
            'footnote' => 'Šį laišką gavote, nes užsiregistravote GANG laukiančiųjų sąraše. Jei daugiau laiškų gauti nenorite, atsakykite į šį laišką.',
        ];
        $subject = $public ? 'GANG jau veikia: kvietimas registruotis' : ($worker ? 'GANG: susikurkite profilį jau dabar' : 'GANG: užregistruokite darbovietę jau dabar');
        if (send_mail($r['email'], $subject, $mail)) {
            $sent++;
        }
    }
    if (!$public) {
        return $sent;
    }
    foreach (sql('SELECT * FROM users WHERE blocked = 0 AND launch_mail_at IS NULL ORDER BY id LIMIT 2000')->fetchAll() as $u) {
        sql('UPDATE users SET launch_mail_at = ? WHERE id = ?', [now(), $u['id']]);
        $ready = profile_complete($u) && ($u['kind'] === 'worker' || sql('SELECT id FROM venues WHERE manager_id = ?', [$u['id']])->fetchColumn());
        if (!$ready) {
            $mail = ['title' => 'Užbaikite registraciją', 'lead' => 'GANG jau veikia. Jūsų registracija dar neužbaigta. Užbaikite ją: tai trunka kelias minutes.',
                'cta' => ['Užbaigti registraciją', url('/profilis')]];
        } elseif ($u['kind'] === 'worker') {
            $mail = ['title' => 'Jūsų profilis matomas', 'lead' => 'GANG jau veikia. Jūsų profilis dabar matomas restoranams, kavinėms, barams ir klubams jūsų miestuose.',
                'body' => 'Jums tinkančius darbo pasiūlymus gausite el. paštu, o patvirtinti darbai automatiškai atsiras jūsų GANG kalendoriuje.',
                'cta' => ['Peržiūrėti pasiūlymus', url('/mano#pasiulymai')]];
        } else {
            $mail = ['title' => 'Paskelbkite pirmą paiešką', 'lead' => 'GANG jau veikia. Nuo šiandien galite skelbti darbuotojų paieškas: pavadavimui, sezonui ar nuolatiniam darbui.',
                'body' => 'Paskelbta paieška iš karto pasiekia tinkamus darbuotojus jūsų mieste.',
                'cta' => ['Paskelbti paiešką', url('/poreikis/naujas')]];
        }
        if (send_mail($u['email'], 'GANG jau veikia', $mail + ['kicker' => 'GANG jau veikia', 'footnote' => 'Šį laišką gavote, nes užsiregistravote GANG.'])) {
            $sent++;
        }
    }
    return $sent;
}
