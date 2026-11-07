<?php

declare(strict_types=1);

// Home page "cameras": photos processed by tools/cctv.sh into public_html/img/cam-NN.jpg.
// A camera without a photo yet shows a "no signal" tile instead.
const CAMS = [
    '01' => ['Baras', 'Barmenai, baristos, someljė, baro vadovai.'],
    '02' => ['Virtuvė', 'Šefai, virėjai, indų plovėjai, pagalbiniai darbuotojai.'],
    '03' => ['Aptarnavimas', 'Padavėjai, runneriai, administratoriai.'],
];

// Section stories behind the home camera tiles: slug, page heading, intro and roles
const TEAM_PAGES = [
    '01' => [
        'slug' => 'baras',
        'about' => 'Apie barą',
        'title' => 'Darbas bare',
        'heading' => 'Baras',
        'intro' => '<p>Baras – tai vieta, kurioje svečias pirmiausia pajunta, ar jis čia laukiamas. Barmenas vienu metu ruošia gėrimus, palaiko pokalbį ir mato visą salę, o baristas ar someljė savo žiniomis paverčia paprastą užsakymą patirtimi.</p>'
            .'<p>Geram barui neužtenka gerų receptų – jam reikia žmonių, kurie išmano savo amatą ir moka priimti svečią, bet galiausiai barmeno meistriškumą svečias vis tiek pajus taurėje.</p>',
        'roles' => ['Barmenai', 'Baristos', 'Someljė', 'Baro vadovai'],
        'join' => 'Profilyje pažymėkite darbą bare, nurodykite savo patirtį ir laisvas dienas. Apie tinkamas pamainas, sezoninį ir nuolatinį darbą sužinosite pirmieji.',
    ],
    '02' => [
        'slug' => 'virtuve',
        'about' => 'Apie virtuvę',
        'title' => 'Darbas virtuvėje',
        'heading' => 'Virtuvė',
        'intro' => '<p>Virtuvė – restorano širdis. Čia viskas priklauso nuo pasiruošimo, tikslumo ir komandos: kiekvienas žino savo vietą, o pamaina pavyksta tik tada, kai visi dirba kaip vienas.</p>'
            .'<p>Šefas, virėjai, indų plovėjai ir pagalbiniai darbuotojai yra vienodai svarbios grandys. Be bet kurios iš jų lėkštė iki svečio nenukeliauja, todėl GANG padeda virtuvėms rasti žmones, su kuriais pamaina vyksta sklandžiai.</p>',
        'roles' => ['Šefai', 'Virėjai', 'Indų plovėjai', 'Pagalbiniai darbuotojai'],
        'join' => 'Profilyje pažymėkite darbą virtuvėje, nurodykite savo patirtį ir laisvas dienas. Apie tinkamas pamainas, sezoninį ir nuolatinį darbą sužinosite pirmieji.',
    ],
    '03' => [
        'slug' => 'aptarnavimas',
        'about' => 'Apie aptarnavimą',
        'title' => 'Darbas aptarnavime',
        'heading' => 'Aptarnavimas',
        'intro' => '<p>Salėje susitinka maistas, gėrimai ir svečias, o visa komanda kuria vakaro nuotaiką: pasitinka, pataria ir pastebi, ko reikia, dar prieš paprašant.</p>'
            .'<p>Šio darbo esmė – rūpintis kitais žmonėmis ir puoselėti žmogiškumą.</p>'
            .'<p>Būtent čia aptarnavimas tampa svetingumu, o pirmas apsilankymas – priežastimi sugrįžti.</p>',
        'roles' => ['Padavėjai', 'Runneriai', 'Administratoriai', 'Salės vadovai', 'Renginiai'],
        'join' => 'Profilyje pažymėkite darbą salėje, nurodykite savo patirtį ir laisvas dienas. Apie tinkamas pamainas, renginius, sezoninį ir nuolatinį darbą sužinosite pirmieji.',
    ],
];

function cam_photo(string $n): string
{
    return is_file(__DIR__.'/../../public_html/img/cam-'.$n.'.jpg') ? '/img/cam-'.$n : '';
}

