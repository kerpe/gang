<?php

declare(strict_types=1);

function home_page(): never
{
    $urgent = open_requests(['kind' => 'skubu'], 6);
    $latest = $urgent ?: open_requests([], 6);
    $cards = $latest ? '<div class="cards">'.implode('', array_map('request_card', $latest)).'</div><p><a href="/darbai">Visi darbai →</a></p>' : '';
    $listTitle = $urgent ? 'Skubiai reikia žmonių' : 'Naujausi darbai';
    $list = $cards !== '' ? "<section class=\"wrap\"><h2>$listTitle</h2>$cards</section>" : '';
    $workers = (int) sql("SELECT COUNT(*) FROM users WHERE kind = 'worker' AND blocked = 0")->fetchColumn();
    $proof = $workers >= 50 ? "<p class=\"proof\">Jau $workers darbuotojų GANG'e</p>" : '';
    page('', <<<HTML
<section class="hero"><div class="wrap">
  <h1>Susirgo virėjas? Reikia rankų šventėms?</h1>
  <p class="lead">GANG greitai suveda barus, restoranus ir kavines su patikimais žmonėmis: pavadavimui dienai ar mėnesiui, sezonui arba nuolatiniam darbui.</p>
  <div class="split">
    <a class="side" href="/prisijungti?kaip=vadovas"><strong>Ieškau žmonių</strong><span>Įstaigų vadovams: paskelbkite poreikį ir laisvi žmonės gaus pranešimą iškart.</span><em>Registruoti įstaigą →</em></a>
    <a class="side" href="/prisijungti?kaip=darbuotojas"><strong>Ieškau darbo</strong><span>Barmenams, padavėjams, virėjams ir kitiems: pažymėkite, kada laisvi, ir gaukite pasiūlymus.</span><em>Susikurti profilį →</em></a>
  </div>
  $proof
</div></section>
$list
<section class="wrap how">
  <h2>Kaip veikia</h2>
  <ol class="steps">
    <li><strong>Vadovas paskelbia poreikį.</strong> Pvz., „virėjas rytoj, 10–22 val.“ ar „padavėjai vasaros sezonui“.</li>
    <li><strong>Tinkami darbuotojai gauna laišką</strong> ir atsiliepia vienu paspaudimu.</li>
    <li><strong>Vadovas patvirtina žmogų.</strong> Tai GANG kontraktas: abu gauna vienas kito kontaktus.</li>
    <li><strong>Po darbo abu įvertina vienas kitą.</strong> Taip kaupiasi patikimumo istorija.</li>
  </ol>
  <p><a href="/kaip-veikia">Daugiau apie įvertinimus ir taisykles →</a></p>
</section>
<section class="wrap cities"><p>Veikiame: <strong>Vilniuje, Kaune, Klaipėdoje ir Palangoje</strong>. Šiuo metu GANG nemokamas.</p></section>
HTML, ['canonical' => '/']);
}

function jobs_page(): never
{
    $f = [
        'city' => isset(CITIES[$_GET['miestas'] ?? '']) ? $_GET['miestas'] : '',
        'role' => isset(ROLES[$_GET['pareigos'] ?? '']) ? $_GET['pareigos'] : '',
        'kind' => isset(KINDS[$_GET['tipas'] ?? '']) ? $_GET['tipas'] : '',
    ];
    $jobs = open_requests($f);
    $count = count($jobs);
    $cards = $count ? '<div class="cards">'.implode('', array_map('request_card', $jobs)).'</div>'
        : '<p class="empty">Pagal šiuos filtrus darbų dabar nėra. Susikurkite profilį ir gausite laišką, kai atsiras. <a href="/prisijungti?kaip=darbuotojas">Susikurti profilį</a></p>';
    $cities = options(['' => 'Visi miestai'] + CITIES, $f['city']);
    $roles = options(['' => 'Visos pareigos'] + ROLES, $f['role']);
    $kinds = options(['' => 'Visi tipai'] + KINDS, $f['kind']);
    $cityName = $f['city'] ? ' '.CITIES[$f['city']].'e' : '';
    page('Darbas baruose, restoranuose ir kavinėse'.($f['city'] ? ' – '.CITIES[$f['city']] : ''), <<<HTML
<section class="wrap list-page">
  <h1>Darbai{$cityName}</h1>
  <form class="filters" method="get" action="/darbai">
    <select name="miestas" aria-label="Miestas">$cities</select>
    <select name="pareigos" aria-label="Pareigos">$roles</select>
    <select name="tipas" aria-label="Tipas">$kinds</select>
    <button class="btn">Rodyti</button>
  </form>
  <p class="meta">Rasta: $count</p>
  $cards
</section>
HTML, ['canonical' => '/darbai'.($f['city'] ? '?miestas='.$f['city'] : '')]);
}

