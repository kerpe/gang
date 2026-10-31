<?php

declare(strict_types=1);

// Until launch everything except the home, how-it-works and privacy pages sits behind
// one shared preview password; every other page shows the waitlist instead.
// 'public' => true in config opens it; without a preview_password nobody gets in.
const PRELAUNCH_PAGES = ['/', '/kaip-veikia', '/apie', '/privatumas', '/favicon.svg', '/robots.txt'];

// True for visitors who see the pre-launch site: links to sign-up lead to the waitlist.
function prelaunch(?bool $set = null): bool
{
    static $on = false;
    return $set === null ? $on : $on = $set;
}

function preview_gate(string $path): void
{
    $c = config();
    if (!empty($c['public'])) {
        return;
    }
    $password = (string) ($c['preview_password'] ?? '');
    $token = $password !== '' ? hash_hmac('sha256', 'preview', $password) : '';
    if ($token !== '' && hash_equals($token, (string) ($_COOKIE['gang_preview'] ?? ''))) {
        return;
    }
    prelaunch(true);
    if (in_array($path, PRELAUNCH_PAGES, true)) {
        return;
    }
    header('X-Robots-Tag: noindex, nofollow');
    $error = '';
    $joined = false;
    $wl = ['email' => '', 'kind' => ['darbuotojas' => 'worker', 'vadovas' => 'manager'][$_GET['kaip'] ?? ''] ?? '', 'city' => ''];
    $wlErrors = [];
    if ($path === '/laukiantieji' && is_post()) {
        [$joined, $wl, $wlErrors] = waitlist_join();
    }
    if ($token !== '' && $path === '/perziura' && is_post()) {
        usleep(400000);
        if (hash_equals($password, (string) ($_POST['password'] ?? ''))) {
            setcookie('gang_preview', $token, ['expires' => time() + 60 * 60 * 24 * 90, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => str_starts_with($c['public_url'], 'https://')]);
            $next = (string) ($_POST['next'] ?? '/');
            header('Location: '.(str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/'), true, 303);
            exit;
        }
        $error = '<span class="err">Neteisingas slaptažodis.</span>';
    }
    http_response_code($path === '/perziura' && $error ? 401 : 200);
    $next = $path === '/perziura' ? (string) ($_POST['next'] ?? '/') : ($_SERVER['REQUEST_URI'] ?? '/');
    if (in_array($next, ['/laukiantieji', '/perziura'], true)) {
        $next = '/';
    }
    $team = $token === '' ? '' : '<details class="team-access"'.($error ? ' open' : '').'><summary>GANG komandos prieiga</summary>'
        .'<form method="post" action="/perziura" class="form">'
        .'<input type="hidden" name="next" value="'.e($next).'">'
        .'<label>Peržiūros slaptažodis <input type="password" name="password" autocomplete="current-password" required>'.$error.'</label>'
        .'<button class="btn small ghost">Įeiti</button></form></details>';
    $form = $joined
        ? '<div class="panel ok"><h2>Ačiū, jūs laukiančiųjų sąraše</h2><p>Kai GANG pradės veikti, atsiųsime kvietimą registruotis adresu <strong>'.e($wl['email']).'</strong>.</p></div>'
        : '<form method="post" action="/laukiantieji" class="form waitlist" novalidate>'.csrf_field()
            .'<fieldset><legend>Aš</legend>'.radios('kind', ['worker' => 'Ieškau darbo', 'manager' => 'Ieškau darbuotojų'], $wl['kind']).err($wlErrors, 'kind').'</fieldset>'
            .'<div class="row"><label>El. paštas <input type="email" name="email" value="'.e($wl['email']).'" autocomplete="email" inputmode="email" required>'.err($wlErrors, 'email').'</label>'
            .'<label>Miestas <small>(neprivaloma)</small><select name="city">'.options(['' => 'Pasirinkite'] + CITIES, $wl['city']).'</select></label></div>'
            // Bots fill every field; people never see this one.
            .'<label class="offscreen" aria-hidden="true">Svetainė <input name="website" tabindex="-1" autocomplete="off"></label>'
            .'<button class="btn">Stoti į laukiančiųjų sąrašą</button>'
            .'<p class="fine">El. pašto adresą naudosime tik tam, kad praneštume apie GANG paleidimą. Iš sąrašo galite išsiregistruoti bet kada, atsakę į kvietimo laišką.</p></form>';
    echo '<!doctype html><html lang="lt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        .'<title>GANG · netrukus</title><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/style.css?v=17"></head>'
        .'<body><main><section class="wrap narrow soon"><a class="logo" href="/">GANG<span>.lt</span></a>'
        .'<h1>Netrukus</h1><p class="lead">Kuriame platformą, padėsiančią barams, restoranams ir kavinėms greitai rasti patikimus darbuotojus, o hospitality specialistams – papildomų pajamų ir darbo pasiūlymų.</p>'
        .'<p>Užsiregistruokite laukiančiųjų sąraše ir paleidimo dieną gausite kvietimą prisijungti vieni pirmųjų.</p>'
        .$form.'<p class="back"><a href="/">← Grįžti į pagrindinį puslapį</a></p>'.$team.'</section></main></body></html>';
    exit;
}

// Returns [joined, values, errors].
function waitlist_join(): array
{
    check_csrf();
    $v = ['email' => mb_strtolower(input('email')), 'kind' => input('kind'), 'city' => isset(CITIES[input('city')]) ? input('city') : ''];
    $errors = [];
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['email']) > 190) {
        $errors['email'] = 'Patikrinkite el. pašto adresą.';
    }
    if (!in_array($v['kind'], ['worker', 'manager'], true)) {
        $errors['kind'] = 'Pasirinkite, ar ieškote darbo, ar darbuotojų.';
    }
    if ($errors) {
        return [false, $v, $errors];
    }
    if (input('website') !== '') {
        return [true, $v, []];
    }
    $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '').'|'.(config()['preview_password'] ?? ''));
    if ((int) sql('SELECT COUNT(*) FROM waitlist WHERE ip_hash = ? AND created_at > ?', [$ip, date('Y-m-d H:i:s', time() - 3600)])->fetchColumn() >= 10) {
        return [false, $v, ['email' => 'Per daug bandymų. Bandykite dar kartą po valandos.']];
    }
    if (sql('SELECT id FROM waitlist WHERE email = ?', [$v['email']])->fetchColumn()) {
        sql('UPDATE waitlist SET kind = ?, city = ? WHERE email = ?', [$v['kind'], $v['city'], $v['email']]);
    } else {
        insert('waitlist', ['email' => $v['email'], 'kind' => $v['kind'], 'city' => $v['city'], 'ip_hash' => $ip, 'created_at' => now()]);
    }
    return [true, $v, []];
}
