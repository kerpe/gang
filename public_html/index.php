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

foreach (['bootstrap', 'preview', 'data', 'views', 'auth', 'public', 'account', 'requests', 'reviews', 'admin', 'photos', 'shifts', 'contracts', 'team', 'legal', 'messages'] as $file) {
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
preview_gate($path);
// Bulk email left in the queue goes out after this page is sent (see mail.php).
if (!str_starts_with($path, '/nuotrauka/') && mail_queue_pending()) {
    mail_queue_after_response(20);
}
release_team_requests();

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
    $is('/darbas/(\d+)/papildyti') && $post => response_note_action((int) $m[1]),
    $is('/darbas/(\d+)/kviesti/(\d+)') && $post => invite_action((int) $m[1], (int) $m[2]),
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
    $is('/kontraktas/(\d+)/zinute') && $post => message_send_action((int) $m[1]),
    $is('/kontraktas/(\d+)/zinutes') => messages_poll((int) $m[1]),
    $is('/kontraktas/(\d+)/reakcija') && $post => message_react_action((int) $m[1]),
    $is('/pokalbis/(\d+)') => chat_link_page((int) $m[1]),
    $is('/darbas/(\d+)/skelbti') && $post => release_now_action((int) $m[1]),
    $is('/k/([a-z0-9]{3,80})') => invite_landing_page($m[1]),
    $path === '/komanda/kviesti' && $post => team_mail_action(),
    $is('/darbuotojas/(\d+)') => worker_profile_page((int) $m[1]),
    $is('/nuotrauka/(\d+)') => photo_page((int) $m[1]),
    $is('/atsisakyti/(\d+)/([0-9a-f]{32})') => unsubscribe_page((int) $m[1], $m[2]),
    default => not_found(),
};
