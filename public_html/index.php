<?php

declare(strict_types=1);

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

foreach (['bootstrap', 'data', 'views', 'auth', 'public', 'account', 'requests', 'reviews', 'admin'] as $file) {
    require dirname(__DIR__)."/private/app/$file.php";
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$post = is_post();

// One address for the site: https://gang.lt (no www).
$canonical = parse_url(config()['public_url'], PHP_URL_HOST);
$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
if ($canonical && $host === 'www.'.$canonical) {
    header('Location: '.url($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}
if (str_starts_with(config()['public_url'], 'https://')) {
    header('Strict-Transport-Security: max-age=31536000');
}

$m = [];
$is = function (string $pattern) use ($path, &$m): bool {
    return (bool) preg_match('#^'.$pattern.'$#', $path, $m);
};
match (true) {
    $path === '/' => home_page(),
    $path === '/darbai' => jobs_page(),
    $path === '/kaip-veikia' => how_page(),
    $path === '/privatumas' => privacy_page(),
    $path === '/sitemap.xml' => sitemap(),
    $path === '/prisijungti' => login_page(),
    $path === '/prisijungti/kodas' => login_code_page(),
    $path === '/prisijungti/kas' => choose_kind_page(),
    $path === '/atsijungti' && $post => logout(),
    $path === '/mano' => dashboard_page(),
    $path === '/profilis' => profile_page(),
    $path === '/laisvas' && $post => availability_action(),
    $path === '/istaiga/nauja' => venue_form_page(),
    $path === '/poreikis/naujas' => request_form_page(),
    $path === '/admin' => admin_page(),
    $is('/istaiga/(\d+)') => venue_page((int) $m[1]),
    $is('/istaiga/(\d+)/redaguoti') => venue_form_page((int) $m[1]),
    $is('/darbas/(\d+)') => request_page((int) $m[1]),
    $is('/darbas/(\d+)/atsiliepti') && $post => respond_action((int) $m[1]),
    $is('/darbas/(\d+)/atsaukti') && $post => withdraw_action((int) $m[1]),
    $is('/darbas/(\d+)/uzdaryti') && $post => close_request_action((int) $m[1]),
    $is('/atsiliepimas/(\d+)/patvirtinti') && $post => decide_response_action((int) $m[1], true),
    $is('/atsiliepimas/(\d+)/atmesti') && $post => decide_response_action((int) $m[1], false),
    $is('/vertinti/(\d+)') => review_page((int) $m[1]),
    $is('/darbuotojas/(\d+)') => worker_profile_page((int) $m[1]),
    default => not_found(),
};