function how_page(): never
{
    $days = REVIEW_HIDE_DAYS;
    page('Kaip veikia GANG', <<<HTML
<section class="wrap narrow prose">
  <h1>Kaip veikia GANG</h1>
  <h2>Įstaigoms</h2>
  <p>Užregistruokite įstaigą ir paskelbkite poreikį: kokio žmogaus reikia, kada ir kokiomis valandomis. Visi darbuotojai, kurių profilyje pažymėtos tos pareigos ir jūsų miestas, iškart gauna laišką. Atsiliepusius matote savo paskyroje kartu su jų įvertinimais ir patirtimi. Patvirtinę žmogų, gaunate jo telefoną.</p>
  <h2>Darbuotojams</h2>
  <p>Susikurkite profilį ir pažymėkite pareigas bei miestus. Kai būsite laisvi, paspauskite „Laisvas“ – vadovai matys, kad galite padėti. Atsiliepkite į jums tinkančius darbus, o kai vadovas jus patvirtins, gausite jo kontaktus ir įstaigos adresą.</p>
  <h2>GANG kontraktas</h2>
  <p>Kontraktas atsiranda, kai vadovas patvirtina atsiliepusį darbuotoją. GANG tik suveda žmones: darbo sutartį, atlygį ir sąlygas įstaiga ir darbuotojas sutaria tarpusavyje.</p>
  <h2>Įvertinimai</h2>
  <ul>
    <li>Įvertinti galima tik po GANG kontrakto – tik tie, kurie tikrai dirbo kartu.</li>
    <li>Vadovas vertina darbuotoją, darbuotojas – įstaigą (1–5 žvaigždutės ir komentaras).</li>
    <li>Įvertinimas tampa matomas, kai įvertina abu arba praėjus $days dienoms. Taip niekas negali „atsikeršyti“ už jau matomą įvertinimą.</li>
    <li>Įvertinimo autorius nerodomas: matosi tik miestas ir laikas.</li>
    <li>Darbuotojų įvertinimus mato tik prisijungę vadovai, įstaigų – prisijungę darbuotojai.</li>
  </ul>
</section>
HTML, ['canonical' => '/kaip-veikia']);
}

function privacy_page(): never
{
    // Draft text: to be reviewed before launch.
    page('Privatumas ir taisyklės', <<<HTML
<section class="wrap narrow prose">
  <h1>Privatumas ir taisyklės</h1>
  <p><em>Juodraštis – bus patikslinta prieš oficialų paleidimą.</em></p>
  <h2>Kokius duomenis renkame</h2>
  <p>El. paštą, vardą, telefoną, pareigas, miestus ir jūsų įrašytą informaciją apie save ar įstaigą. Telefoną kitai pusei parodome tik po GANG kontrakto.</p>
  <h2>Kam naudojame</h2>
  <p>Kad suvestume įstaigas su darbuotojais: siunčiame pranešimus apie tinkamus darbus ir atsiliepimus, rodome įvertinimus. Duomenų neparduodame ir reklamai neperduodame.</p>
  <h2>Įvertinimai</h2>
  <p>Įvertinimai rodomi be autoriaus vardo. Galite atsakyti į neteisingą įvertinimą parašę mums – netinkamus, įžeidžiančius ar asmeninę informaciją atskleidžiančius komentarus pašaliname.</p>
  <h2>Paskyros ištrynimas</h2>
  <p>Norėdami ištrinti paskyrą ir duomenis, parašykite mums iš savo el. pašto. Ištrinsime per 30 dienų.</p>
  <h2>Taisyklės</h2>
  <p>Skelbiami tik tikri darbo pasiūlymai hospitality įstaigose. Draudžiama diskriminacija, klaidinanti informacija ir piktnaudžiavimas įvertinimais. Pažeidėjų paskyras blokuojame.</p>
</section>
HTML, ['canonical' => '/privatumas']);
}

function sitemap(): never
{
    header('Content-Type: application/xml; charset=utf-8');
    $out = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $paths = ['/', '/darbai', '/kaip-veikia', '/privatumas'];
    foreach (array_keys(CITIES) as $c) {
        $paths[] = '/darbai?miestas='.$c;
    }
    foreach (open_requests([], 1000) as $r) {
        $paths[] = '/darbas/'.$r['id'];
    }
    foreach ($paths as $p) {
        $out .= '<url><loc>'.e(url($p)).'</loc></url>';
    }
    echo $out.'</urlset>';
    exit;
}
