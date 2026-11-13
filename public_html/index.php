<?php

declare(strict_types=1);

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
// No plugins, no <base> tricks, no framing, forms post only to GANG itself. (Inline scripts and
// styles are part of the pages, so script-src is not restricted here.)
header("Content-Security-Policy: base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');

// Errors that end a request (and slow /admin pages) go to private/data/errors.log; the last lines are on
// /admin → Nustatymai. Added 2026-11-13 to find why /admin dropped the connection after the sign-in code.
$GLOBALS['gang_t0'] = microtime(true);
register_shutdown_function(function (): void {
    $e = error_get_last();
    $uri = substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 120);
    $ms = (int) ((microtime(true) - $GLOBALS['gang_t0']) * 1000);
    $line = '';
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $line = 'FATAL '.$e['message'].' @ '.basename($e['file']).':'.$e['line'];
    } elseif (str_starts_with($uri, '/admin') && $ms > 3000) {
        $line = 'SLOW '.$ms.' ms';
    }
    if ($line !== '') {
        @file_put_contents(dirname(__DIR__).'/private/data/errors.log', date('Y-m-d H:i:s').' '.$uri.' '.$line."\n", FILE_APPEND | LOCK_EX);
    }
});
set_exception_handler(function (Throwable $t): void {
    @file_put_contents(dirname(__DIR__).'/private/data/errors.log', date('Y-m-d H:i:s').' '.substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 120).' '.get_class($t).' '.$t->getMessage().' @ '.basename($t->getFile()).':'.$t->getLine()."\n", FILE_APPEND | LOCK_EX);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!doctype html><meta charset="utf-8"><title>GANG</title><p style="font:16px sans-serif;padding:24px">Įvyko klaida. Pabandykite dar kartą po minutės.</p>';
});

foreach (['bootstrap', 'preview', 'data', 'views', 'auth', 'public', 'account', 'requests', 'reviews', 'admin', 'analytics', 'photos', 'shifts', 'contracts', 'team', 'legal', 'messages'] as $file) {
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
lang_switch();
i18n_start();
preview_gate($path);
// Bulk email left in the queue goes out after this page is sent (see mail.php).
if (!str_starts_with($path, '/nuotrauka/') && mail_queue_pending()) {
    mail_queue_after_response(20);
}

match (true) {
    $path === '/' => home_page(),
    $path === '/darbai' => jobs_page(),
    $path === '/kaip-veikia' => how_page(),
    $path === '/apie' => about_page(),
    $path === '/baras' => team_page('01'),
    $path === '/virtuve' => team_page('02'),
    $path === '/aptarnavimas' => team_page('03'),
    $path === '/sale' => (function (): never { header('Location: /aptarnavimas', true, 301); exit; })(),
    $path === '/privatumas' => privacy_page(),
    $path === '/taisykles' => terms_page(),
    $path === '/sutikimas' => consent_page(),
    $path === '/sitemap.xml' => sitemap(),
    $path === '/prisijungti' => login_page(),
    $path === '/prisijungti/kodas' => login_code_page(),
    $path === '/prisijungti/kas' => choose_kind_page(),
    $path === '/atsijungti' && $post => logout(),
    $path === '/atsijungti/visur' && $post => logout_everywhere(),
    $path === '/mano' => dashboard_page(),
    $path === '/mano/pasiulymai' => worker_offers_page(),
    $path === '/mano/paieskos' => manager_requests_page(),
    $path === '/mano/puse' && $post => side_switch_action(),
    $path === '/mano/prideti' && $post => side_add_action(),
    $path === '/profilis' => profile_page(),
    $path === '/profilis/duomenys' => account_export(),
    $path === '/profilis/istrinti' => account_delete_page(),
    $path === '/darboviete/nauja' => venue_form_page(),
    $path === '/poreikis/naujas' => request_form_page(),
    $path === '/admin' => admin_page(),
    $is('/darboviete/(\d+)') => venue_page((int) $m[1]),
    $is('/darboviete/(\d+)/redaguoti') => venue_form_page((int) $m[1]),
    $is('/darboviete/(\d+)/logotipas') => venue_logo_page((int) $m[1]),
    $is('/darbas/(\d+)') => request_page((int) $m[1]),
    $is('/kandidatura/(\d+)') => application_link_page((int) $m[1]),
    $is('/kandidatura/(\d+)/prisijungti') && $post => application_switch_action((int) $m[1]),
    $is('/darbas/(\d+)/atsiliepti') && $post => respond_action((int) $m[1]),
    $is('/darbas/(\d+)/busena') => application_state_poll((int) $m[1]),
    $is('/darbas/(\d+)/atsaukti') && $post => withdraw_action((int) $m[1]),
    $is('/darbas/(\d+)/uzdaryti') && $post => close_request_action((int) $m[1]),
    $is('/darbas/(\d+)/palikti') && $post => keep_open_action((int) $m[1]),
    $is('/darbas/(\d+)/redaguoti') => request_edit_page((int) $m[1]),
    $is('/darbas/(\d+)/tinka') && $post => confirm_changes_action((int) $m[1]),
    $is('/atsiliepimas/(\d+)/patvirtinti') && $post => decide_response_action((int) $m[1], true),
    $is('/atsiliepimas/(\d+)/atmesti') && $post => decide_response_action((int) $m[1], false),
    $is('/pamaina/([0-9a-f]{32})') => shift_page($m[1]),
    $is('/vertinti/(\d+)') => review_page((int) $m[1]),
    $is('/kontraktas/(\d+)/kalendorius') => contract_ics((int) $m[1]),
    $is('/kontraktas/(\d+)/atsaukti') => contract_cancel_page((int) $m[1]),
    $is('/kontraktas/(\d+)/neatvyko') && $post => no_show_action((int) $m[1]),
    $is('/kontraktas/(-?\d+)/zinute') && $post => message_send_action((int) $m[1]),
    $is('/kontraktas/(-?\d+)/zinutes') => messages_poll((int) $m[1]),
    $is('/kontraktas/(-?\d+)/reakcija') && $post => message_react_action((int) $m[1]),
    $is('/pokalbis/(-?\d+)') => chat_link_page((int) $m[1]),
    $is('/k/([a-z0-9]{3,80})') => invite_landing_page($m[1]),
    $is('/darbuotojas/(\d+)') => worker_profile_page((int) $m[1]),
    $is('/nuotrauka/(\d+)') => photo_page((int) $m[1]),
    $is('/atsisakyti/(\d+)/([0-9a-f]{32})/priminimai') => signup_reminders_off_page((int) $m[1], $m[2]),
    $is('/atsisakyti/(\d+)/([0-9a-f]{32})') => unsubscribe_page((int) $m[1], $m[2]),
    default => not_found(),
};