// Sign-up buttons for visitors; a signed-in member gets the next step for their own role instead.
function join_cta(string $workerClass = 'btn', string $employerClass = 'btn light'): string
{
    $u = current_user();
    if (!$u) {
        return '<p class="cta"><a class="'.$workerClass.'" href="/prisijungti?kaip=darbuotojas">Ieškau darbo</a><a class="'.$employerClass.'" href="/prisijungti?kaip=vadovas">Ieškau darbuotojų</a></p>';
    }
    if ($u['kind'] === 'manager') {
        return '<p class="cta"><a class="'.$workerClass.'" href="/poreikis/naujas">Paskelbti paiešką</a><a class="'.$employerClass.'" href="/mano">Mano GANG</a></p>';
    }
    return '<p class="cta"><a class="'.$workerClass.'" href="/darbai">Darbo pasiūlymai</a><a class="'.$employerClass.'" href="/mano">Mano GANG</a></p>';
}

function home_page(): never
{
    // Urgent listings come first (open_requests orders them so), the rest fills full rows of three.
    $latest = open_requests([], 6);
    if (count($latest) >= 3) {
        $latest = array_slice($latest, 0, count($latest) >= 6 ? 6 : 3);
    }
    $urgent = $latest && !array_filter($latest, fn ($r) => $r['kind'] !== 'skubu');
    $listTitle = $urgent ? 'Skubiai reikia' : 'Naujausi darbo pasiūlymai';
    $list = $latest ? '<section class="wrap home-block"><div class="section-head"><h2>'.$listTitle.'</h2></div><div class="cards home-cards">'.implode('', array_map('request_card_short', $latest)).'</div></section>' : '';
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
        $tiles .= '<a class="cam-tile" href="/'.TEAM_PAGES[$n]['slug'].'"><div class="cam-media">'.$media.'</div>'
            .'<strong>'.e($label).'</strong><span>'.e($roles).'</span></a>';
    }

    $mark = knives();
    $me = current_user();
    $heroCta = join_cta();
    $workerBtn = !$me ? '<a class="btn" href="/prisijungti?kaip=darbuotojas">Susikurti profilį</a>'
        : ($me['kind'] === 'worker' ? '<a class="btn" href="/profilis">Mano profilis</a>' : '');
    $venueBtn = !$me ? '<a class="btn light" href="/prisijungti?kaip=vadovas">Raskite žmonių savo komandai</a>'
        : ($me['kind'] === 'manager' ? '<a class="btn light" href="/poreikis/naujas">Paskelbti paiešką</a>' : '');
    $finalCta = $me ? '' : '<section class="wrap home-block">
  <div class="final-cta">
    <h2>Prisijunkite prie GANG</h2>
    <p>Registracija trunka kelias minutes ir šiuo metu yra nemokama.</p>
    '.join_cta('btn dark', 'btn dark ghost').'
  </div>
