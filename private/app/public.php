<?php

declare(strict_types=1);

// Home page "cameras": photos processed by tools/cctv.sh into public_html/img/cam-NN.jpg.
// A camera without a photo yet shows a "no signal" tile instead.
const CAMS = [
    '01' => ['Baras', 'Barmenai, baristos, baro vadovai.'],
    '02' => ['Virtuvė', 'Šefai, virėjai, virtuvės pagalbiniai darbuotojai, indų plovėjai.'],
    '03' => ['Aptarnavimas', 'Padavėjai, runneriai, administratoriai, restorano vadovai.'],
];

// Section stories behind the home camera tiles: slug, page heading, intro and roles
const TEAM_PAGES = [
    '01' => [
        'slug' => 'baras',
        'about' => 'Apie barą',
        'title' => 'Darbas bare',
        'heading' => 'Baras',
        'intro' => '<p>Baras – tai vieta, kurioje svečias pirmiausia pajunta, ar jis čia laukiamas. Barmenas vienu metu ruošia gėrimus, palaiko pokalbį ir mato visą salę, o baristas savo žiniomis paverčia paprastą užsakymą patirtimi.</p>'
            .'<p>Geram barui neužtenka gerų receptų – jam reikia žmonių, kurie išmano savo amatą ir moka priimti svečią, bet galiausiai barmeno meistriškumą svečias vis tiek pajus taurėje.</p>',
        'roles' => ['Barmenai', 'Baristos', 'Baro vadovai'],
        'join' => 'Paskyroje pažymėkite darbą bare, nurodykite savo patirtį ir miestus. Apie tinkamas pamainas, sezoninį ir nuolatinį darbą sužinosite pirmieji.',
    ],
    '02' => [
        'slug' => 'virtuve',
        'about' => 'Apie virtuvę',
        'title' => 'Darbas virtuvėje',
        'heading' => 'Virtuvė',
        'intro' => '<p>Virtuvė – restorano širdis. Čia viskas priklauso nuo pasiruošimo, tikslumo ir komandos: kiekvienas žino savo vietą, o pamaina pavyksta tik tada, kai visi dirba kaip vienas.</p>'
            .'<p>Šefas, virėjai, virtuvės pagalbiniai darbuotojai ir indų plovėjai yra vienodai svarbios grandys. Be bet kurios iš jų lėkštė iki svečio nenukeliauja, todėl GANG padeda virtuvėms rasti žmones, su kuriais pamaina vyksta sklandžiai.</p>',
        'roles' => ['Šefai', 'Virėjai', 'Virtuvės pagalbiniai darbuotojai', 'Indų plovėjai'],
        'join' => 'Paskyroje pažymėkite darbą virtuvėje, nurodykite savo patirtį ir miestus. Apie tinkamas pamainas, sezoninį ir nuolatinį darbą sužinosite pirmieji.',
    ],
    '03' => [
        'slug' => 'aptarnavimas',
        'about' => 'Apie aptarnavimą',
        'title' => 'Darbas aptarnavime',
        'heading' => 'Aptarnavimas',
        'intro' => '<p>Salėje susitinka maistas, gėrimai ir svečias, o visa komanda kuria vakaro nuotaiką: pasitinka, pataria ir pastebi, ko reikia, dar prieš paprašant.</p>'
            .'<p>Šio darbo esmė – rūpintis kitais žmonėmis ir puoselėti žmogiškumą.</p>'
            .'<p>Būtent čia aptarnavimas tampa svetingumu, o pirmas apsilankymas – priežastimi sugrįžti.</p>',
        'roles' => ['Padavėjai', 'Runneriai', 'Administratoriai', 'Restorano vadovai'],
        'join' => 'Paskyroje pažymėkite darbą salėje, nurodykite savo patirtį ir miestus. Apie tinkamas pamainas, renginius, sezoninį ir nuolatinį darbą sužinosite pirmieji.',
    ],
];

