<?php

declare(strict_types=1);

function page(string $title, string $body, array $opt = []): never
{
    $e = 'e';
    $desc = $opt['description'] ?? 'GANG – vieta, kur barai, restoranai ir kavinės greitai randa patikimų žmonių: skubiam pavadavimui, sezonui ar nuolatiniam darbui.';
    $fullTitle = $title === '' ? 'GANG – darbas hospitality komandoms' : $title.' · GANG';
    $robots = !empty($opt['noindex']) ? '<meta name="robots" content="noindex">' : '';
    $canonical = isset($opt['canonical']) ? '<link rel="canonical" href="'.e(url($opt['canonical'])).'">' : '';
    $head = $opt['head'] ?? '';
    $user = current_user();
    if ($user) {
        $nav = '<a class="hide-sm" href="/darbai">Darbai</a><a class="hide-sm" href="/kaip-veikia">Kaip veikia</a><a class="btn small" href="/mano">Mano GANG</a>';
    } else {
        $nav = '<a class="hide-sm" href="/darbai">Darbai</a><a class="hide-sm" href="/kaip-veikia">Kaip veikia</a><a class="btn small ghost" href="/prisijungti">Prisijungti</a><a class="btn small hide-sm" href="/prisijungti">Registruotis</a>';
    }
    $flash = flash();
    $flash = $flash !== '' ? '<div class="wrap"><div class="notice ok flash">'.e($flash).'</div></div>' : '';
    $year = date('Y');
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
<link rel="stylesheet" href="/style.css?v=5">
$head
</head>
<body>
<header class="top"><div class="wrap">
  <a class="logo" href="/">GANG<span>.lt</span></a>
  <nav>$nav</nav>
</div></header>
<main>
$flash
$body
</main>
<footer class="foot"><div class="wrap">
  <p><strong>GANG.lt</strong> – patikimi žmonės barams, restoranams ir kavinėms. Vilnius, Kaunas, Klaipėda, Palanga.</p>
  <p><a href="/kaip-veikia">Kaip veikia</a> · <a href="/darbai">Darbai</a> · <a href="/privatumas">Privatumas ir taisyklės</a> · © $year</p>
</div></footer>
</body>
</html>
HTML;
    exit;
}

function not_found(): never
{
    http_response_code(404);
    page('Puslapis nerastas', '<section class="wrap narrow"><h1>Puslapis nerastas</h1><p>Galbūt skelbimas jau nebegalioja.</p><p><a class="btn" href="/darbai">Visi darbai</a></p></section>', ['noindex' => true]);
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
        $out .= '<label class="choice"><input type="radio" name="'.$name.'" value="'.e($value).'"'.($value === $checked ? ' checked' : '').'> '.e($label).'</label>';
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

function stars(?float $avg, int $count): string
{
    if ($count === 0 || $avg === null) {
        return '<span class="stars none">Įvertinimų dar nėra</span>';
    }
    return '<span class="stars" title="'.number_format($avg, 1, ',', '').' iš 5">★ '.number_format($avg, 1, ',', '').' <small>('.$count.')</small></span>';
}

function request_card(array $r, string $extra = ''): string
{
    $badge = $r['kind'] === 'skubu' ? '<span class="tag urgent">Skubu</span>' : '<span class="tag">'.e(KINDS[$r['kind']] ?? '').'</span>';
    $pay = $r['pay'] !== '' ? '<span class="tag pay">'.e($r['pay']).'</span>' : '';
    $people = (int) $r['people'] > 1 ? ' · reikia '.(int) $r['people'].' žm.' : '';
    return '<div class="card"><a class="card-link" href="/darbas/'.(int) $r['id'].'"><h3>'.e(ROLES[$r['role']] ?? $r['role']).'</h3>'
        .'<p class="meta">'.e($r['venue_name']).' · '.e(CITIES[$r['city']] ?? '').$people.'</p>'
        .'<p class="meta">'.e(period($r)).($r['hours'] !== '' ? ' · '.e($r['hours']) : '').'</p>'
        .'<p class="tags">'.$badge.$pay.'</p></a>'.$extra.'</div>';
}
