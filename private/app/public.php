<?php

declare(strict_types=1);

// Home page "cameras": photos processed by tools/cctv.sh into public_html/img/cam-NN.jpg.
// A camera without a photo yet shows a "no signal" tile instead.
const CAMS = [
    '01' => ['Baras', 'Barmenai, baristos, someljė, baro vadovai.'],
    '02' => ['Virtuvė', 'Šefai, virėjai, indų plovėjai, pagalbiniai darbuotojai.'],
    '03' => ['Staliukai', 'Padavėjai, administratoriai, salės vadovai, papildomos rankos renginiams.'],
];

function cam_photo(string $n): string
{
    return is_file(__DIR__.'/../../public_html/img/cam-'.$n.'.jpg') ? '/img/cam-'.$n : '';
}

function home_page(): never
{
    // Before launch there are no real listings yet, and job pages lead to the waitlist.
    $urgent = prelaunch() ? [] : open_requests(['kind' => 'skubu'], 6);
    $latest = $urgent ?: (prelaunch() ? [] : open_requests([], 6));
    $jobs = prelaunch() ? '/laukiantieji' : '/darbai';
    $jobsLabel = prelaunch() ? 'Stoti į laukiančiųjų sąrašą →' : 'Peržiūrėti pasiūlymus →';
    $listTitle = $urgent ? 'Skubiai reikia' : 'Naujausi darbo pasiūlymai';
    $list = $latest ? '<section class="wrap home-block"><div class="section-head"><h2>'.$listTitle.'</h2><a href="/darbai">Visi darbo pasiūlymai →</a></div><div class="cards">'.implode('', array_map('request_card', $latest)).'</div></section>' : '';
    $workers = (int) sql("SELECT COUNT(*) FROM users WHERE kind = 'worker' AND blocked = 0")->fetchColumn();
    $proof = $workers >= 50 ? '<li><strong>'.$workers.'</strong> registruotų darbuotojų</li>' : '';

    // Hero: every camera that has a photo, cross-faded by the script below.
    $slides = $tabs = '';
    $preload = '';
    foreach (CAMS as $n => [$label]) {
        $src = cam_photo($n);
        if ($src === '') {
            continue;
        }
        $first = $slides === '';
        $slides .= '<picture class="cam'.($first ? ' on' : '').'" data-cam="'.$n.'"><source media="(max-width: 720px)" srcset="'.$src.'-sm.jpg">'
            .'<img src="'.$src.'.jpg" alt="" width="1600" height="1200"'.($first ? ' fetchpriority="high"' : ' loading="lazy"').'></picture>';
        $tabs .= '<button type="button" class="'.($first ? 'on' : '').'" data-cam="'.$n.'">CAM '.$n.' · '.e($label).'</button>';
        if ($first) {
            $preload = '<link rel="preload" as="image" href="'.$src.'-sm.jpg" media="(max-width: 720px)"><link rel="preload" as="image" href="'.$src.'.jpg" media="(min-width: 721px)">';
        }
    }
    $tabs = substr_count($tabs, '<button') > 1 ? '<div class="cam-tabs">'.$tabs.'</div>' : '';

    $tiles = '';
    foreach (CAMS as $n => [$label, $roles]) {
        $src = cam_photo($n);
        // Photos carry their own burned-in camera label.
        $media = $src !== '' ? '<img src="'.$src.'-sm.jpg" alt="" loading="lazy" width="800" height="600">' : '<span class="no-signal">Vaizdas netrukus</span><span class="cam-label">CAM '.$n.'</span>';
        $tiles .= '<a class="cam-tile" href="'.$jobs.'"><div class="cam-media">'.$media.'</div>'
            .'<strong>'.e($label).'</strong><span>'.e($roles).'</span></a>';
    }

    page('', <<<HTML
<section class="hero home-hero">
  <div class="cams">$slides</div>
  <div class="hero-shade"></div>
  <div class="grain" aria-hidden="true"></div>
  <p class="rec" aria-hidden="true"><i></i>REC</p>
  <div class="wrap hero-body">
    <p class="eyebrow">Darbas barams, restoranams ir kavinėms</p>
    <h1>FOH &amp; BOH <span class="hl">Patikimi žmonės šiandien</span></h1>
    <p class="lead">GANG padeda įstaigoms greitai rasti darbuotojų pavadavimui, sezonui ar nuolatiniam darbui, o hospitality specialistams – papildomų pajamų ir darbo, kuriame galima save išreikšti.</p>
    <p class="cta"><a class="btn" href="/prisijungti?kaip=darbuotojas">Ieškau darbo</a><a class="btn light" href="/prisijungti?kaip=vadovas">Ieškau darbuotojų</a></p>
    <ul class="hero-facts"><li><strong>Nemokamai</strong> darbuotojams ir įstaigoms</li><li><strong>4 miestai</strong> Vilnius, Kaunas, Klaipėda, Palanga</li>$proof</ul>
  </div>
  $tabs
</section>


<section class="wrap home-block">
  <div class="audience">
    <div class="aud worker">
      <p class="eyebrow">Darbuotojams</p>
      <h2>Dirbkite tada, kai jums patogu</h2>
      <ul>
        <li><strong>Papildomos pajamos</strong>Kalendoriuje pažymite laisvas dienas, o tinkami pasiūlymai ateina el. paštu.</li>
        <li><strong>Profesinė reputacija</strong>Kiekvienas GANG kontraktas ir įvertinimas stiprina jūsų profilį.</li>
        <li><strong>Jūsų pasirinkimas</strong>Patys sprendžiate, kur, kada ir su kokia komanda dirbti.</li>
      </ul>
      <a class="btn" href="/prisijungti?kaip=darbuotojas">Susikurti profilį</a>
    </div>
    <div class="aud venue">
      <p class="eyebrow">Įstaigoms</p>
      <h2>Darbuotojas reikiamą dieną</h2>
      <ul>
        <li><strong>Skubus pavadavimas</strong>Paskelbtas poreikis iš karto pasiekia laisvus darbuotojus jūsų mieste.</li>
        <li><strong>Matote, ką kviečiate</strong>Kandidato nuotrauka, patirtis, įvertinimai ir laisvos dienos vienoje vietoje.</li>
        <li><strong>Salė ir virtuvė</strong>Nuo barmeno iki indų plovėjo – visos komandos pareigos.</li>
      </ul>
      <a class="btn light" href="/prisijungti?kaip=vadovas">Užregistruoti įstaigą</a>
    </div>
  </div>
</section>

$list

<section class="wrap home-block">
  <div class="section-head"><h2>Visa komanda vienoje vietoje</h2><a href="$jobs">$jobsLabel</a></div>
  <div class="cam-tiles">$tiles</div>
</section>

<section class="wrap home-block">
  <div class="section-head"><h2>Kaip veikia</h2><a href="/kaip-veikia">Daugiau →</a></div>
  <ol class="steps">
    <li><strong>Vadovas paskelbia poreikį</strong>Pavyzdžiui, „virėjas rytoj, 10–22 val.“ arba „padavėjai vasaros sezonui“.</li>
    <li><strong>Tinkami darbuotojai gauna pranešimą</strong>ir pateikia kandidatūrą vienu paspaudimu.</li>
    <li><strong>Vadovas patvirtina kandidatą</strong>Sudaromas GANG kontraktas: abi šalys gauna viena kitos kontaktus.</li>
    <li><strong>Abi šalys įvertina viena kitą</strong>Įvertinimai paskelbiami tik tada, kai įvertina abi šalys arba praėjus 7 dienoms.</li>
  </ol>
</section>

<section class="wrap home-block">
  <div class="final-cta">
    <h2>Prisijunkite prie GANG</h2>
    <p>Registracija trunka kelias minutes. Šiuo metu GANG paslaugos nemokamos.</p>
    <p class="cta"><a class="btn dark" href="/prisijungti?kaip=darbuotojas">Ieškau darbo</a><a class="btn dark ghost" href="/prisijungti?kaip=vadovas">Ieškau darbuotojų</a></p>
  </div>
</section>
<script>
(function () {
  var cams = document.querySelectorAll('.home-hero .cam'), tabs = document.querySelectorAll('.cam-tabs button'), i = 0, timer;
  if (cams.length < 2) return;
  function show(n) {
    i = (n + cams.length) % cams.length;
    cams.forEach(function (c, k) { c.classList.toggle('on', k === i); });
    tabs.forEach(function (t, k) { t.classList.toggle('on', k === i); });
  }
  tabs.forEach(function (t, k) { t.addEventListener('click', function () { clearInterval(timer); show(k); }); });
  if (!matchMedia('(prefers-reduced-motion: reduce)').matches) timer = setInterval(function () { show(i + 1); }, 7000);
})();
</script>
HTML, ['canonical' => '/', 'head' => $preload]);
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
  <p>Užregistruokite įstaigą ir paskelbkite poreikį: nurodykite, kokio darbuotojo reikia, kokios patirties tikitės, kada ir kokiomis valandomis. Visi darbuotojai, kurių profilyje pažymėtos atitinkamos pareigos ir jūsų miestas bei kurių patirtis atitinka jūsų reikalavimą, iš karto gauna pranešimą el. paštu. Kandidatus matote savo paskyroje kartu su jų nuotrauka, patirtimi, įvertinimais ir laisvomis dienomis. Patvirtinę kandidatą, gaunate jo telefono numerį.</p>
  <h2>Darbuotojams</h2>
  <p>Susikurkite profilį, įkelkite nuotrauką, pažymėkite pareigas, miestus ir nurodykite darbo patirtį. Kalendoriuje pažymėkite dienas per artimiausius šešis mėnesius, kuriomis galite priimti darbo pasiūlymus: įstaigų vadovai matys, kad tomis dienomis esate laisvi. Pateikite kandidatūrą jums tinkantiems pasiūlymams. Vadovui patvirtinus jūsų kandidatūrą, gausite jo kontaktus ir įstaigos adresą.</p>
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

function about_page(): never
{
    page('Apie GANG', <<<HTML
<section class="wrap narrow prose">
  <h1>Apie GANG</h1>
  <p class="lead">GANG sukūrė žmonės, kurie patys dirba barų, restoranų ir kavinių versle ir kasdien susiduria su tais pačiais iššūkiais kaip ir jūs.</p>
  <h2>Problema, kurią gerai pažįstame</h2>
  <ul>
    <li><strong>Skubus pavadavimas.</strong> Darbuotojas suserga likus kelioms valandoms iki pamainos, ir vadovui tenka skambinti visiems pažįstamiems arba pačiam stoti už baro ar prie viryklės.</li>
    <li><strong>Sezono ir renginių įkarštis.</strong> Vasarą, per šventes ir didelius renginius papildomų rankų reikia čia ir dabar, tačiau patikimų žmonių trumpam laikotarpiui rasti sunku.</li>
    <li><strong>Darbuotojų trūkumas.</strong> Trūksta ne tik padavėjų ir barmenų, bet ir virėjų, indų plovėjų bei kitų virtuvės darbuotojų, be kurių įstaiga negali veikti.</li>
    <li><strong>Patikimumas.</strong> Skelbimų portaluose ir socialinių tinklų grupėse sunku įvertinti, ar žmogus tikrai atvyks ir gerai atliks savo darbą.</li>
    <li><strong>Brangūs tarpininkai.</strong> Įdarbinimo agentūros už kiekvieną darbo valandą ima papildomą mokestį, todėl trumpalaikė pagalba tampa brangi.</li>
  </ul>
  <h2>Kaip ją sprendžiame</h2>
  <p>GANG tiesiogiai sujungia įstaigas ir hospitality specialistus. Įstaiga paskelbia poreikį, ir apie jį iš karto sužino tinkamas pareigas, miestą bei patirtį nurodę darbuotojai. Darbuotojai kalendoriuje pažymi dienas, kuriomis gali dirbti, todėl vadovas iš karto mato, kas yra laisvas.</p>
  <p>Pasitikėjimą kuria abipusiai įvertinimai: juos palikti gali tik tie, kurie iš tiesų dirbo kartu. Darbo sąlygas įstaiga ir darbuotojas suderina tiesiogiai, be tarpininkų ir valandinių mokesčių.</p>
  <p>Tikime, kad darbas hospitality sektoriuje yra ne tik papildomos pajamos, bet ir profesija bei saviraiška. Norime, kad šis darbas būtų vertinamas taip, kaip jis to nusipelno.</p>
  <h2>Apie įkūrėją</h2>
  <p>„HoReCa sektoriuje dirbu nuo 2008 metų. Teko dirbti pagalbiniu virtuvės darbuotoju, o šiandien pats vadovauju savo verslui. Puikiai žinau, kaip atrodo pamaina be reikiamo žmogaus ir iš salės, ir iš virtuvės pusės. GANG kuriu tam, kad patikimą komandos narį būtų galima rasti greitai, o geri specialistai lengvai rastų jiems tinkamą darbą.“</p>
  <p><strong>Vilius</strong>, GANG įkūrėjas</p>
</section>
HTML, ['canonical' => '/apie']);
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
    $paths = ['/', '/darbai', '/kaip-veikia', '/apie', '/privatumas'];
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
