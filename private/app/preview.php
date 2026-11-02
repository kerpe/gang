<?php

declare(strict_types=1);

// Until launch the home, how-it-works, about and privacy pages are open to everyone, and both
// sides may already sign up: workers build their profile, employers register their venues.
// Everything else (jobs, requests, availability, other people's profiles) sits behind one shared
// preview password; visitors see the sign-up page instead.
// 'public' => true in config opens it; without a preview_password nobody gets in.
// PRELAUNCH_OPEN = false closes even the public pages and sign-up: every visitor without the
// password sees one "Netrukus" page (Vilius, 2026-11-02: nothing visible until he says so).
const PRELAUNCH_OPEN = false;
const PRELAUNCH_PAGES = ['/', '/kaip-veikia', '/apie', '/privatumas', '/favicon.svg', '/robots.txt'];
const PRELAUNCH_SIGNUP_PAGES = ['/prisijungti', '/prisijungti/kodas', '/prisijungti/kas', '/profilis', '/mano'];

// True for visitors who see the pre-launch site: sign-up and profiles work, the job board does not.
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
    if (in_array($path, ['/favicon.svg', '/robots.txt'], true)) {
        return;
    }
    if (PRELAUNCH_OPEN && (in_array($path, PRELAUNCH_PAGES, true) || in_array($path, PRELAUNCH_SIGNUP_PAGES, true) || $path === '/atsijungti')) {
        return;
    }
    $user = PRELAUNCH_OPEN ? current_user() : null;
    if ($user !== null) {
        $own = $user['kind'] === 'worker'
            ? $path === '/nuotrauka/'.$user['id']
            : $path === '/darboviete/nauja' || preg_match('#^/darboviete/\d+/redaguoti$#', $path);
        if ($own) {
            return;
        }
        redirect('/mano');
    }
    header('X-Robots-Tag: noindex, nofollow');
    $error = '';
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
    if (in_array($next, ['/registracija', '/laukiantieji', '/perziura'], true)) {
        $next = '/';
    }
    $team = $token === '' ? '' : '<details class="team-access"'.($error ? ' open' : '').'><summary>GANG komandos prieiga</summary>'
        .'<form method="post" action="/perziura" class="form">'
        .'<input type="hidden" name="next" value="'.e($next).'">'
        .'<label>Peržiūros slaptažodis <input type="password" name="password" autocomplete="current-password" required>'.$error.'</label>'
        .'<button class="btn small ghost">Įeiti</button></form></details>';
    $workerBox = '<div class="join-box worker"><p class="eyebrow">Darbuotojams</p><h2>Susikurkite profilį jau dabar</h2>'
        .'<p>Nurodykite pareigas, patirtį ir miestus, kuriuose galite dirbti. Starto dieną jūsų profilis jau bus matomas darbdaviams.</p>'
        .'<p class="later"><strong>'.e(from_launch()).'</strong> kalendoriuje pažymėsite laiką, kada galite dirbti, ir gausite jums tinkančius darbo pasiūlymus.</p>'
        .'<p><a class="btn" href="/prisijungti?kaip=darbuotojas">Susikurti profilį</a></p></div>';
    $employerBox = '<div class="join-box employer"><p class="eyebrow">Darbdaviams</p><h2>Užregistruokite savo darbovietę</h2>'
        .'<p>Užregistruokite savo barą, restoraną ar kavinę: pavadinimą, miestą ir trumpą aprašymą apie komandą ir darbo sąlygas.</p>'
        .'<p class="later"><strong>'.e(from_launch()).'</strong> skelbsite darbuotojų poreikius, o tinkami darbuotojai juos gaus iš karto.</p>'
        .'<p><a class="btn light" href="/prisijungti?kaip=vadovas">Užregistruoti darbovietę</a></p></div>';
    $employerFirst = ($_GET['kaip'] ?? '') === 'vadovas';
    if (!PRELAUNCH_OPEN) {
        echo '<!doctype html><html lang="lt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>GANG · netrukus</title><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/style.css?v=28"></head>'
            .'<body><main><section class="wrap narrow soon"><span class="logo">'.knives().'GANG<span>.lt</span></span>'
            .'<h1>Netrukus</h1><p class="lead">Kuriame platformą, padėsiančią barams, restoranams ir kavinėms greitai rasti patikimus darbuotojus, o hospitality specialistams – papildomų pajamų ir darbo pasiūlymų.</p>'
            .(launch_when() !== '' ? '<p>'.e(str_replace('GANG startuoja', 'Startuojame', launch_headline())).'.</p>' : '')
            .'<p class="meta">Susisiekti: <a href="mailto:hello@gang.lt">hello@gang.lt</a></p>'.$team.'</section></main></body></html>';
        exit;
    }
    echo '<!doctype html><html lang="lt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        .'<title>GANG · netrukus</title><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/style.css?v=28"></head>'
        .'<body><main><section class="wrap soon"><a class="logo" href="/" aria-label="GANG.lt">'.knives().'GANG<span>.lt</span></a>'
        .'<h1>Prisijunkite prie GANG</h1><p class="lead">GANG padės barams, restoranams ir kavinėms greitai rasti patikimus darbuotojus, o hospitality specialistams – papildomų pajamų ir darbo pasiūlymų. '.e(launch_when() !== '' ? str_replace('GANG startuoja', 'Startuojame', launch_headline()).'. Pasiruošti galite jau dabar.' : 'Platforma pradės veikti netrukus, o pasiruošti galite jau dabar.').'</p>'
        .'<div class="join'.($employerFirst ? ' employer-first' : '').'">'.$workerBox.$employerBox.'</div>'
        .'<p class="meta">Jau užsiregistravote? <a href="/prisijungti">Prisijunkite</a></p>'
        .'<p class="back"><a href="/">← Grįžti į pagrindinį puslapį</a></p>'.$team.'</section></main></body></html>';
    exit;
}