</section>';
    page('', <<<HTML
<section class="hero home-hero">
  <div class="cams">$slides</div>
  <div class="hero-shade"></div>
  <div class="grain" aria-hidden="true"></div>
  <p class="rec" aria-hidden="true"><i></i>REC</p>
  <div class="wrap hero-body">
    <h1>FOH &amp; BOH <span class="hl">Patikimi žmonės šiandien</span></h1>
    <p class="lead">GANG padeda restoranams, kavinėms, barams ir klubams greitai rasti darbuotojų pavadavimui, sezonui ar nuolatiniam darbui, o hospitality specialistams bei entuziastams – papildomų pajamų ir darbo, kuriame galima save išreikšti.</p>
    $heroCta
    <ul class="hero-facts"><li><strong>Salė ir virtuvė</strong> visos komandos pareigos</li><li><strong>6 miestai</strong> Vilnius, Kaunas, Klaipėda, Šiauliai, Panevėžys, Palanga</li>$proof</ul>
  </div>
  $tabs
</section>


<section class="wrap home-block">
  <div class="audience">
    <div class="aud worker">
      <p class="eyebrow">Darbuotojams</p>
      <h2>Dirbkite, kai jums patogu</h2>
      <ul>
        <li><strong>Papildomos pajamos</strong>Kalendoriuje pažymite laisvas dienas, o tinkami pasiūlymai ateina el. paštu.</li>
        <li><strong>Profesinė reputacija</strong>Kiekvienas GANG kontraktas ir įvertinimas stiprina jūsų profilį.</li>
        <li><strong>Jūsų pasirinkimas</strong>Patys sprendžiate, kur, kada ir su kokia komanda dirbti.</li>
      </ul>
      $workerBtn
    </div>
    <div class="aud venue">
      <p class="eyebrow">Darbdaviams</p>
      <h2>Darbuotojų pasiūla</h2>
      <ul>
        <li><strong>Skubus pavadavimas</strong>Paskelbta paieška iš karto pasiekia laisvus darbuotojus jūsų mieste.</li>
        <li><strong>Matote, ką kviečiate</strong>Kandidato patirtis, įvertinimai ir laisvos dienos vienoje vietoje.</li>
        <li><strong>Salė ir virtuvė</strong>Nuo barmeno iki indų plovėjo – visos komandos pareigos.</li>
      </ul>
      $venueBtn
    </div>
  </div>
</section>

<section class="wrap home-block">
  <div class="bento">
    <div class="bt t-2 lime"><span class="k">Darbuotojams</span><span class="big">12 mėn.</span><p>Kalendoriuje pažymėkite laisvas dienas, ir darbdaviai matys, kada galite dirbti.</p></div>
    <div class="bt t-2"><span class="k">Miestai</span><span class="big">6</span><p>Vilnius, Kaunas, Klaipėda, Šiauliai, Panevėžys ir Palanga.</p></div>
    <div class="bt t-2 orange"><span class="k">Įvertinimai</span><span class="big">{$mark}1–5</span><p>Vertina tik tie, kurie iš tiesų dirbo kartu.</p></div>
  </div>
</section>

$list

<section class="wrap home-block">
  <div class="section-head"><h2>Visa komanda vienoje vietoje</h2></div>
  <div class="cam-tiles">$tiles</div>
</section>

<section class="wrap home-block">
  <div class="section-head"><h2>Kaip veikia</h2></div>
  <ol class="steps">
    <li><strong>Darbdavys paskelbia paiešką</strong>Pavyzdžiui, „virėjas rytoj, <span class="nw">10–22 val.</span>“ arba „padavėjai vasaros sezonui“.</li>
    <li><strong>Tinkami darbuotojai gauna pranešimą</strong>ir pateikia kandidatūrą vienu paspaudimu.</li>
    <li><strong>Darbdavys patvirtina kandidatą</strong>Sudaromas GANG kontraktas: abi šalys gauna viena kitos kontaktus.</li>
    <li><strong>Abi šalys įvertina viena kitą</strong>Įvertinimai paskelbiami tik tada, kai įvertina abi šalys arba praėjus 7 dienoms.</li>
  </ol>
</section>

$finalCta
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
        : '<p class="empty">Pagal pasirinktus filtrus darbo pasiūlymų šiuo metu nėra. '.(current_user() ? '' : 'Susikūrę profilį, apie naujus pasiūlymus gausite pranešimą el. paštu. <a href="/prisijungti?kaip=darbuotojas">Susikurti profilį</a>').'</p>';
    $cities = options(['' => 'Visi miestai'] + CITIES, $f['city']);
    $roles = options(['' => 'Visos pareigos'] + ROLES, $f['role']);
    $kinds = options(['' => 'Visi tipai'] + KINDS, $f['kind']);
    $cityName = $f['city'] ? ' '.CITIES_IN[$f['city']] : '';
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

// Two-column text page: sticky heading, CCTV photo and section index on the left,
// numbered sections on the right. $sections is [[id, heading, html], ...].
// With $numbered = false the sections carry no numbers, headings or side index (Baras, Virtuvė, Salė).
function split_page(string $title, string $eyebrow, string $heading, string $cam, array $sections, string $canonical, bool $numbered = true): never
{
    $index = $body = '';
    foreach ($sections as $i => [$id, $h, $html]) {
        $n = sprintf('%02d', $i + 1);
        if (!$numbered) {
            $body .= '<section class="sec plain" id="'.$id.'"><div>'.$html.'</div></section>';
            continue;
        }
        $index .= '<li><a href="#'.$id.'">'.$n.' · '.e($h).'</a></li>';
        $body .= '<section class="sec" id="'.$id.'"><span class="num" aria-hidden="true">'.$n.'</span><div><h2>'.e($h).'</h2>'.$html.'</div></section>';
    }
    $index = $index !== '' ? '<ol class="side-index">'.$index.'</ol>' : '';
    $photo = cam_photo($cam);
    $photo = $photo !== '' ? '<div class="side-cam"><img src="'.$photo.'-sm.jpg" alt="" width="800" height="600"></div>' : '';
    page($title, '<div class="wrap split"><aside class="side">'.($eyebrow !== '' ? '<p class="eyebrow">'.e($eyebrow).'</p>' : '').'<h1>'.e($heading).'</h1>'.$photo
        .$index.'</aside><div class="split-body">'.$body.'</div></div>', ['canonical' => $canonical]);
}

function team_page(string $n): never
{
    $t = TEAM_PAGES[$n];
    $label = CAMS[$n][0];
    $chips = '<p class="chips">'.implode('', array_map(fn ($r) => '<span>'.e($r).'</span>', $t['roles'])).'</p>';
    $me = current_user();
    $join = ($me && $me['kind'] === 'manager' ? '' : '<p>'.e($t['join']).'</p>').join_cta();
    split_page($t['title'], '', $t['heading'], $n, [
        ['apie', $t['about'], $t['intro'].$chips],
        ['gang', 'Darbas per GANG', $join],
    ], '/'.$t['slug'], false);
}

function how_page(): never
{
    $days = REVIEW_HIDE_DAYS;
    split_page('Kaip veikia GANG', 'Kaip veikia GANG', 'Nuo paieškos iki pamainos', '03', [
        ['darbdaviams', 'Darbdaviams', '<p>Užregistruokite savo barą, restoraną ar kavinę ir paskelbkite paiešką: nurodykite, kokio darbuotojo reikia, kokios patirties tikitės, kada ir kokiomis valandomis. Visi darbuotojai, kurių profilyje pažymėtos atitinkamos pareigos ir jūsų miestas bei kurių patirtis atitinka jūsų reikalavimą, iš karto gauna pranešimą el. paštu. Kandidatus matote savo paskyroje kartu su jų patirtimi, įvertinimais ir laisvomis dienomis. Patvirtinę kandidatą, gaunate jo telefono numerį.</p>'
            .'<p class="chips"><span>Pareigos</span><span>Patirtis</span><span>Data ir valandos</span><span>Atlygis</span></p>'],
        ['darbuotojams', 'Darbuotojams', '<p>Susikurkite profilį, pažymėkite pareigas, prie kiekvienų nurodykite savo patirtį ir pasirinkite miestus. Kalendoriuje pažymėkite dienas per artimiausius 12 mėnesių, kuriomis galite priimti darbo pasiūlymus: darbdaviai matys, kad tomis dienomis esate laisvi. Pateikite kandidatūrą jums tinkantiems pasiūlymams. Darbdaviui patvirtinus jūsų kandidatūrą, gausite jo kontaktus.</p>'
            .'<p class="chips"><span>Profilis</span><span>Laisvos dienos</span><span>Pranešimai el. paštu</span></p>'],
        ['kontraktas', 'GANG kontraktas', '<p>GANG kontraktas sudaromas, kai darbdavys patvirtina darbuotojo kandidatūrą. GANG tik padeda šalims susisiekti: darbo sutartį, atsiskaitymą ir kitas sąlygas darbdavys ir darbuotojas suderina tiesiogiai.</p>'],
        ['ivertinimai', 'Įvertinimai', '<ul>'
            .'<li>Įvertinti galima tik po GANG kontrakto, todėl vertina tik tie, kurie iš tiesų dirbo kartu.</li>'
            .'<li>Įvertinimo autorius neskelbiamas: rodomas tik miestas ir data.</li>'
            .'<li>Darbuotojų įvertinimus mato tik prisijungę darbdaviai, o darbdavių įvertinimus – tik prisijungę darbuotojai.</li></ul>'],
    ], '/kaip-veikia');
}

function about_page(): never
{
    split_page('Apie GANG', 'Apie GANG', 'Get ready to meet the GANG', '01', [
        ['problema', 'Problemos', '<ul>'
            .'<li><strong>Skubus pavadavimas.</strong> Darbuotojas suserga likus kelioms valandoms iki pamainos, ir vadovui tenka skambinti visiems pažįstamiems arba pačiam stoti už baro ar prie viryklės.</li>'
            .'<li><strong>Sezono ir renginių įkarštis.</strong> Vasarą, per šventes ir didelius renginius papildomų rankų reikia čia ir dabar, tačiau patikimų žmonių trumpam laikotarpiui rasti sunku.</li>'
            .'<li><strong>Darbuotojų trūkumas.</strong> Trūksta ne tik padavėjų ir barmenų, bet ir virėjų, indų plovėjų bei kitų virtuvės darbuotojų, be kurių neįsivaizduojama nė viena komanda.</li>'
            .'<li><strong>Patikimumas.</strong> Skelbimų portaluose ir socialinių tinklų grupėse sunku įvertinti, ar žmogus tikrai atvyks ir gerai atliks savo darbą.</li>'
            .'<li><strong>Brangūs tarpininkai.</strong> Įdarbinimo agentūros už kiekvieną darbo valandą ima papildomą mokestį, todėl trumpalaikė pagalba tampa brangi.</li></ul>'],
        ['sprendimas', 'Sprendimai', '<p>GANG tiesiogiai sujungia barus, restoranus ir kavines su hospitality specialistais. Darbdavys paskelbia paiešką, ir apie ją iš karto sužino tinkamas pareigas, miestą bei patirtį nurodę darbuotojai. Darbuotojai kalendoriuje pažymi dienas, kuriomis gali dirbti, todėl darbdavys iš karto mato, kas yra laisvas.</p>'
            .'<p>Pasitikėjimą kuria abipusiai įvertinimai: juos palikti gali tik tie, kurie iš tiesų dirbo kartu. Darbo sąlygas darbdavys ir darbuotojas suderina tiesiogiai, be tarpininkų ir valandinių mokesčių.</p>'
            .'<p>Tikime, kad darbas hospitality sektoriuje yra ne tik papildomos pajamos, bet ir profesija bei saviraiška. Norime, kad šis darbas būtų vertinamas taip, kaip jis to nusipelno.</p>'],
        ['kas-mes', 'GANG', '<blockquote><p>HoReCa sektoriuje sukuosi nuo 2008 metų ir dirbti teko visose įmanomose pozicijose: virtuvėje, bare, salėje ir, žinoma, lauke (dažniausiai prie klubo durų).</p>'
            .'<p>Puikiai žinau, kaip atrodo darbas, kai pamainoje trūksta bent vieno iš kolegų, ir ne kartą teko pačiam ieškoti pagalbos visais įmanomais kanalais.</p>'
            .'<p>GANG padės skubiai rasti patikimą komandos narį, o geri specialistai čia lengvai ras sau tinkamiausią darbą.</p>'
            .'<p>Pastebėjau ir tai, kad pastaraisiais metais vis daugiau žmonių, neturinčių nieko bendro su HoReCa, teiraujasi, ar galėtų porą dienų pabandyti pasidarbuoti bare ar pagelbėti virtuvėje. Pasirodo, darbas restorane daugeliui atrodo viliojantis, tačiau, gyvenime neatsiradus tinkamai progai, taip ir lieka net neišbandytas – GANG tikrai rasime darbo ir žaliems.</p></blockquote>'
            .'<p><strong>Vilius</strong>, GANG įkūrėjas</p>'],
    ], '/apie');
}

function sitemap(): never
{
    header('Content-Type: application/xml; charset=utf-8');
    $out = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $paths = ['/', '/darbai', '/kaip-veikia', '/apie', '/baras', '/virtuve', '/aptarnavimas', '/privatumas', '/taisykles'];
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
