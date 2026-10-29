<?php

declare(strict_types=1);

function home_page(): never
{
    $urgent = open_requests(['kind' => 'skubu'], 6);
    $latest = $urgent ?: open_requests([], 6);
    $listTitle = $urgent ? 'Skubiai reikia' : 'Naujausi darbai';
    $list = $latest ? '<div class="section-head"><h2>'.$listTitle.'</h2><a href="/darbai">Visi darbo pasiūlymai →</a></div><div class="cards">'.implode('', array_map('request_card', $latest)).'</div>' : '';
    $workers = (int) sql("SELECT COUNT(*) FROM users WHERE kind = 'worker' AND blocked = 0")->fetchColumn();
    $proof = $workers >= 50 ? "<p class=\"proof\">GANG jau užsiregistravo darbuotojų: $workers</p>" : '';
    // Drop a photo at public_html/img/hero.jpg to replace the warm light gradient.
    $photo = is_file(__DIR__.'/../../public_html/img/hero.jpg') ? ' style="background-image: linear-gradient(transparent, transparent), url(/img/hero.jpg)"' : '';
    $cards = '';
    foreach ([
        ['/darbai', 'Darbai', 'Pamainos, sezoninis ir nuolatinis darbas.', '<path d="M4 8h16v11H4zM9 8V5h6v3M4 13h16"/>'],
        ['/prisijungti?kaip=vadovas', 'Įstaigoms', 'Patikimi darbuotojai per kelias minutes.', '<path d="M7 3h10l-5 8zM12 11v8M8 21h8"/>'],
        ['/prisijungti?kaip=darbuotojas', 'Darbuotojams', 'Pažymėkite laisvas dienas ir gaukite pasiūlymus.', '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5M16 4.8a3.3 3.3 0 0 1 0 6.4M18 14.8c1.8.7 3 2.4 3.5 5.2"/>'],
        ['/kaip-veikia', 'Kaip veikia', 'GANG kontraktai ir įvertinimai.', '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>'],
    ] as [$href, $title, $text, $icon]) {
        $cards .= '<a class="navcard" href="'.$href.'"><svg viewBox="0 0 24 24" aria-hidden="true">'.$icon.'</svg><strong>'.$title.'</strong><span>'.$text.'</span><em class="arrow" aria-hidden="true">→</em></a>';
    }
    page('', <<<HTML
<section class="hero"><div class="hero-media"$photo></div><div class="wrap">
  <p class="eyebrow">Hospitality komandoms</p>
  <h1>Susirgo virėjas? <span class="hl">Pakaitinį darbuotoją rasite šiandien</span></h1>
  <p class="lead">GANG padeda barams, restoranams ir kavinėms rasti patikimus darbuotojus: pavadavimui dienai ar mėnesiui, sezonui arba nuolatiniam darbui.</p>
  <p class="cta"><a class="btn" href="/prisijungti">Prisijungti prie GANG →</a><a class="text-link" href="/kaip-veikia">Kaip tai veikia</a></p>
  $proof
</div></section>
<section class="wrap">
  <nav class="navcards" aria-label="Skiltys">$cards</nav>
  $list
  <div class="section-head"><h2>Kaip veikia</h2><a href="/kaip-veikia">Daugiau →</a></div>
  <ol class="steps">
    <li><strong>Vadovas paskelbia poreikį</strong>Pavyzdžiui, „virėjas rytoj, 10–22 val.“ arba „padavėjai vasaros sezonui“.</li>
    <li><strong>Tinkami darbuotojai gauna pranešimą</strong>ir pateikia kandidatūrą vienu paspaudimu.</li>
    <li><strong>Vadovas patvirtina kandidatą</strong>Sudaromas GANG kontraktas: abi šalys gauna viena kitos kontaktus.</li>
    <li><strong>Abi šalys įvertina viena kitą</strong>Taip kaupiama patikimumo istorija.</li>
  </ol>
  <p class="cities">Veikiame <strong>Vilniuje, Kaune, Klaipėdoje ir Palangoje</strong>. Šiuo metu GANG paslaugos nemokamos.</p>
</section>
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
        : '<p class="empty">Pagal pasirinktus filtrus darbo pasiūlymų šiuo metu nėra. Susikūrę profilį, apie naujus pasiūlymus gausite pranešimą el. paštu. <a href="/prisijungti?kaip=darbuotojas">Susikurti profilį</a></p>';
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
  <p>Užregistruokite įstaigą ir paskelbkite poreikį: nurodykite, kokio darbuotojo reikia, kada ir kokiomis valandomis. Visi darbuotojai, kurių profilyje pažymėtos atitinkamos pareigos ir jūsų miestas, iš karto gauna pranešimą el. paštu. Kandidatus matote savo paskyroje kartu su jų nuotrauka, patirtimi, įvertinimais ir laisvomis dienomis. Patvirtinę kandidatą, gaunate jo telefono numerį.</p>
  <h2>Darbuotojams</h2>
  <p>Susikurkite profilį, įkelkite nuotrauką ir pažymėkite pareigas bei miestus. Kalendoriuje pažymėkite dienas per artimiausius šešis mėnesius, kuriomis galite priimti darbo pasiūlymus: įstaigų vadovai matys, kad tomis dienomis esate laisvi. Pateikite kandidatūrą jums tinkantiems pasiūlymams. Vadovui patvirtinus jūsų kandidatūrą, gausite jo kontaktus ir įstaigos adresą.</p>
  <h2>GANG kontraktas</h2>
  <p>GANG kontraktas sudaromas, kai įstaigos vadovas patvirtina darbuotojo kandidatūrą. GANG tik padeda šalims susisiekti: darbo sutartį, atlygį ir kitas sąlygas įstaiga ir darbuotojas suderina tiesiogiai.</p>
  <h2>Įvertinimai</h2>
  <ul>
    <li>Įvertinti galima tik po GANG kontrakto, todėl vertina tik tie, kurie iš tiesų dirbo kartu.</li>
    <li>Vadovas vertina darbuotoją, o darbuotojas – įstaigą (nuo 1 iki 5 žvaigždučių ir komentaras).</li>
    <li>Įvertinimas tampa matomas, kai įvertina abi šalys arba praėjus $days dienoms. Taip užtikrinama, kad vienos šalies įvertinimas nedarytų įtakos kitos šalies vertinimui.</li>
    <li>Įvertinimo autorius neskelbiamas: rodomas tik miestas ir data.</li>
    <li>Darbuotojų įvertinimus mato tik prisijungę įstaigų vadovai, o įstaigų įvertinimus – tik prisijungę darbuotojai.</li>
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
  <p><em>Preliminari redakcija. Galutinė redakcija bus paskelbta prieš oficialų svetainės paleidimą.</em></p>
  <h2>Kokius duomenis renkame</h2>
  <p>Renkame el. pašto adresą, vardą ir pavardę, telefono numerį, profilio nuotrauką (jei ją įkeliate), pareigas, miestus, pažymėtas laisvas dienas ir jūsų pateiktą informaciją apie save ar įstaigą. Telefono numeris kitai šaliai rodomas tik po GANG kontrakto. Profilio nuotrauką mato tik prisijungę įstaigų vadovai.</p>
  <h2>Kam naudojame</h2>
  <p>Duomenis naudojame tam, kad padėtume įstaigoms ir darbuotojams rasti vieni kitus: siunčiame pranešimus apie tinkamus darbo pasiūlymus ir kandidatūras, rodome įvertinimus. Duomenų neparduodame ir reklamos tikslais neperduodame.</p>
  <h2>Įvertinimai</h2>
  <p>Įvertinimai skelbiami nenurodant autoriaus. Jei manote, kad įvertinimas neteisingas, susisiekite su mumis. Netinkamus, įžeidžiančius ar asmeninę informaciją atskleidžiančius komentarus pašaliname.</p>
  <h2>Paskyros ištrynimas</h2>
  <p>Norėdami ištrinti paskyrą ir duomenis, parašykite mums iš el. pašto adreso, kuriuo esate užsiregistravę. Duomenis ištrinsime per 30 dienų.</p>
  <h2>Taisyklės</h2>
  <p>Skelbiami tik tikri darbo pasiūlymai svetingumo sektoriaus įstaigose. Draudžiama diskriminacija, klaidinanti informacija ir piktnaudžiavimas įvertinimais. Taisyklių nesilaikančių narių paskyros blokuojamos.</p>
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
