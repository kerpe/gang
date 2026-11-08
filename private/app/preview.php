<?php

declare(strict_types=1);

// 2026-11-07 Vilius: locked again with the team password until the real launch.
// SITE_OPEN = false: every visitor without the shared team password (preview_password,
// from the PREVIEW_PASSWORD secret) sees one "Netrukus" page; /admin stays reachable.
// SITE_OPEN = true opens the site to everyone (instant sign-up, as in PR #68).
const SITE_OPEN = false;

function preview_gate(string $path): void
{
    $c = config();
    if (SITE_OPEN || $path === '/admin' || str_starts_with($path, '/atsisakyti/') || admin_signed_in()) {
        return;
    }
    $password = (string) ($c['preview_password'] ?? '');
    $token = $password !== '' ? hash_hmac('sha256', 'preview', $password) : '';
    if ($token !== '' && hash_equals($token, (string) ($_COOKIE['gang_preview'] ?? ''))) {
        return;
    }
    if (in_array($path, ['/favicon.svg', '/robots.txt'], true)) {
        return;
    }
    header('X-Robots-Tag: noindex, nofollow');
    $error = '';
    if ($token !== '' && $path === '/perziura' && is_post()) {
        usleep(400000);
        if (hash_equals($password, (string) ($_POST['password'] ?? ''))) {
            setcookie('gang_preview', $token, ['expires' => time() + 60 * 60 * 24 * 90, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => str_starts_with($c['public_url'], 'https://')]);
            $next = (string) ($_POST['next'] ?? '/');
            header('Location: '.(str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, '\\') ? $next : '/'), true, 303);
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
    echo '<!doctype html><html lang="lt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        .'<title>GANG · netrukus</title><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/style.css?v=68"></head>'
        .'<body><main><section class="wrap narrow soon"><span class="logo">'.knives().'GANG<span>.lt</span></span>'
        .'<h1>Netrukus</h1>'
        .'<p class="meta">Susisiekti: <a href="mailto:hello@gang.lt">hello@gang.lt</a></p>'.$team.'</section></main></body></html>';
    exit;
}
