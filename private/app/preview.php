<?php

declare(strict_types=1);

// Until launch the whole site sits behind one shared preview password.
// 'public' => true in config opens it; without a preview_password nobody gets in.
function preview_gate(string $path): void
{
    $c = config();
    if (!empty($c['public']) || in_array($path, ['/favicon.svg', '/robots.txt'], true)) {
        return;
    }
    header('X-Robots-Tag: noindex, nofollow');
    $password = (string) ($c['preview_password'] ?? '');
    $token = $password !== '' ? hash_hmac('sha256', 'preview', $password) : '';
    if ($token !== '' && hash_equals($token, (string) ($_COOKIE['gang_preview'] ?? ''))) {
        return;
    }
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
    http_response_code(401);
    $next = $path === '/perziura' ? (string) ($_POST['next'] ?? '/') : ($_SERVER['REQUEST_URI'] ?? '/');
    $form = $token === '' ? '' : '<form method="post" action="/perziura" class="form">'
        .'<input type="hidden" name="next" value="'.e($next).'">'
        .'<label>Peržiūros slaptažodis <input type="password" name="password" autocomplete="current-password" autofocus required>'.$error.'</label>'
        .'<button class="btn">Įeiti</button></form>';
    echo '<!doctype html><html lang="lt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        .'<title>GANG · netrukus</title><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/style.css?v=9"></head>'
        .'<body><main><section class="wrap narrow" style="padding-top:12vh"><p class="logo">GANG<span>.lt</span></p>'
        .'<h1>Netrukus</h1><p class="lead">Kuriame platformą, padėsiančią barams, restoranams ir kavinėms greitai rasti patikimus darbuotojus.</p>'.$form.'</section></main></body></html>';
    exit;
}
