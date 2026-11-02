<?php

declare(strict_types=1);

function page(string $title, string $body, array $opt = []): never
{
    $e = 'e';
    $desc = $opt['description'] ?? 'GANG – platforma, padedanti barams, restoranams ir kavinėms greitai rasti patikimus darbuotojus skubiam pavadavimui, sezonui ar nuolatiniam darbui.';
    $fullTitle = $title === '' ? 'GANG – darbas hospitality komandoms' : $title.' · GANG';
    $robots = !empty($opt['noindex']) ? '<meta name="robots" content="noindex">' : '';
    $canonical = isset($opt['canonical']) ? '<link rel="canonical" href="'.e(url($opt['canonical'])).'">' : '';
    $head = $opt['head'] ?? '';
    $user = current_user();
    if (prelaunch()) {
        $nav = '<a class="hide-sm" href="/kaip-veikia">Kaip veikia</a><a class="hide-sm" href="/apie">Apie</a>'
            .($user ? '<a class="btn small" href="/mano">Mano GANG</a>' : '<a class="btn small ghost hide-sm" href="/prisijungti">Prisijungti</a><a class="btn small" href="/registracija">Registruotis</a>');
    } elseif ($user) {
        $nav = '<a class="hide-sm" href="/darbai">Darbai</a><a class="hide-sm" href="/kaip-veikia">Kaip veikia</a><a class="hide-sm" href="/apie">Apie</a><a class="btn small" href="/mano">Mano GANG</a>';
    } else {
        $nav = '<a class="hide-sm" href="/darbai">Darbai</a><a class="hide-sm" href="/kaip-veikia">Kaip veikia</a><a class="hide-sm" href="/apie">Apie</a><a class="btn small ghost" href="/prisijungti">Prisijungti</a><a class="btn small hide-sm" href="/prisijungti">Registruotis</a>';
    }
    // Phones: a guest menu behind one button; signed-in members get a bottom tab bar.
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $menuLinks = prelaunch()
        ? '<a href="/kaip-veikia">Kaip veikia</a><a href="/apie">Apie GANG</a>'.($user ? '' : '<a href="/prisijungti">Prisijungti</a>')
        : '<a href="/darbai">Darbai</a><a href="/kaip-veikia">Kaip veikia</a><a href="/apie">Apie GANG</a>'.($user ? '' : '<a href="/prisijungti">Registruotis</a>');
    $nav .= '<details class="mnav"><summary aria-label="Meniu"><span></span><span></span><span></span></summary><div class="mnav-links">'.$menuLinks.'</div></details>';
    $tabbar = '';
    if ($user && !prelaunch() && profile_complete($user)) {
        $tabs = $user['kind'] === 'worker'
            ? [['/mano', 'Mano GANG', 'home'], ['/darbai', 'Darbai', 'list'], ['/mano#laisvumas', 'Laisvos dienos', 'cal'], ['/profilis', 'Profilis', 'user']]
            : [['/mano', 'Mano GANG', 'home'], ['/poreikis/naujas', 'Nauja paieška', 'plus'], ['/darbai', 'Darbai', 'list'], ['/profilis', 'Profilis', 'user']];
        $icons = [
            'home' => '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
            'list' => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
            'cal' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
            'plus' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        ];
        $tabbar = '<nav class="tabbar" aria-label="Pagrindinis meniu">';
        foreach ($tabs as [$href, $label, $icon]) {
            $current = $href === $path ? ' aria-current="page"' : '';
            $tabbar .= '<a href="'.$href.'"'.$current.($icon === 'plus' ? ' class="accent"' : '').'><svg viewBox="0 0 24 24" aria-hidden="true">'.$icons[$icon].'</svg>'.e($label).'</a>';
        }
        $tabbar .= '</nav>';
    }
    $bodyClass = $tabbar ? ' class="has-tabbar"' : '';
    $flash = flash();
    $flash = $flash !== '' ? '<div class="wrap"><div class="notice ok flash">'.e($flash).'</div></div>' : '';
    $year = date('Y');
    $logoMark = knives();
    $jobsLink = prelaunch() ? '' : ' · <a href="/darbai">Darbai</a>';
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
<meta name="theme-color" content="#171717">
$robots$canonical
<link rel="preload" href="/fonts/anton-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/style.css?v=29">
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
<footer class="foot"><div class="wrap">
  <p><strong>GANG.lt</strong> – patikimi darbuotojai barams, restoranams ir kavinėms. Vilnius, Kaunas, Klaipėda, Palanga.</p>
  <p><a href="/kaip-veikia">Kaip veikia</a> · <a href="/apie">Apie GANG</a>$jobsLink · <a href="/privatumas">Privatumas ir taisyklės</a> · © $year</p>
</div></footer>
$tabbar
<script>
// A field's error disappears as soon as the field is changed.
document.addEventListener('input', clearErr); document.addEventListener('change', clearErr);
function clearErr(e) { var box = e.target.closest && (e.target.closest('fieldset') || e.target.closest('label')); if (box && box.closest('form.form')) box.querySelectorAll('.err').forEach(function (x) { x.remove(); }); }
</script>
</body>
</html>
HTML;
    exit;
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
    return '<svg class="'.$class.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
        .'<mask id="gk-m" maskUnits="userSpaceOnUse" x="0" y="0" width="24" height="24"><rect width="24" height="24" fill="#fff"/><g '.$b.' fill="#000" stroke="#000" stroke-width="2.2" stroke-linejoin="round">'.$k.'</g></mask>'
        .'<g mask="url(#gk-m)"><g transform="rotate(45 12 12)">'.$k.'</g></g><g '.$b.'>'.$k.'</g></svg>';
}

function stars(?float $avg, int $count): string
{
    if ($count === 0 || $avg === null) {
        return '<span class="stars none">Įvertinimų dar nėra</span>';
    }
    return '<span class="stars" title="'.number_format($avg, 1, ',', '').' iš 5">'.knives().' '.number_format($avg, 1, ',', '').' <small>('.$count.')</small></span>';
}

function request_card(array $r, string $extra = ''): string
{
    $badge = $r['kind'] === 'skubu' ? '<span class="tag urgent">Skubu</span>' : '<span class="tag">'.e(KINDS[$r['kind']] ?? '').'</span>';
    $pay = $r['pay'] !== '' ? '<span class="tag pay">'.e($r['pay']).'</span>' : '';
    $people = (int) $r['people'] > 1 ? ' · reikia darbuotojų: '.(int) $r['people'] : '';
    return '<div class="card"><a class="card-link" href="/darbas/'.(int) $r['id'].'"><h3>'.e(ROLES[$r['role']] ?? $r['role']).'</h3>'
        .'<p class="meta">'.e($r['venue_name']).' · '.e(CITIES[$r['city']] ?? '').$people.'</p>'
        .'<p class="meta">'.e(period($r)).($r['hours'] !== '' ? ' · '.e($r['hours']) : '').'</p>'
        .'<p class="tags">'.$badge.$pay.'</p></a>'.$extra.'</div>';
}