// Home hero "camera wall": four screens, each switching between its own photos (keys of JOB_PHOTOS).
const CAM_WALL = [
    ['bar', 'club', 'wedding'],
    ['kitchen', 'pass'],
    ['dining', 'briefing', 'cafe'],
    ['dish', 'stock', 'roastery'],
];
// Photos beside the text of /baras, /virtuve and /aptarnavimas: one large, two small.
const TEAM_PHOTOS = [
    '01' => ['bar', 'club', 'cafe'],
    '02' => ['kitchen', 'pass', 'dish'],
    '03' => ['dining', 'briefing', 'wedding'],
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
        return '<p class="cta"><a class="'.$workerClass.'" href="/poreikis/naujas">Paskelbti paiešką</a><a class="'.$employerClass.'" href="/mano">Mano paskyra</a></p>';
    }
    return '<p class="cta"><a class="'.$workerClass.'" href="/darbai">Darbo pasiūlymai</a><a class="'.$employerClass.'" href="/mano">Mano paskyra</a></p>';
}

function home_page(): never
{
    // Urgent listings come first (open_requests orders them so), the rest fills full rows of three.
    $latest = open_requests([], 6);
    if (count($latest) >= 3) {
        $latest = array_slice($latest, 0, count($latest) >= 6 ? 6 : 3);
    }
    $urgent = $latest && !array_filter($latest, fn ($r) => !request_urgent($r));
    $listTitle = $urgent ? 'Skubiai reikia' : 'Naujausi darbo pasiūlymai';
    $list = $latest ? '<section class="wrap home-block"><div class="section-head feed-head"><h2><span class="live-dot" aria-hidden="true"></span>'.$listTitle.'</h2><a href="/darbai">Visi pasiūlymai →</a></div><div class="feed">'.implode('', array_map('request_card_short', $latest)).'</div></section>' : '';
    // Hero: four camera screens behind the text (phones show one of them at a time); each screen cross-fades between its photos (script below).
    $wall = $preload = '';
    foreach (CAM_WALL as $c => $keys) {
        $wall .= '<div class="wall-cell'.($c === 0 ? ' show' : '').'">';
        foreach ($keys as $k => $key) {
            $n = str_pad((string) (array_search($key, array_keys(JOB_PHOTOS), true) + 1), 2, '0', STR_PAD_LEFT);
            $src = '/img/job-'.$key.'-sm.jpg?v=2';
            // Only the first photo of a screen loads with the page; the script fetches the rest when idle.
            $set = $src.' 640w, /img/job-'.$key.'.jpg?v=2 1200w';
            $wall .= '<figure class="'.($k === 0 ? 'on' : '').'"><img '.($k === 0 ? 'src="'.$src.'" srcset="'.$set.'"' : 'data-src="'.$src.'" data-srcset="'.$set.'"').' sizes="50vw" alt="" width="640" height="480"'.($k === 0 && $c === 0 ? ' fetchpriority="high"' : '').'>'
                .'<figcaption>CAM '.$n.' · '.e(mb_strtoupper(JOB_PHOTOS[$key])).'</figcaption></figure>';
            if ($k === 0) {
                $preload .= '<link rel="preload" as="image" href="'.$src.'" imagesrcset="'.$set.'" imagesizes="50vw">';
            }
        }
        $wall .= '</div>';
    }

    $tiles = '';
    foreach (CAMS as $n => [$label, $roles]) {
        $src = cam_photo($n);
        // The first photos, with their own burned-in camera label. Kept here on purpose (Vilius 2026-11-10)
        // until he asks to change them.
        $media = $src !== '' ? '<img src="'.$src.'-sm.jpg" alt="" loading="lazy" width="800" height="600">' : '<span class="no-signal">Vaizdas netrukus</span><span class="cam-label">CAM '.$n.'</span>';
        $tiles .= '<a class="cam-tile" href="/'.TEAM_PAGES[$n]['slug'].'"><div class="cam-media">'.$media.'</div>'
            .'<strong>'.e($label).'</strong><span>'.e($roles).'</span></a>';
    }

    $mark = knives();
    $me = current_user();
    $heroCta = join_cta();
    $workerBtn = !$me ? '<a class="btn" href="/prisijungti?kaip=darbuotojas">Susikurti paskyrą</a>'
        : ($me['kind'] === 'worker' ? '<a class="btn" href="/profilis">Redaguoti paskyrą</a>' : '');
    $venueBtn = !$me ? '<a class="btn light" href="/prisijungti?kaip=vadovas">Raskite žmonių savo komandai</a>'
        : ($me['kind'] === 'manager' ? '<a class="btn light" href="/poreikis/naujas">Paskelbti paiešką</a>' : '');
    $finalCta = $me ? '' : '<section class="wrap home-block">
  <div class="final-cta">
    <h2>Prisijunkite prie GANG</h2>
    <p>Registracija trunka kelias minutes.</p>
    '.join_cta('btn dark', 'btn dark ghost').'
  </div>
</section>';
    page('', <<<HTML
<section class="hero home-hero">
  <div class="cam-wall" aria-hidden="true">$wall</div>
  <div class="hero-shade"></div>
  <div class="grain" aria-hidden="true"></div>
  <p class="rec" aria-hidden="true"><i></i>REC</p>
  <div class="wrap hero-body">
    <h1>FOH &amp; BOH <span class="hl">Patikimi žmonės šiandien</span></h1>
    <p class="lead">GANG padeda restoranams, kavinėms, barams ir klubams greitai rasti darbuotojų pavadavimui, sezonui ar nuolatiniam darbui, o hospitality specialistams bei entuziastams – papildomų pajamų ir darbo, kuriame galima save išreikšti.</p>
    $heroCta
    <ul class="hero-facts"><li><strong>Salė ir virtuvė</strong> visos komandos pareigos</li><li><strong>6 miestai</strong> Vilnius, Kaunas, Klaipėda, Šiauliai, Panevėžys, Palanga</li></ul>
  </div>
</section>
$list

<section class="wrap home-block">
  <div class="audience">
    <div class="aud worker">
      <p class="eyebrow">Darbuotojams</p>
      <h2>Dirbkite, kai jums patogu</h2>
      <ul>
        <li><strong>Papildomos pajamos</strong>Tinkami pasiūlymai ateina el. paštu, o patvirtinti darbai atsiranda jūsų GANG kalendoriuje.</li>
        <li><strong>Profesinė reputacija</strong>Kiekvienas GANG kontraktas ir įvertinimas stiprina jūsų paskyrą.</li>
        <li><strong>Jūsų pasirinkimas</strong>Patys sprendžiate, kur, kada ir su kokia komanda dirbti.</li>
      </ul>
      $workerBtn
    </div>
    <div class="aud venue">
      <p class="eyebrow">Darbdaviams</p>
      <h2>Darbuotojų pasiūla</h2>
      <ul>
        <li><strong>Skubus pavadavimas</strong>Paskelbta paieška iš karto pasiekia tinkamus darbuotojus jūsų mieste.</li>
        <li><strong>Matote, ką kviečiate</strong>Kandidato patirtis, įvertinimai ir GANG darbų istorija vienoje vietoje.</li>
        <li><strong>Salė ir virtuvė</strong>Nuo barmeno iki indų plovėjo – visos komandos pareigos.</li>
      </ul>
      $venueBtn
    </div>
  </div>
</section>

<section class="wrap home-block">
  <div class="bento">
    <div class="bt t-2 lime"><span class="k">Darbuotojams</span><span class="big">1 kalendorius</span><p>Visi jūsų GANG darbai vienoje vietoje: patvirtinti darbai jame atsiranda patys.</p></div>
    <div class="bt t-2"><span class="k">Miestai</span><span class="big">6</span><p>Vilnius, Kaunas, Klaipėda, Šiauliai, Panevėžys ir Palanga.</p></div>
    <div class="bt t-2 orange"><span class="k">Įvertinimai</span><span class="big">{$mark}1–5</span><p>Vertina tik tie, kurie iš tiesų dirbo kartu.</p></div>
  </div>
</section>

<section class="wrap home-block">
  <div class="section-head"><h2>Visa komanda vienoje vietoje</h2></div>
  <div class="cam-tiles">$tiles</div>
</section>

<section class="wrap home-block">
  <div class="section-head"><h2>Kaip veikia</h2></div>
  <ol class="steps">
    <li><strong>Darbdavys paskelbia paiešką</strong>Pavyzdžiui, „virėjas rytoj, <span class="nw">10–22 val.</span>“ arba „padavėjai vasaros sezonui“.</li>
    <li><strong>Tinkami darbuotojai gauna pranešimą</strong>Pranešimas ateina el. paštu, o kandidatūrą galima pateikti vienu paspaudimu.</li>
    <li><strong>Darbdavys patvirtina kandidatą</strong>Sudaromas GANG kontraktas, o darbo detales abi šalys suderina GANG pokalbyje.</li>
    <li><strong>Abi šalys įvertina viena kitą</strong>Įvertinimai paskelbiami tik tada, kai įvertina abi šalys arba praėjus 7 dienoms.</li>
  </ol>
</section>

$finalCta
<script>
(function () {
  var cells = document.querySelectorAll('.cam-wall .wall-cell'), c = 0;
  if (!cells.length || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  function load() { document.querySelectorAll('.cam-wall img[data-src]').forEach(function (img) { img.srcset = img.dataset.srcset; img.src = img.dataset.src; img.removeAttribute('data-src'); }); }
  window.addEventListener('load', function () { setTimeout(load, 1500); });
  // Wide screens: one of the four screens switches at a time, in turn, like an operator flipping through cameras.
  // Phones show a single screen (CSS), so there the whole view moves on to the next camera.
  var phone = matchMedia('(max-width: 720px)');
  function ready(fig) { var img = fig.querySelector('img'); return img.complete && img.naturalWidth; }
  function advance(cell) {
    var figs = cell.querySelectorAll('figure'), on = cell.querySelector('figure.on');
    var next = figs[(Array.prototype.indexOf.call(figs, on) + 1) % figs.length];
    if (next !== on && ready(next)) { on.classList.remove('on'); next.classList.add('on'); }
  }
  (function tick() {
    setTimeout(function () {
      c = (c + 1) % cells.length;
      if (phone.matches) {
        // A hidden screen changes its photo before it comes back, so every visit shows something new.
        if (cells[c].dataset.seen) advance(cells[c]);
        if (ready(cells[c].querySelector('figure.on'))) {
          cells.forEach(function (cell, k) { cell.classList.toggle('show', k === c); });
          cells[c].dataset.seen = 1;
        }
      } else {
        advance(cells[c]);
      }
      tick();
    }, phone.matches ? 5000 : 2600);
  })();
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
        : '<p class="empty">Pagal pasirinktus filtrus darbo pasiūlymų šiuo metu nėra. '.(current_user() ? '' : 'Susikūrę paskyrą, apie naujus pasiūlymus gausite pranešimą el. paštu. <a href="/prisijungti?kaip=darbuotojas">Susikurti paskyrą</a>').'</p>';
    $cities = options(['' => 'Visi miestai'] + CITIES, $f['city']);
    $roles = options(['' => 'Visos pareigos'] + ROLES, $f['role']);
    $kinds = options(['' => 'Visi tipai'] + KINDS, $f['kind']);
    $cityName = $f['city'] ? ' '.CITIES_IN[$f['city']] : '';
    page('Darbas restoranuose, kavinėse, baruose ir klubuose'.($f['city'] ? ' – '.CITIES[$f['city']] : ''), <<<HTML
<section class="wrap list-page">
  <h1>Darbo pasiūlymai{$cityName}</h1>
  <form class="filters" method="get" action="/darbai">
    <select name="miestas" aria-label="Miestas">$cities</select>
    <select name="pareigos" aria-label="Pareigos">$roles</select>
    <select name="tipas" aria-label="Tipas">$kinds</select>
    <button class="btn">Rodyti</button>
  </form>
  <script>
  (function () {
    var f = document.querySelector('.filters');
    f.classList.add('auto');
    f.addEventListener('change', function () { f.submit(); });
  })();
  </script>
  <p class="meta">Rasta: $count</p>
  <h2 class="sr-only">Darbo pasiūlymai</h2>
  $cards
</section>
HTML, ['canonical' => '/darbai'.($f['city'] ? '?miestas='.$f['city'] : '')]);
}

// Two-column text page: sticky heading, CCTV photo and section index on the left,
// numbered sections on the right. $sections is [[id, heading, html], ...].
// With $numbered = false the sections carry no numbers, headings or side index (Baras, Virtuvė, Salė).
// $photos: keys of JOB_PHOTOS shown under the heading (the first large, the rest in a row); a section may carry
// its own photo as a fourth element.
function split_page(string $title, string $eyebrow, string $heading, array $photos, array $sections, string $canonical, bool $numbered = true, bool $cta = false): never
{
    $index = $body = '';
    foreach ($sections as $i => $sec) {
        [$id, $h, $html] = $sec;
        $html = (isset($sec[3]) ? cam_frame($sec[3], 'sec-photo', '-sm') : '').$html;
        $n = sprintf('%02d', $i + 1);
        if (!$numbered) {
            $body .= '<section class="sec plain" id="'.$id.'"><div>'.$html.'</div></section>';
            continue;
        }
        $index .= '<li><a href="#'.$id.'">'.$n.' · '.e($h).'</a></li>';
        $body .= '<section class="sec" id="'.$id.'"><span class="num" aria-hidden="true">'.$n.'</span><div><h2>'.e($h).'</h2>'.$html.'</div></section>';
    }
    $index = $index !== '' ? '<ol class="side-index">'.$index.'</ol>' : '';
    $photo = '';
    foreach ($photos as $k => $key) {
        $photo .= cam_frame($key, $k === 0 ? 'main' : 'small', '-sm', $k === 0);
    }
    $photo = $photo !== '' ? '<div class="side-cams">'.$photo.'</div>' : '';
    if ($cta) {
        $body .= '<div class="split-cta">'.(current_user() ? '' : '<h2>Prisijunkite prie GANG</h2><p>Registracija trunka kelias minutes.</p>').join_cta('btn dark', 'btn dark ghost').'</div>';
    }
    page($title, '<div class="wrap split"><div class="side">'.($eyebrow !== '' ? '<p class="eyebrow">'.e($eyebrow).'</p>' : '').'<h1>'.e($heading).'</h1>'.$photo
        .$index.'</div><div class="split-body">'.$body.'</div></div>', ['canonical' => $canonical]);
}

function team_page(string $n): never
{
    $t = TEAM_PAGES[$n];
    $label = CAMS[$n][0];
    $chips = '<p class="chips">'.implode('', array_map(fn ($r) => '<span>'.e($r).'</span>', $t['roles'])).'</p>';
    $me = current_user();
    $join = ($me && $me['kind'] === 'manager' ? '' : '<p>'.e($t['join']).'</p>').join_cta();
    split_page($t['title'], '', $t['heading'], TEAM_PHOTOS[$n], [
        ['apie', $t['about'], $t['intro'].$chips],
        ['gang', 'Darbas per GANG', $join],
    ], '/'.$t['slug'], false);
}

function how_page(): never
{
    $days = REVIEW_HIDE_DAYS;
    split_page('Kaip veikia GANG', 'Kaip veikia GANG', 'Nuo paieškos iki pamainos', [], [
        ['darbdaviams', 'Darbdaviams', '<p>Užregistruokite savo barą, restoraną ar kavinę ir paskelbkite paiešką: nurodykite, kokio darbuotojo reikia, kokios patirties tikitės, kada ir kokiomis valandomis. Visi darbuotojai, kurių paskyroje pažymėtos atitinkamos pareigos ir jūsų miestas bei kurių patirtis atitinka jūsų reikalavimą, gauna pranešimą el. paštu. Kandidatus matote savo paskyroje kartu su jų patirtimi ir įvertinimais. Su kandidatais galite susirašinėti GANG pokalbyje.</p>'
            .'<p class="chips"><span>Pareigos</span><span>Patirtis</span><span>Data ir valandos</span><span>Atlygis</span></p>', 'stock'],
        ['darbuotojams', 'Darbuotojams', '<p>Susikurkite paskyrą, pažymėkite pareigas, prie kiekvienų nurodykite savo patirtį ir pasirinkite miestus. Pateikite kandidatūrą jums tinkantiems pasiūlymams. Su darbdaviu galite susirašinėti GANG pokalbyje. Darbdaviui patvirtinus jūsų kandidatūrą, darbas automatiškai atsiras jūsų GANG kalendoriuje.</p>'
            .'<p class="chips"><span>Paskyra</span><span>GANG kalendorius</span><span>Pranešimai el. paštu</span></p>', 'roastery'],
        ['kontraktas', 'GANG kontraktas', '<p>GANG kontraktas sudaromas, kai darbdavys patvirtina darbuotojo kandidatūrą. GANG tik padeda šalims susisiekti: darbo sutartį, atsiskaitymą ir kitas sąlygas darbdavys ir darbuotojas suderina tiesiogiai.</p>', 'dining'],
        ['ivertinimai', 'Įvertinimai', '<ul>'
            .'<li>Įvertinti galima tik po GANG kontrakto, todėl vertina tik tie, kurie iš tiesų dirbo kartu.</li>'
            .'<li>Įvertinimo autorius neskelbiamas: prie darbuotojų įvertinimų rodomas darbdavio miestas ir data, prie darbdavių įvertinimų – tik data.</li>'
            .'<li>Darbuotojų įvertinimus mato tik prisijungę darbdaviai, o darbdavių įvertinimus – tik prisijungę darbuotojai.</li></ul>', 'pass'],
    ], '/kaip-veikia', true, true);
}

function about_page(): never
{
    split_page('Apie GANG', 'Apie GANG', 'Get ready to meet the GANG', ['briefing'], [
        ['problema', 'Problemos', '<ul>'
            .'<li><strong>Skubus pavadavimas.</strong> Darbuotojas suserga likus kelioms valandoms iki pamainos, ir vadovui tenka skambinti visiems pažįstamiems arba pačiam stoti už baro ar prie viryklės.</li>'
            .'<li><strong>Sezono ir renginių įkarštis.</strong> Vasarą, per šventes ir didelius renginius papildomų rankų reikia čia ir dabar, tačiau patikimų žmonių trumpam laikotarpiui rasti sunku.</li>'
            .'<li><strong>Darbuotojų trūkumas.</strong> Trūksta ne tik padavėjų ir barmenų, bet ir virėjų, indų plovėjų bei kitų virtuvės darbuotojų, be kurių neįsivaizduojama nė viena komanda.</li>'
            .'<li><strong>Patikimumas.</strong> Skelbimų portaluose ir socialinių tinklų grupėse sunku įvertinti, ar žmogus tikrai atvyks ir gerai atliks savo darbą.</li>'
            .'<li><strong>Brangūs tarpininkai.</strong> Įdarbinimo agentūros už kiekvieną darbo valandą ima papildomą mokestį, todėl trumpalaikė pagalba tampa brangi.</li></ul>'],
        ['sprendimas', 'Sprendimai', '<p>GANG tiesiogiai sujungia restoranus, kavines, barus ir klubus su hospitality specialistais. Darbdavys paskelbia paiešką, ir apie ją iš karto sužino tinkamas pareigas, miestą bei patirtį nurodę darbuotojai. Darbuotojai kandidatuoja į jiems tinkančius darbus, o patvirtinti darbai automatiškai atsiranda jų GANG kalendoriuje.</p>'
            .'<p>Pasitikėjimą kuria abipusiai įvertinimai: juos palikti gali tik tie, kurie iš tiesų dirbo kartu. Darbo sąlygas darbdavys ir darbuotojas suderina tiesiogiai, be tarpininkų ir valandinių mokesčių.</p>'
            .'<p>Tikime, kad darbas hospitality sektoriuje yra ne tik papildomos pajamos, bet ir profesija bei saviraiška. Norime, kad šis darbas būtų vertinamas taip, kaip jis to nusipelno.</p>'],
        ['kas-mes', 'GANG', '<blockquote><p>HoReCa sektoriuje sukuosi nuo 2008 metų ir dirbti teko visose įmanomose pozicijose: virtuvėje, bare, salėje ir, žinoma, lauke (dažniausiai prie klubo durų).</p>'
            .'<p>Puikiai žinau, kaip atrodo darbas, kai pamainoje trūksta bent vieno iš kolegų, ir ne kartą teko pačiam ieškoti pagalbos visais įmanomais kanalais.</p>'
            .'<p>GANG padės skubiai rasti patikimą komandos narį, o geri specialistai čia lengvai ras sau tinkamiausią darbą.</p>'
            .'<p>Pastebėjau ir tai, kad pastaraisiais metais vis daugiau žmonių, neturinčių nieko bendro su HoReCa, teiraujasi, ar galėtų porą dienų pabandyti pasidarbuoti bare ar pagelbėti virtuvėje. Pasirodo, darbas restorane daugeliui atrodo viliojantis, tačiau, gyvenime neatsiradus tinkamai progai, taip ir lieka net neišbandytas – GANG tikrai rasime darbo ir žaliems.</p></blockquote>'
            .'<p><strong>Vilius</strong>, GANG įkūrėjas</p>'],
    ], '/apie', true, true);
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
