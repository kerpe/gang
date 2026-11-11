<?php

declare(strict_types=1);

function page(string $title, string $body, array $opt = []): never
{
    $e = 'e';
    $desc = $opt['description'] ?? 'GANG – platforma, padedanti restoranams, kavinėms, barams ir klubams greitai rasti patikimus darbuotojus skubiam pavadavimui, sezonui ar nuolatiniam darbui.';
    $fullTitle = $title === '' ? 'GANG – darbas hospitality komandoms' : $title.' · GANG';
    $robots = !empty($opt['noindex']) ? '<meta name="robots" content="noindex">' : '';
    $canonical = isset($opt['canonical']) ? '<link rel="canonical" href="'.e(url($opt['canonical'])).'">' : '';
    $head = $opt['head'] ?? '';
    // Link previews (Facebook, Messenger, LinkedIn, X): one shared picture for every page.
    $ogUrl = url($opt['canonical'] ?? (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
    $ogImage = url('/img/og.jpg?v=1');
    $user = current_user();
    // New chat messages: a count on "Mano paskyra" (the contract cards there link to each chat).
    $unread = $user ? unread_messages_total((int) $user['id']) : 0;
    $badge = $unread ? ' <span class="count" data-unread-total aria-label="Naujos žinutės: '.$unread.'">'.$unread.'</span>' : '';
    if ($user) {
        $nav = '<a class="hide-sm" href="/darbai">Darbo pasiūlymai</a><a class="hide-sm" href="/kaip-veikia">Kaip veikia</a><a class="hide-sm" href="/apie">Apie GANG</a><a class="btn small" href="/mano">Mano paskyra'.$badge.'</a>'.post_button('/atsijungti', 'Atsijungti', [], 'btn small ghost');
    } else {
        $nav = '<a class="hide-sm" href="/darbai">Darbo pasiūlymai</a><a class="hide-sm" href="/kaip-veikia">Kaip veikia</a><a class="hide-sm" href="/apie">Apie GANG</a><a class="btn small ghost hide-sm" href="/prisijungti">Prisijungti</a><a class="btn small" href="/prisijungti?registruotis">Registruotis</a>';
    }
    // Phones: a guest menu behind one button; signed-in members get a bottom tab bar.
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $menuLinks = '<a href="/darbai">Darbo pasiūlymai</a><a href="/kaip-veikia">Kaip veikia</a><a href="/apie">Apie GANG</a>'.($user ? post_button('/atsijungti', 'Atsijungti', [], 'mnav-logout') : '<a href="/prisijungti">Prisijungti</a>');
    $nav .= '<details class="mnav"><summary aria-label="Meniu"><span></span><span></span><span></span></summary><div class="mnav-links">'.$menuLinks.'</div></details>';
    $tabbar = '';
    if ($user && profile_complete($user)) {
        $tabs = $user['kind'] === 'worker'
            ? [['/mano', 'Mano paskyra', 'home'], ['/darbai', 'Darbai', 'list'], ['/mano#kalendorius', 'Kalendorius', 'cal']]
            : [['/mano', 'Mano paskyra', 'home'], ['/poreikis/naujas', 'Nauja paieška', 'plus'], ['/darbai', 'Darbai', 'list']];
        $icons = [
            'home' => '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
            'list' => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
            'cal' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
            'plus' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        ];
        $tabbar = '<nav class="tabbar" aria-label="Pagrindinis meniu">';
        foreach ($tabs as [$href, $label, $icon]) {
            $current = $href === $path ? ' aria-current="page"' : '';
            $tabbar .= '<a href="'.$href.'"'.$current.($icon === 'plus' ? ' class="accent"' : '').'><svg viewBox="0 0 24 24" aria-hidden="true">'.$icons[$icon].'</svg>'.e($label).($href === '/mano' ? $badge : '').'</a>';
        }
        $tabbar .= '</nav>';
    }
    // The admin area has its own frame: no public menu, footer or tab bar.
    $admin = ($opt['shell'] ?? '') === 'admin';
    if ($admin) {
        $nav = '<span class="admin-badge">Administravimas</span>';
        $tabbar = '';
    }
    $consent = $admin ? '' : analytics_consent();
    $bodyClass = $admin ? ' class="admin-body"' : ($tabbar ? ' class="has-tabbar"' : '');
    $flash = flash();
    // Shown as a toast at the bottom of the screen, where it is seen after a form is sent.
    $flash = $flash !== '' ? '<div class="toast" role="status"><p>'.e($flash).'</p><button type="button" aria-label="Uždaryti" onclick="this.parentNode.remove()">×</button></div>'
        .'<script>setTimeout(function () { var t = document.querySelector(".toast"); if (t) { t.classList.add("out"); setTimeout(function () { t.remove(); }, 400); } }, 9000);</script>' : '';
    $year = date('Y');
    $logoMark = knives();
        $footer = $admin ? '' : '<footer class="foot"><div class="wrap">'
        .'<p><strong>GANG.lt</strong> – patikimi kolegos restoranams, kavinėms, barams ir klubams.</p>'
        .'<p><a href="/kaip-veikia">Kaip veikia</a> · <a href="/apie">Apie GANG</a> · <a href="/darbai">Darbo pasiūlymai</a> · <a href="/taisykles">Taisyklės</a> · <a href="/privatumas">Privatumas</a> · <a href="#slapukai" data-consent-open>Slapukai</a> · © '.$year.'</p>'
        .'</div></footer>';
    echo <<<HTML
<!doctype html>
<html lang="lt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$e($fullTitle)}</title>
<meta name="description" content="{$e($desc)}">
<meta property="og:title" content="{$e($fullTitle)}">
<meta property="og:description" content="{$e($desc)}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="GANG.lt">
<meta property="og:locale" content="lt_LT">
<meta property="og:url" content="{$e($ogUrl)}">
<meta property="og:image" content="{$e($ogImage)}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="GANG.lt – patikimi žmonės restoranams, kavinėms, barams ir klubams">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#171717">
$robots$canonical
<link rel="preload" href="/fonts/anton-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/anton-latin-ext.woff2" as="font" type="font/woff2" crossorigin>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/style.css?v=96">
$head
</head>
<body$bodyClass>
<header class="top"><div class="wrap">
  <a class="logo" href="/" aria-label="GANG.lt">{$logoMark}GANG<span>.lt</span></a>
  <nav>$nav</nav>
</div></header>
<main>
$flash
$body
</main>
$footer
$tabbar
$consent
<script>
// A field's error disappears as soon as the field is changed.
document.addEventListener('input', clearErr); document.addEventListener('change', clearErr);
function clearErr(e) { var box = e.target.closest && (e.target.closest('fieldset') || e.target.closest('label')); if (box && box.closest('form.form')) box.querySelectorAll('.err').forEach(function (x) { x.remove(); }); }
// A second tap while a form is still being sent (slow mobile network) would post it twice,
// e.g. two identical job requests. Forms that handle sending themselves (chat) prevent the default and are skipped.
document.addEventListener('submit', function (e) { var f = e.target; if (e.defaultPrevented || f.method !== 'post') return; if (f.dataset.sending) { e.preventDefault(); return; } f.dataset.sending = '1'; setTimeout(function () { delete f.dataset.sending; }, 8000); });
window.addEventListener('pageshow', function () { document.querySelectorAll('form[data-sending]').forEach(function (f) { delete f.dataset.sending; }); });
// Remaining characters under text fields that have a maxlength: shown while the field is in use.
// A textarea with data-min (venue/job description) first counts up to its minimum and stays visible.
// Short numeric fields (pay, phone), link fields (255) and the chat box are left without a counter.
var pointerDown = false;
document.addEventListener('pointerdown', function () { pointerDown = true; }, true);
document.addEventListener('pointerup', function () { pointerDown = false; }, true);
document.addEventListener('pointercancel', function () { pointerDown = false; }, true);
document.querySelectorAll('form.form input[maxlength], form.form textarea[maxlength]').forEach(function (f) {
  var max = +f.getAttribute('maxlength'), min = +f.dataset.min || 0;
  if (max < 40 || (max >= 255 && f.tagName === 'INPUT') || f.readOnly || f.hasAttribute('data-nocount') || f.type === 'tel' || /numeric|decimal/.test(f.inputMode)) return;
  var out = f.parentNode.querySelector('.counter');
  if (!out) { out = document.createElement('span'); out.className = 'counter meta'; (f.closest('.input-suffix') || f).after(out); }
  out.hidden = !min;
  function show() {
    var need = min - f.value.trim().length, left = max - f.value.length;
    out.textContent = need > 0 ? 'Dar reikia simbolių: ' + need : left > 0 ? 'Liko simbolių: ' + left : 'Pasiektas simbolių limitas';
  }
  f.addEventListener('input', show);
  f.addEventListener('focus', function () { show(); out.hidden = false; });
  // Hiding the counter moves everything below it up. When a tap or click on the save button is what
  // takes the focus away, wait until it is over, or the button slides away and the form is not sent.
  f.addEventListener('blur', function () {
    var hide = function () { out.hidden = !min; };
    if (pointerDown) document.addEventListener('pointerup', function () { setTimeout(hide, 0); }, { once: true }); else hide();
  });
  show();
});
</script>
</body>
</html>
HTML;
    exit;
}

// Cookie banner („Sutinku / Atsisakyti“). Google tag and Microsoft Clarity are loaded only after
// „Sutinku“; the choice is kept in the browser (localStorage) for a year, and the footer link
// „Slapukai“ opens the banner again. Refusing after agreeing removes their cookies.
function analytics_consent(): string
{
    $gtm = GOOGLE_TAG_ID;
    $clarity = CLARITY_ID;
    if ($gtm === '' && $clarity === '') {
        return '';
    }
    $ids = json_encode(['gtm' => $gtm, 'clarity' => $clarity]);
    return <<<HTML
<div class="cookie-bar" id="cookie-bar" role="dialog" aria-live="polite" aria-label="Slapukai" hidden>
  <p>Naudojame analitikos slapukus, kad matytume, kaip lankytojai naudojasi GANG, ir galėtume svetainę tobulinti. <a href="/privatumas#slapukai">Daugiau</a></p>
  <div class="cookie-bar-actions"><button type="button" class="btn small ghost" data-consent="no">Atsisakyti</button><button type="button" class="btn small" data-consent="yes">Sutinku</button></div>
</div>
<script>
(function () {
  var ids = $ids, key = 'gang_consent', box = document.getElementById('cookie-bar'), loaded = false;
  function read() { try { var v = JSON.parse(localStorage.getItem(key) || 'null'); return v && v.t > Date.now() - 365 * 864e5 ? v.c : null; } catch (e) { return null; } }
  function save(c) { try { localStorage.setItem(key, JSON.stringify({ c: c, t: Date.now() })); } catch (e) {} }
  function load() {
    if (loaded) return; loaded = true;
    if (ids.gtm) {
      window.dataLayer = window.dataLayer || [];
      window.gtag = function () { dataLayer.push(arguments); };
      dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
      gtag('js', new Date());
      var g = document.createElement('script'); g.async = true; g.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(ids.gtm); document.head.appendChild(g);
    }
    if (ids.clarity) {
      (function (c, l, a, r, i, t, y) { c[a] = c[a] || function () { (c[a].q = c[a].q || []).push(arguments); }; t = l.createElement(r); t.async = 1; t.src = 'https://www.clarity.ms/tag/' + i; y = l.getElementsByTagName(r)[0]; y.parentNode.insertBefore(t, y); })(window, document, 'clarity', 'script', ids.clarity);
    }
  }
  function wipe() {
    var host = location.hostname.replace(/^www\./, '');
    document.cookie.split(';').forEach(function (p) {
      var n = p.split('=')[0].trim();
      if (/^(_ga|_gid|_gat|_gcl|_clck|_clsk|CLID|MUID)/.test(n)) ['', '; domain=' + host, '; domain=.' + host].forEach(function (d) { document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + d; });
    });
  }
  var c = read();
  if (c === 'yes') load(); else if (c === null) box.hidden = false;
  box.addEventListener('click', function (e) {
    var b = e.target.closest('[data-consent]'); if (!b) return;
    var yes = b.dataset.consent === 'yes', was = read();
    save(yes ? 'yes' : 'no'); box.hidden = true;
    if (yes) load(); else { wipe(); if (was === 'yes') location.reload(); }
  });
  document.addEventListener('click', function (e) { if (e.target.closest('[data-consent-open]')) { e.preventDefault(); box.hidden = false; } });
})();
</script>
HTML;
}

function not_found(): never
{
    http_response_code(404);
    page('Puslapis nerastas', '<section class="wrap narrow"><h1>Puslapis nerastas</h1><p>Puslapis neegzistuoja arba skelbimas nebegalioja.</p><p><a class="btn" href="/darbai">Visi darbo pasiūlymai</a></p></section>', ['noindex' => true]);
}

function options(array $items, string $selected): string
{
    $out = '';
    foreach ($items as $value => $label) {
        $out .= '<option value="'.e((string) $value).'"'.((string) $value === $selected ? ' selected' : '').'>'.e($label).'</option>';
    }
    return $out;
}

function checkboxes(string $name, array $items, array $checked): string
{
    $out = '<div class="choices">';
    foreach ($items as $value => $label) {
        $out .= '<label class="choice"><input type="checkbox" name="'.$name.'[]" value="'.e($value).'"'.(in_array($value, $checked, true) ? ' checked' : '').'> '.e($label).'</label>';
    }
    return $out.'</div>';
}

function radios(string $name, array $items, string $checked): string
{
    $out = '<div class="choices">';
    foreach ($items as $value => $label) {
        $out .= '<label class="choice"><input type="radio" name="'.$name.'" value="'.e((string) $value).'"'.((string) $value === $checked ? ' checked' : '').'> '.e($label).'</label>';
    }
    return $out.'</div>';
}

function err(array $errors, string $name): string
{
    return isset($errors[$name]) ? '<span class="err">'.e($errors[$name]).'</span>' : '';
}

function post_button(string $action, string $label, array $fields = [], string $class = 'btn small', string $confirm = ''): string
{
    $hidden = '';
    foreach ($fields as $k => $v) {
        $hidden .= '<input type="hidden" name="'.e($k).'" value="'.e((string) $v).'">';
    }
    $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(\''.e($confirm).'\')"' : '';
    return '<form method="post" action="'.e($action).'" class="inline"'.$onsubmit.'>'.csrf_field().$hidden.'<button class="'.$class.'">'.e($label).'</button></form>';
}

/** GANG ženklas: sukryžiuoti virėjo peiliai (vertinimai, logotipas). */
function knives(string $class = 'gk'): string
{
    $k = '<path d="M7.5 10.2H20.5C22.5 10.2 23.2 11 23 12C21.8 13.6 18 14.6 7.5 14.6Z"/><rect x="1" y="10.9" width="6.2" height="3" rx="1"/>';
    $b = 'transform="translate(24 0) scale(-1 1) rotate(45 12 12)"';
    static $n = 0;
    $id = 'gk-m'.(++$n > 1 ? $n : '');
    return '<svg class="'.$class.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
        .'<mask id="'.$id.'" maskUnits="userSpaceOnUse" x="0" y="0" width="24" height="24"><rect width="24" height="24" fill="#fff"/><g '.$b.' fill="#000" stroke="#000" stroke-width="2.2" stroke-linejoin="round">'.$k.'</g></mask>'
        .'<g mask="url(#'.$id.')"><g transform="rotate(45 12 12)">'.$k.'</g></g><g '.$b.'>'.$k.'</g></svg>';
}

function stars(?float $avg, int $count): string
{
    if ($count === 0 || $avg === null) {
        return '<span class="stars none">Įvertinimų dar nėra</span>';
    }
    return '<span class="stars" title="'.number_format($avg, 1, ',', '').' iš 5">'.knives().' '.number_format($avg, 1, ',', '').' <small>('.$count.')</small></span>';
}

// One look for a job's duration everywhere: label from KINDS, colour per kind (style.css .tag.k-*).
function kind_tag(string $kind): string
{
    return '<span class="tag k-'.e($kind).'">'.e(KINDS[$kind] ?? $kind).'</span>';
}

// "14 €" + "/val. į rankas" from a stored pay label; null when the label has no amount.
function pay_parts(string $pay): ?array
{
    return preg_match('/^([\d,]+)\s*€\s*(.*)$/u', $pay, $m) ? [$m[1].' €', $m[2]] : null;
}

// onsubmit for forms that must not be sent twice (a double click would send a second login code).
const ONE_SUBMIT = ' onsubmit="if(this.dataset.sent)return false;this.dataset.sent=1"';

// "Šiandien", "Rytoj", "Po 3 d." for work starting within a week; '' otherwise.
function starts_in(string $date): string
{
    $days = (int) round((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
    return match (true) {
        $days <= 0 => 'Šiandien',
        $days === 1 => 'Rytoj',
        $days <= 7 => 'Po '.$days.' d.',
        default => '',
    };
}

// Home page "live feed" card (2026-11-11): the role's CCTV photo on top with the duration chip
// (and "Rytoj" / "Po 2 d." when the work starts soon), the venue logo on the photo's edge, then the role,
// workplace, pay and start date. Phones show it as a row: a square photo on the left, text on the right.
function request_card_short(array $r): string
{
    $logo = venue_logo(['id' => $r['venue_id'], 'name' => $r['venue_name'], 'logo' => $r['venue_logo'] ?? null], 'feed-logo');
    $parts = $r['pay'] !== '' ? pay_parts($r['pay']) : null;
    $pay = $r['pay'] === '' ? '' : ($parts
        ? '<p class="feed-pay"><strong>'.e($parts[0]).'</strong> <span>'.e($parts[1]).'</span></p>'
        : '<p class="feed-pay text"><strong>'.e($r['pay']).'</strong></p>');
    $ranges = !empty($r['dates']) ? explode(',', $r['dates']) : [$r['start_date'].':'.($r['end_date'] ?: $r['start_date'])];
    [$from, $to] = explode(':', $ranges[0]) + [1 => ''];
    $oneDay = count($ranges) === 1 && $from === $to && $r['kind'] !== 'nuolat';
    $when = ($oneDay ? '' : 'nuo ').date_lt($from);
    $kind = KINDS[$r['kind']] ?? $r['kind'];
    $soon = request_urgent($r) ? starts_in($from) : '';
    $keys = ROLE_PHOTOS[$r['role']] ?? [];
    $key = $keys ? $keys[(int) $r['id'] % count($keys)] : '';
    $img = $key !== '' ? '<img src="/img/job-'.$key.'-sm.jpg?v=2" srcset="/img/job-'.$key.'-sm.jpg?v=2 640w, /img/job-'.$key.'.jpg?v=2 1200w" sizes="(max-width: 640px) 104px, (max-width: 960px) 50vw, 420px" alt="" width="640" height="480" loading="lazy" decoding="async">' : '';
    return '<a class="card feed-card" href="/darbas/'.(int) $r['id'].'">'
        .'<div class="feed-media">'.$img
        .'<span class="feed-chips"><span class="feed-chip">'.e($kind).'</span>'.($soon !== '' ? '<span class="feed-chip soon">'.e($soon).'</span>' : '').'</span>'
        .'<span class="feed-go" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M8 7h9v9"/></svg></span></div>'
        .'<div class="feed-body">'.$logo
        .'<h3>'.e(ROLES[$r['role']] ?? $r['role']).'</h3>'
        .'<p class="feed-venue">'.e($r['venue_name']).' · '.e(CITIES[$r['city']] ?? '').'</p>'
        .'<div class="feed-foot">'.$pay.'<p class="feed-when"><span class="feed-when-kind">'.e($kind).' · </span>'.e($when).'</p></div></div></a>';
}

// One CCTV frame from JOB_PHOTOS with its camera label. $size: '' (1200 px) or '-sm' (640 px); $eager for a photo at the top of a page.
function cam_frame(string $key, string $class, string $size = '', bool $eager = false): string
{
    $n = str_pad((string) (array_search($key, array_keys(JOB_PHOTOS), true) + 1), 2, '0', STR_PAD_LEFT);
    [$w, $h] = $size === '' ? [1200, 900] : [640, 480];
    return '<div class="job-photo '.$class.'" aria-hidden="true"><img src="/img/job-'.$key.$size.'.jpg?v=2" alt="" width="'.$w.'" height="'.$h.'"'.($eager ? ' fetchpriority="high"' : ' loading="lazy"').'>'
        .'<span class="cam-label">CAM '.$n.' · '.e(mb_strtoupper(JOB_PHOTOS[$key])).'</span></div>';
}

// The photo of a job ad, chosen by role; '' when the role has none.
function job_photo(array $r, string $class, string $size = ''): string
{
    $keys = ROLE_PHOTOS[$r['role']] ?? [];
    return $keys ? cam_frame($keys[(int) $r['id'] % count($keys)], $class, $size, $size === '') : '';
}

// $goodTag: false on cards of jobs the viewer already posted or applied for.
function request_card(array $r, string $extra = '', bool $goodTag = true): string
{
    $people = (int) $r['people'] > 1 ? ' · reikia darbuotojų: '.(int) $r['people'] : '';
    $good = $goodTag && good_pay($r) ? '<span class="tag good">Geras atlygis</span>' : '';
    $event = !empty($r['event_title']) ? '<p class="meta event-name">Renginys: '.e($r['event_title']).'</p>' : '';
    $logo = venue_logo(['id' => $r['venue_id'], 'name' => $r['venue_name'], 'logo' => $r['venue_logo'] ?? null], 'card-logo');
    $pay = '';
    if ($r['pay'] !== '') {
        $parts = pay_parts($r['pay']);
        $pay = $parts ? '<p class="card-pay"><strong>'.e($parts[0]).'</strong> <span>'.e($parts[1]).'</span></p>' : '<p class="card-pay text"><strong>'.e($r['pay']).'</strong></p>';
    }
    $when = open_period($r);
    $first = !empty($r['dates']) ? explode(':', explode(',', $r['dates'])[0])[0] : $r['start_date'];
    if ($when !== period($r)) {
        // Some days are already taken: the card starts from the first day still open.
        $first = array_key_first(request_needs($r)) ?? $first;
    }
    $soon = $r['kind'] !== 'nuolat' ? starts_in($first) : '';
    // Lists of offers get the role photo; cards with the viewer's own actions under them stay compact.
    $photo = $extra === '' ? job_photo($r, 'card-photo', '-sm') : '';
    return '<div class="card job-card'.($photo !== '' ? ' has-photo' : '').'"><a class="card-link" href="/darbas/'.(int) $r['id'].'">'.$photo
        .'<div class="card-head">'.$logo.'<div><h3>'.e(ROLES[$r['role']] ?? $r['role']).'</h3>'
        .$event.'<p class="meta">'.e($r['venue_name']).' · '.e(CITIES[$r['city']] ?? '').$people.'</p></div></div>'
        .$pay
        .'<p class="tags">'.kind_tag($r['kind']).$good.'</p>'
        .'<div class="card-when"><div><strong>'.e(mb_strtoupper(mb_substr($when, 0, 1)).mb_substr($when, 1)).'</strong>'
        .($r['hours'] !== '' ? '<span class="meta">'.e($r['hours']).'</span>' : '')
        .($soon !== '' ? '<span class="starts">'.e($soon).'</span>' : '').'</div></div></a>'.$extra.'</div>';
}
