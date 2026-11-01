<?php

declare(strict_types=1);

function venue_form_page(?int $id = null): never
{
    $u = require_user('manager');
    $venue = $id ? sql('SELECT * FROM venues WHERE id = ? AND manager_id = ?', [$id, $u['id']])->fetch() : null;
    if ($id && !$venue) {
        not_found();
    }
    $v = $venue ?: ['name' => '', 'city' => '', 'address' => '', 'about' => ''];
    $errors = [];
    if (is_post()) {
        check_csrf();
        $v = ['name' => input('name'), 'city' => input('city'), 'address' => input('address'), 'about' => input('about')];
        if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 120) {
            $errors['name'] = 'Įrašykite darbovietės pavadinimą.';
        }
        if (!isset(CITIES[$v['city']])) {
            $errors['city'] = 'Pasirinkite miestą.';
        }
        if (mb_strlen($v['address']) > 190) {
            $errors['address'] = 'Adresas gali būti ne ilgesnis nei 190 simbolių.';
        }
        if (mb_strlen($v['about']) < VENUE_ABOUT_MIN) {
            $errors['about'] = 'Aprašymas padeda darbuotojams apsispręsti. Parašykite bent '.VENUE_ABOUT_MIN.' simbolių: kokia tai vieta, kokia komanda ir kokios darbo sąlygos.';
        } elseif (mb_strlen($v['about']) > 2000) {
            $errors['about'] = 'Tekstas gali būti ne ilgesnis nei 2000 simbolių.';
        }
        if (!$errors) {
            if ($venue) {
                sql('UPDATE venues SET name = ?, city = ?, address = ?, about = ? WHERE id = ?', [$v['name'], $v['city'], $v['address'], $v['about'], $venue['id']]);
                flash('Darbovietės duomenys išsaugoti.');
                redirect('/mano');
            }
            insert('venues', ['manager_id' => $u['id'], 'name' => $v['name'], 'city' => $v['city'], 'address' => $v['address'], 'about' => $v['about'], 'created_at' => now()]);
            flash('Darbovietė užregistruota. Dabar galite skelbti darbuotojų poreikius.');
            redirect('/mano');
        }
    }
    $title = $venue ? 'Darbovietės duomenys' : 'Užregistruokite savo barą, restoraną ar kavinę';
    page($title, '<section class="wrap narrow"><h1>'.$title.'</h1>'
        .'<form method="post" class="form" novalidate>'.csrf_field()
        .'<label>Pavadinimas <input name="name" value="'.e($v['name']).'" maxlength="120" placeholder="Pavyzdžiui: baras „Gintaras“" required>'.err($errors, 'name').'</label>'
        .'<div class="row"><label>Miestas <select name="city" required>'.options(['' => 'Pasirinkite'] + CITIES, $v['city']).'</select>'.err($errors, 'city').'</label>'
        .'<label>Adresas <small>(darbuotojas matys tik po patvirtinimo)</small><input name="address" value="'.e($v['address']).'" maxlength="190" autocomplete="street-address">'.err($errors, 'address').'</label></div>'
        .'<label>Apie darbovietę <small>(bent '.VENUE_ABOUT_MIN.' simbolių)</small><textarea name="about" rows="5" minlength="'.VENUE_ABOUT_MIN.'" maxlength="2000" required data-min="'.VENUE_ABOUT_MIN.'" placeholder="Pavyzdžiui: kokteilių baras senamiestyje, 40 vietų. Dirbame komandoje po 3–4 žmones, darbas vakarais, apmokymai vietoje.">'.e($v['about']).'</textarea>'
        .'<span class="counter meta" aria-live="polite"></span>'.err($errors, 'about').'</label>'
        .'<button class="btn">Išsaugoti</button></form></section>'.counter_script(), ['noindex' => true]);
}

// Parses "2026-11-07:2026-11-08,2026-11-14:2026-11-15" into sorted, merged [start, end] pairs.
// Returns [] when anything is invalid, in the past or more than a year ahead.
function parse_ranges(string $raw): array
{
    $max = date('Y-m-d', strtotime('+12 months'));
    $ranges = [];
    foreach (array_filter(explode(',', $raw)) as $part) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}):(\d{4}-\d{2}-\d{2})$/', $part, $m)
            || !DateTime::createFromFormat('!Y-m-d', $m[1]) || !DateTime::createFromFormat('!Y-m-d', $m[2])
            || $m[1] > $m[2] || $m[1] < today() || $m[2] > $max) {
            return [];
        }
        $ranges[] = [$m[1], $m[2]];
    }
    if (count($ranges) > 12) {
        return [];
    }
    sort($ranges);
    $merged = [];
    foreach ($ranges as [$a, $b]) {
        $last = count($merged) - 1;
        if ($last >= 0 && $a <= date('Y-m-d', strtotime($merged[$last][1].' +1 day'))) {
            $merged[$last][1] = max($merged[$last][1], $b);
        } else {
            $merged[] = [$a, $b];
        }
    }
    return $merged;
}

function ranges_string(array $ranges): string
{
    return implode(',', array_map(fn ($r) => $r[0].':'.$r[1], $ranges));
}

// One calendar for a single day, a from–to range or several ranges. Used by the request form
// and the worker's availability; each .picker writes "from:to,…" into the form's hidden "dates" input.
function date_picker_script(): string
{
    return <<<'HTML'
<script>
document.querySelectorAll('.picker').forEach(function (box) {
  var form = box.closest('form'), out = form.querySelector('input[name=dates]');
  if (!out) return;
  var MN = ['Sausis','Vasaris','Kovas','Balandis','Gegužė','Birželis','Liepa','Rugpjūtis','Rugsėjis','Spalis','Lapkritis','Gruodis'];
  var MG = ['sausio','vasario','kovo','balandžio','gegužės','birželio','liepos','rugpjūčio','rugsėjo','spalio','lapkričio','gruodžio'];
  var min = box.dataset.min, max = box.dataset.max, limit = +box.dataset.limit || 12, empty = box.dataset.empty || 'Dienos dar nepažymėtos.';
  var hint = form.querySelector('.when-hint'), hintText = hint ? hint.textContent : '';
  var ranges = out.value ? out.value.split(',').map(function (p) { return p.split(':'); }) : [];
  var pending = null, hover = null, view = (ranges[0] ? ranges[0][0] : min).slice(0, 7);
  function iso(y, m, d) { return y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0'); }
  function addMonth(ym, n) { var y = +ym.slice(0, 4), m = +ym.slice(5) - 1 + n; y += Math.floor(m / 12); m = ((m % 12) + 12) % 12; return y + '-' + String(m + 1).padStart(2, '0'); }
  function nextDay(s) { var d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + 1); return d.toISOString().slice(0, 10); }
  function lt(s) { return MG[+s.slice(5, 7) - 1] + ' ' + +s.slice(8); }
  function label(r) {
    if (r[0] === r[1]) return lt(r[0]) + ' d.';
    if (r[0].slice(0, 7) === r[1].slice(0, 7)) return lt(r[0]) + '–' + +r[1].slice(8) + ' d.';
    return lt(r[0]) + ' d. – ' + lt(r[1]) + ' d.';
  }
  function permanent() { var k = form.querySelector('input[name=kind]:checked'); return k && k.value === 'nuolat'; }
  function normalize() {
    ranges.sort(function (a, b) { return a[0] < b[0] ? -1 : 1; });
    var m = [];
    ranges.forEach(function (r) { var l = m[m.length - 1]; if (l && r[0] <= nextDay(l[1])) { if (r[1] > l[1]) l[1] = r[1]; } else m.push([r[0], r[1]]); });
    ranges = m;
  }
  function find(d) { for (var i = 0; i < ranges.length; i++) if (d >= ranges[i][0] && d <= ranges[i][1]) return i; return -1; }
  function pick(d) {
    if (permanent()) { ranges = [[d, d]]; pending = null; }
    else if (pending && d === pending[0]) { pending = null; }
    else if (pending && d > pending[0]) { pending[1] = d; pending = null; normalize(); }
    else if (find(d) >= 0) { ranges.splice(find(d), 1); pending = null; }
    else if (ranges.length < limit) { pending = [d, d]; ranges.push(pending); normalize(); pending = ranges[find(d)]; }
    render();
  }
  function month(ym) {
    var y = +ym.slice(0, 4), m = +ym.slice(5), lead = (new Date(y, m - 1, 1).getDay() + 6) % 7, n = new Date(y, m, 0).getDate();
    var h = '<div class="month"><h3>' + MN[m - 1] + ' ' + y + '</h3><div class="week"><span>Pr</span><span>An</span><span>Tr</span><span>Kt</span><span>Pn</span><span>Št</span><span>Sk</span></div><div class="days">';
    for (var i = 0; i < lead; i++) h += '<span class="day pad"></span>';
    for (var d = 1; d <= n; d++) {
      var s = iso(y, m, d), wk = (lead + d - 1) % 7 >= 5 ? ' weekend' : '';
      if (s < min || s > max) { h += '<span class="day off' + wk + '">' + d + '</span>'; continue; }
      var i2 = find(s), c = i2 >= 0 ? ' on' : '';
      if (i2 >= 0) { if (s === ranges[i2][0]) c += ' first'; if (s === ranges[i2][1]) c += ' last'; }
      if (pending && hover && s > pending[0] && s <= hover) c += ' preview';
      if (pending && s === pending[0]) c += ' anchor';
      h += '<button type="button" class="day' + wk + c + (s === min ? ' today' : '') + '" data-d="' + s + '" aria-pressed="' + (i2 >= 0) + '" aria-label="' + lt(s) + ' d."><span>' + d + '</span></button>';
    }
    for (var j = lead + n; j < 42; j++) h += '<span class="day pad"></span>';
    return h + '</div></div>';
  }
  function render() {
    if (hint) hint.textContent = permanent() ? hint.dataset.nuolat : hintText;
    out.value = ranges.map(function (r) { return r[0] + ':' + r[1]; }).join(',');
    out.dataset.label = ranges.map(label).join(', ');
    var first = view < min.slice(0, 7) ? min.slice(0, 7) : view, last = addMonth(max.slice(0, 7), -1);
    if (first > last) first = last; view = first;
    var status = pending
      ? '<p class="pick-status">Pažymėta ' + label(pending) + ' Spustelėkite paskutinę laikotarpio dieną arba <button type="button" class="linkish" data-single>palikite vieną dieną</button>.</p>'
      : '';
    var chips = ranges.length
      ? '<div class="chips">' + ranges.map(function (r, i) { return '<span class="chip">' + label(r) + '<button type="button" data-rm="' + i + '" aria-label="Pašalinti ' + label(r) + '">×</button></span>'; }).join('') + '</div>'
      : '<p class="meta">' + empty + '</p>';
    box.innerHTML = '<div class="pick-nav"><button type="button" class="btn small ghost" data-nav="-1"' + (view <= min.slice(0, 7) ? ' disabled' : '') + ' aria-label="Ankstesnis mėnuo">←</button>'
      + '<button type="button" class="btn small ghost" data-nav="1"' + (view >= last ? ' disabled' : '') + ' aria-label="Kitas mėnuo">→</button></div>'
      + '<div class="pick-months">' + month(view) + month(addMonth(view, 1)) + '</div>' + status + chips;
  }
  function changed() { out.dispatchEvent(new Event('change', {bubbles: true})); }
  box.addEventListener('click', function (e) {
    var t = e.target.closest('button'); if (!t) return;
    if (t.dataset.d) { pick(t.dataset.d); changed(); }
    else if (t.dataset.nav) { view = addMonth(view, +t.dataset.nav); render(); }
    else if (t.dataset.rm) { ranges.splice(+t.dataset.rm, 1); pending = null; render(); changed(); }
    else if ('single' in t.dataset) { pending = null; render(); }
  });
  box.addEventListener('mouseover', function (e) {
    var t = e.target.closest('button[data-d]'); if (!pending || !t || t.dataset.d === hover) return;
    hover = t.dataset.d;
    box.querySelectorAll('button[data-d]').forEach(function (b) { b.classList.toggle('preview', b.dataset.d > pending[0] && b.dataset.d <= hover); });
  });
  form.querySelectorAll('input[name=kind]').forEach(function (k) {
    k.addEventListener('change', function () { if (permanent() && ranges.length) ranges = [[ranges[0][0], ranges[0][0]]]; pending = null; render(); });
  });
  render();
});
</script>
HTML;
}

// Live "dar X simbolių" under textareas with data-min.
function counter_script(): string
{
    return <<<'HTML'
<script>
document.querySelectorAll('textarea[data-min]').forEach(function (t) {
  var out = t.parentNode.querySelector('.counter'), min = +t.dataset.min;
  function show() {
    var left = min - t.value.trim().length;
    out.textContent = left > 0 ? 'Dar reikia simbolių: ' + left : 'Aprašymo ilgis pakankamas.';
    out.classList.toggle('ok-text', left <= 0);
  }
  t.addEventListener('input', show); show();
});
</script>
HTML;
}

function venue_page(int $id): never
{
    $u = require_user();
    $venue = sql('SELECT * FROM venues WHERE id = ?', [$id])->fetch();
    if (!$venue) {
        not_found();
    }
    if ($u['kind'] !== 'worker' && (int) $venue['manager_id'] !== (int) $u['id'] && !is_admin($u)) {
        flash('Darbdavių įvertinimus gali peržiūrėti tik darbuotojai.');
        redirect('/mano');
    }
    [$avg, $n] = venue_rating($id);
    $reviews = reviews_html(venue_reviews($id), fn ($r) => 'Darbuotojas');
    $jobs = open_requests([], 200);
    $jobs = array_filter($jobs, fn ($r) => (int) $r['venue_id'] === $id);
    $jobsHtml = $jobs ? '<h2>Aktualūs darbo pasiūlymai</h2><div class="cards">'.implode('', array_map('request_card', $jobs)).'</div>' : '';
    page($venue['name'], '<section class="wrap narrow"><h1>'.e($venue['name']).'</h1><p class="meta">'.e(CITIES[$venue['city']] ?? '').' · '.stars($avg, $n).'</p>'
        .($venue['about'] ? '<div class="desc">'.nl2br(e($venue['about'])).'</div>' : '')
        .$jobsHtml.'<h2>Darbuotojų įvertinimai</h2>'.$reviews.'</section>', ['noindex' => true]);
}

function request_form_page(): never
{
    $u = require_user('manager');
    $venues = sql('SELECT * FROM venues WHERE manager_id = ? ORDER BY id', [$u['id']])->fetchAll();
    if (!$venues) {
        redirect('/darboviete/nauja');
    }
    $venueOptions = array_column($venues, 'name', 'id');
    $v = ['venue_id' => (string) $venues[0]['id'], 'role' => '', 'kind' => 'skubu', 'people' => '1', 'dates' => '', 'pay' => '', 'min_experience' => '1', 'description' => ''];
    // "Pakartoti": the form comes filled in from an earlier request; only the dates are picked again.
    $from = !is_post() && ctype_digit($_GET['pagal'] ?? '') ? find_request((int) $_GET['pagal']) : null;
    if ($from && (int) $from['manager_id'] === (int) $u['id']) {
        preg_match('/^\d+(?:,\d+)?/', (string) $from['pay'], $pay);
        // Older requests kept the working hours in a separate field; they now go at the top of the description.
        $description = ($from['hours'] !== '' ? 'Darbo laikas: '.$from['hours']."\n\n" : '').$from['description'];
        $v = ['venue_id' => (string) $from['venue_id'], 'role' => $from['role'], 'kind' => $from['kind'], 'people' => (string) $from['people'], 'dates' => '',
            'pay' => valid_pay($pay[0] ?? '') ? $pay[0] : '', 'min_experience' => (string) $from['min_experience'], 'description' => $description];
    }
    $errors = [];
    if (is_post()) {
        check_csrf();
        foreach ($v as $k => $_) {
            $v[$k] = input($k);
        }
        if (!isset($venueOptions[(int) $v['venue_id']])) {
            $errors['venue_id'] = 'Pasirinkite darbovietę.';
        }
        if (!isset(ROLES[$v['role']])) {
            $errors['role'] = 'Pasirinkite pareigas.';
        }
        if (!isset(KINDS[$v['kind']])) {
            $errors['kind'] = 'Pasirinkite darbo trukmę.';
        }
        $people = (int) $v['people'];
        if ($people < 1 || $people > 20) {
            $errors['people'] = 'Nurodykite skaičių nuo 1 iki 20.';
        }
        $ranges = parse_ranges($v['dates']);
        if (!$ranges) {
            $errors['dates'] = 'Pažymėkite kalendoriuje bent vieną dieną, kada reikalingas darbuotojas.';
        } elseif ($v['kind'] === 'nuolat') {
            $ranges = [[$ranges[0][0], $ranges[0][0]]];
        }
        $v['dates'] = ranges_string($ranges);
        if (!isset(EXPERIENCE_REQUIRED[(int) $v['min_experience']])) {
            $errors['min_experience'] = 'Nurodykite reikalaujamą darbo patirtį.';
        }
        $rate = str_replace(',', '.', $v['pay']);
        if (!valid_pay($v['pay'])) {
            $errors['pay'] = PAY_ERROR;
        }
        if (mb_strlen($v['description']) < 20 || mb_strlen($v['description']) > 3000) {
            $errors['description'] = 'Nurodykite darbo laiką ir trumpai aprašykite darbo pobūdį bei pareigas (bent 20 simbolių).';
        }
        if (!$errors) {
            $id = insert('requests', [
                'venue_id' => (int) $v['venue_id'], 'role' => $v['role'], 'kind' => $v['kind'], 'people' => $people,
                'start_date' => $ranges[0][0], 'end_date' => end($ranges)[1] !== $ranges[0][0] ? end($ranges)[1] : null, 'dates' => count($ranges) > 1 ? $v['dates'] : '', 'hours' => '', 'pay' => pay_label($rate),
                'min_experience' => (int) $v['min_experience'], 'description' => $v['description'], 'created_at' => now(),
            ]);
            $sent = notify_workers(find_request($id));
            flash($sent ? "Poreikis paskelbtas. Pranešimą gavo tinkamų darbuotojų: $sent. Atsiliepimus matysite šiame puslapyje ir gausite el. paštu." : 'Poreikis paskelbtas. Tinkamų registruotų darbuotojų kol kas nėra, tačiau skelbimą matys visi svetainės lankytojai.');
            redirect('/darbas/'.$id);
        }
    }
    $kinds = '<div class="choices kind-choices">';
    foreach (KINDS as $value => $label) {
        $kinds .= '<label class="choice"><input type="radio" name="kind" value="'.$value.'"'.($value === $v['kind'] ? ' checked' : '').'>'
            .'<span><span class="choice-name">'.e($label).'</span><small>'.e(KIND_HINTS[$value]).'</small></span></label>';
    }
    $kinds .= '</div>';
    $venueData = '';
    foreach ($venues as $venue) {
        $venueData .= ' data-v'.(int) $venue['id'].'="'.e($venue['name'].' · '.(CITIES[$venue['city']] ?? '')).'"';
    }
    $repeat = $from && (int) $from['manager_id'] === (int) $u['id'] ? '<p class="notice tip">Forma užpildyta pagal ankstesnį poreikį. Pažymėkite naujas datas ir, jei reikia, pakoreguokite kitus laukus.</p>' : '';
    page('Naujas poreikis', '<section class="wrap request-wrap"><h1>Kokio darbuotojo ieškote?</h1>'.$repeat
        .'<form method="post" class="form request-form" novalidate'.$venueData.'>'.csrf_field()
        .'<aside class="live-preview" aria-live="polite"><p class="eyebrow">Taip skelbimą matys darbuotojai</p><div class="card"><div class="card-link"><h3 data-p="role">Pareigos</h3>'
        .'<p class="meta" data-p="venue"></p><p class="meta" data-p="when">Datos nepažymėtos</p><p class="tags"><span class="tag" data-p="kind"></span><span class="tag pay" data-p="pay" hidden></span></p></div></div></aside>'
        .(count($venues) > 1 ? '<label>Darbovietė <select name="venue_id">'.options($venueOptions, $v['venue_id']).'</select>'.err($errors, 'venue_id').'</label>' : '<input type="hidden" name="venue_id" value="'.(int) $venues[0]['id'].'">')
        .'<div class="row"><label>Pareigos <select name="role" required>'.options(['' => 'Pasirinkite'] + ROLES, $v['role']).'</select>'.err($errors, 'role').'</label>'
        .'<label>Darbuotojų skaičius <input type="number" name="people" min="1" max="20" value="'.e($v['people']).'">'.err($errors, 'people').'</label></div>'
        .'<fieldset><legend>Darbo trukmė</legend>'.$kinds.err($errors, 'kind').'</fieldset>'
        .'<fieldset><legend>Reikalaujama darbo patirtis</legend><p class="hint">Apie skelbimą el. paštu informuosime tik reikalavimą atitinkančius darbuotojus.</p>'.radios('min_experience', EXPERIENCE_REQUIRED, $v['min_experience']).err($errors, 'min_experience').'</fieldset>'
        .'<fieldset class="when"><legend>Kada reikalingas darbuotojas?</legend>'
        .'<p class="hint when-hint" data-nuolat="Pažymėkite darbo pradžios dieną.">Spustelėkite dieną, kad pažymėtumėte vieną dieną. Spustelėjus kitą dieną, pažymimas laikotarpis nuo–iki. Galite pažymėti kelis laikotarpius, pavyzdžiui, kelis savaitgalius.</p>'
        .'<div class="picker" data-min="'.today().'" data-max="'.date('Y-m-d', strtotime('+12 months')).'"><noscript><p class="notice">Kalendoriui reikalingas „JavaScript“. Įjunkite jį naršyklėje.</p></noscript></div>'
        .'<input type="hidden" name="dates" value="'.e($v['dates']).'">'.err($errors, 'dates').'</fieldset>'
        .'<label class="pay-field">Atlygis į rankas <span class="input-suffix"><input name="pay" value="'.e($v['pay']).'" inputmode="decimal" maxlength="6" required placeholder="10" pattern="\d{1,3}([.,]\d{1,2})?" aria-describedby="pay-live"><span aria-hidden="true">€/val.</span></span>'
        .err($errors, 'pay').'<span class="err-live" id="pay-live" role="alert" hidden>'.e(PAY_ERROR).'</span></label>'
        .'<label>Darbo laikas ir aprašymas <textarea name="description" rows="6" maxlength="3000" required placeholder="Pavyzdžiui: penktadienį ir šeštadienį 16–24 val. Dėl darbuotojo ligos ieškome virėjo karštajai virtuvei. Meniu nesudėtingas, dirbsite kartu su su-šefu.">'.e($v['description']).'</textarea>'.err($errors, 'description').'</label>'
        .'<div class="submit-bar"><button class="btn">Paskelbti ir informuoti darbuotojus</button></div></form></section>'.date_picker_script().request_preview_script(), ['noindex' => true]);
}

// Keeps the preview card next to the request form in step with the fields.
function request_preview_script(): string
{
    return <<<'HTML'
<script>
(function () {
  var f = document.querySelector('.request-form'); if (!f) return;
  function el(k) { return f.querySelector('[data-p=' + k + ']'); }
  function val(n) { var x = f.querySelector('[name=' + n + ']:checked') || f.querySelector('[name=' + n + ']'); return x ? x.value : ''; }
  function update() {
    var role = f.querySelector('select[name=role]'), kind = f.querySelector('input[name=kind]:checked');
    el('role').textContent = role && role.value ? role.options[role.selectedIndex].text : 'Pareigos';
    var people = +val('people');
    el('venue').textContent = (f.dataset['v' + val('venue_id')] || '') + (people > 1 ? ' · reikia darbuotojų: ' + people : '');
    var dates = f.querySelector('input[name=dates]');
    el('when').textContent = (dates && dates.dataset.label) || 'Datos nepažymėtos';
    var k = el('kind');
    k.textContent = kind ? kind.parentNode.querySelector('.choice-name').textContent : '';
    k.className = 'tag' + (kind && kind.value === 'skubu' ? ' urgent' : '');
    var pay = val('pay').trim().replace('.', ','), ok = payOk(pay);
    el('pay').hidden = !ok;
    el('pay').textContent = pay + ' €/val. į rankas';
  }
  // Pay: digits and one comma or dot only, at most 100 €/val.; a message shows straight away when the number is out of range.
  var payIn = f.querySelector('input[name=pay]'), payMsg = document.getElementById('pay-live');
  function payOk(p) { return /^\d{1,3}([.,]\d{1,2})?$/.test(p) && +p.replace(',', '.') > 0 && +p.replace(',', '.') <= 100; }
  function payCheck(final) {
    var p = payIn.value.trim();
    payMsg.hidden = p === '' ? !final : payOk(p) || (!final && /^\d{1,3}[.,]?$/.test(p) && +p.replace(',', '') <= 100);
  }
  payIn.addEventListener('input', function () {
    var clean = payIn.value.replace(/[^\d.,]/g, '').replace(/([.,].*)[.,]/g, '$1');
    if (clean !== payIn.value) payIn.value = clean;
    payCheck(false);
  });
  payIn.addEventListener('blur', function () { if (payIn.value.trim() !== '') payCheck(true); });
  f.addEventListener('submit', function (e) {
    payCheck(true);
    if (!payMsg.hidden) { e.preventDefault(); payIn.focus(); }
  });
  f.addEventListener('input', update); f.addEventListener('change', update); f.addEventListener('click', function () { setTimeout(update); });
  update();
})();
</script>
HTML;
}

const PAY_ERROR = 'Nurodykite valandinį atlygį į rankas eurais – skaičių iki 100, pavyzdžiui, 10 arba 12,50.';

// Hourly pay as typed: up to 100 €, a comma or a dot, at most two decimals.
function valid_pay(string $pay): bool
{
    if (!preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $pay)) {
        return false;
    }
    $n = (float) str_replace(',', '.', $pay);
    return $n > 0 && $n <= 100;
}

// Stored as display text, e.g. "12,50 €/val. į rankas".
function pay_label(string $rate): string
{
    $n = round((float) $rate, 2);
    return str_replace('.', ',', (string) ($n == floor($n) ? (int) $n : number_format($n, 2, '.', ''))).' €/val. į rankas';
}

function request_page(int $id): never
{
    $r = find_request($id);
    if (!$r) {
        not_found();
    }
    $u = current_user();
    $owner = $u && (int) $r['manager_id'] === (int) $u['id'];
    [$where, $params] = open_where();
    $isOpen = (bool) sql("SELECT 1 FROM requests r WHERE r.id = ? AND $where", array_merge([$id], $params))->fetchColumn();
    if (!$isOpen && !$owner && !($u && sql('SELECT 1 FROM responses WHERE request_id = ? AND worker_id = ?', [$id, $u['id']])->fetchColumn()) && !is_admin($u)) {
        not_found();
    }
    $role = ROLES[$r['role']] ?? $r['role'];
    $city = CITIES[$r['city']] ?? '';
    [$avg, $n] = venue_rating((int) $r['venue_id']);
    $facts = '<ul class="facts"><li><b>Kada</b>'.e(period($r)).'</li>'
        .($r['hours'] !== '' ? '<li><b>Darbo laikas</b>'.e($r['hours']).'</li>' : '')
        .($r['pay'] !== '' ? '<li><b>Atlygis</b>'.e($r['pay']).'</li>' : '')
        .'<li><b>Tipas</b>'.e(KINDS[$r['kind']] ?? '').'</li>'
        .'<li><b>Patirtis</b>'.e(EXPERIENCE_REQUIRED[(int) $r['min_experience']] ?? '').'</li>'
        .((int) $r['people'] > 1 ? '<li><b>Reikia</b>'.(int) $r['people'].' žm.</li>' : '').'</ul>';
    $venueLink = $u && $u['kind'] === 'worker' ? '<a href="/darboviete/'.(int) $r['venue_id'].'">'.e($r['venue_name']).'</a> · '.stars($avg, $n) : e($r['venue_name']);
    $action = $owner ? owner_panel($r, $isOpen) : worker_panel($r, $u, $isOpen);
    $desc = nl2br(e($r['description']));
    $urgent = $r['kind'] === 'skubu' ? '<span class="tag urgent">Skubu</span> ' : '';
    $e = 'e';
    $ld = '';
    if ($isOpen) {
        $ld = '<script type="application/ld+json">'.json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => $role,
            'description' => nl2br(e($r['description'])),
            'datePosted' => substr($r['created_at'], 0, 10),
            'validThrough' => ($r['end_date'] ?: ($r['kind'] === 'nuolat' ? date('Y-m-d', strtotime($r['created_at'].' +30 days')) : $r['start_date'])).'T23:59:00+02:00',
            'employmentType' => ['skubu' => 'PER_DIEM', 'trumpai' => 'TEMPORARY', 'sezonas' => 'TEMPORARY', 'nuolat' => 'FULL_TIME'][$r['kind']],
            'hiringOrganization' => ['@type' => 'Organization', 'name' => $r['venue_name']],
            'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $city, 'addressCountry' => 'LT']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script>';
    }
    page("$role – {$r['venue_name']}, $city", <<<HTML
<article class="wrap narrow job">
  <p class="back"><a href="/darbai">← Visi darbo pasiūlymai</a></p>
  <p>$urgent</p>
  <h1>{$e($role)}</h1>
  <p class="meta">$venueLink · {$e($city)}</p>
  $facts
  <div class="desc">$desc</div>
  $action
</article>
HTML, [
        'description' => mb_substr("$role, {$r['venue_name']}, $city: ".preg_replace('/\s+/', ' ', $r['description']), 0, 155),
        'canonical' => '/darbas/'.$id,
        'head' => $ld,
        'noindex' => !$isOpen,
    ]);
}

function worker_panel(array $r, ?array $u, bool $isOpen): string
{
    if (!$u) {
        return $isOpen ? '<div class="panel"><h2>Šis pasiūlymas jums tinka?</h2><p>Atsiliepti į darbo pasiūlymus gali registruoti GANG nariai. Registracija trunka vos kelias minutes.</p><p><a class="btn" href="/prisijungti?kaip=darbuotojas">Susikurti profilį ir atsiliepti</a> <a href="/prisijungti">Jau turiu paskyrą</a></p></div>' : '<div class="panel"><p>Šis darbo pasiūlymas nebegalioja.</p></div>';
    }
    if ($u['kind'] !== 'worker') {
        return '';
    }
    $mine = sql('SELECT * FROM responses WHERE request_id = ? AND worker_id = ?', [$r['id'], $u['id']])->fetch();
    if ($mine) {
        return match ($mine['status']) {
            'accepted' => accepted_panel($r, $u),
            'declined' => '<div class="panel"><p>Dėkojame už susidomėjimą. Šį kartą darbdavys pasirinko kitą kandidatą. Kviečiame peržiūrėti <a href="/darbai">kitus darbo pasiūlymus</a>.</p></div>',
            'withdrawn' => '<div class="panel"><p>Savo atsiliepimą atšaukėte.</p></div>',
            default => '<div class="panel"><h2>Jūsų atsiliepimas išsiųstas</h2><p>Darbdavys mato jūsų profilį ir įvertinimus. Darbdaviui patvirtinus jūsų kandidatūrą, el. paštu gausite jo kontaktus.</p>'
                .post_button('/darbas/'.$r['id'].'/atsaukti', 'Atšaukti atsiliepimą', [], 'btn small ghost', 'Ar tikrai norite atšaukti atsiliepimą?').'</div>',
        };
    }
    if (!$isOpen) {
        return '<div class="panel"><p>Šis darbo pasiūlymas nebegalioja.</p></div>';
    }
    $below = (int) $r['min_experience'] > 1 && (role_experience((int) $u['id'])[$r['role']] ?? 0) < (int) $r['min_experience']
        ? '<p class="notice tip">Darbdavio reikalaujama patirtis: '.e(mb_strtolower(EXPERIENCE_REQUIRED[(int) $r['min_experience']] ?? '')).'. Jūsų profilyje šioms pareigoms nurodyta mažesnė patirtis, todėl rekomenduojame žinutėje trumpai paaiškinti, kodėl šis darbas jums tinka.</p>'
        : '';
    return '<div class="panel" id="atsiliepti"><h2>Atsiliepti į pasiūlymą</h2>'.$below
        .'<form method="post" action="/darbas/'.(int) $r['id'].'/atsiliepti" class="form">'.csrf_field()
        .'<label>Žinutė darbdaviui <small>(neprivaloma)</small><textarea name="note" rows="3" maxlength="1000" placeholder="Pavyzdžiui: galiu atvykti per valandą, turiu dvejų metų patirtį šiose pareigose."></textarea></label>'
        .'<button class="btn">Pateikti kandidatūrą</button></form>'
        .'<p class="meta">Darbdavys matys jūsų profilį ir įvertinimus. Telefono numerį – tik patvirtinęs jūsų kandidatūrą.</p></div>';
}

function accepted_panel(array $r, array $u): string
{
    $c = sql(str_replace(' FROM contracts c', ", (SELECT COUNT(*) FROM reviews rv WHERE rv.contract_id = c.id AND rv.author = 'worker') AS reviewed FROM contracts c", CONTRACT_SELECT)
        .' WHERE c.request_id = ? AND c.worker_id = ?', [$r['id'], $u['id']])->fetch();
    return '<div class="panel ok"><h2>Darbdavys patvirtino jūsų kandidatūrą</h2>'
        .'<p>Susisiekite su darbdaviu ir aptarkite darbo detales. Darbo sąlygos ir sutartis derinamos tiesiogiai su darbdaviu.</p>'
        .($c ? contract_card($c, 'worker', (bool) $c['reviewed']) : '').'</div>';
}

function owner_panel(array $r, bool $isOpen): string
{
    $responses = sql("SELECT s.*, w.name, w.about, w.roles, w.photo, w.phone, wr.experience FROM responses s JOIN users w ON w.id = s.worker_id
        LEFT JOIN worker_roles wr ON wr.user_id = s.worker_id AND wr.role = ?
        WHERE s.request_id = ? AND s.status IN ('sent', 'accepted') ORDER BY CASE WHEN s.status = 'accepted' THEN 0 ELSE 1 END, s.created_at", [$r['role'], $r['id']])->fetchAll();
    $list = '';
    foreach ($responses as $s) {
        [$avg, $n] = worker_rating((int) $s['worker_id']);
        $done = completed_count((int) $s['worker_id']);
        $badges = [];
        if (is_available((int) $s['worker_id'], $r['start_date'], $r['end_date'] ?: $r['start_date'])) {
            $badges[] = '<span class="badge on">Laisvas šiomis dienomis</span>';
        }
        if (isset(EXPERIENCE[(int) $s['experience']])) {
            $badges[] = '<span class="badge">Patirtis: '.e(mb_strtolower(EXPERIENCE[(int) $s['experience']])).'</span>';
        }
        $badges[] = $done ? '<span class="badge">Darbų per GANG: '.$done.'</span>' : '<span class="badge">Naujas GANG narys</span>';
        $buttons = $s['status'] === 'accepted'
            ? '<p class="ok-text">Patvirtinta</p>'.tel_link($s['phone'], 'Skambinti '.$s['phone'])
            : post_button('/atsiliepimas/'.(int) $s['id'].'/patvirtinti', 'Patvirtinti', [], 'btn small').post_button('/atsiliepimas/'.(int) $s['id'].'/atmesti', 'Atsisakyti', [], 'btn small ghost');
        $list .= '<div class="responder'.($s['status'] === 'accepted' ? ' accepted' : '').'">'.avatar(array_merge($s, ['id' => $s['worker_id']])).'<div class="who"><strong><a href="/darbuotojas/'.(int) $s['worker_id'].'">'.e(first_name($s['name'])).'</a></strong> '.stars($avg, $n)
            .'<p class="badges">'.implode('', $badges).'</p>'
            .($s['note'] ? '<p class="note">„'.e($s['note']).'“</p>' : '').($s['about'] ? '<details><summary>Apie save</summary><p>'.nl2br(e($s['about'])).'</p></details>' : '')
            .'</div><div class="actions">'.$buttons.'</div></div>';
    }
    [$total, $freeNow] = matching_workers_count($r['role'], $r['city'], max($r['start_date'], today()), (int) $r['min_experience']);
    $reach = '<ul class="reach"><li><b>'.(int) $r['notified'].'</b> gavo pranešimą el. paštu</li><li><b>'.$total.'</b> tinkami darbuotojai mieste</li><li><b>'.$freeNow.'</b> laisvi pirmąją darbo dieną</li></ul>';
    $status = ['open' => 'Aktyvus', 'filled' => 'Užpildytas', 'cancelled' => 'Atšauktas', 'expired' => 'Pasibaigęs'][$r['status']] ?? $r['status'];
    $close = $isOpen ? post_button('/darbas/'.$r['id'].'/uzdaryti', 'Uždaryti poreikį', [], 'btn small ghost', 'Ar tikrai norite uždaryti šį poreikį? Darbuotojai jo nebematys.') : '';
    return '<div class="panel owner"><div class="owner-head"><h2>Atsiliepę darbuotojai</h2><span class="tag'.($isOpen ? ' live' : '').'">'.e($isOpen ? 'Aktyvus' : $status).'</span></div>'.$reach
        .($list ?: '<p class="empty">Atsiliepusių darbuotojų dar nėra. Kai kas nors atsilieps, informuosime jus el. paštu.</p>')
        .'<p class="owner-actions">'.$close.'<a class="btn small ghost" href="/poreikis/naujas?pagal='.(int) $r['id'].'">Pakartoti poreikį</a></p></div>';
}

function respond_action(int $id): never
{
    $u = require_user('worker');
    check_csrf();
    $r = find_request($id);
    [$where, $params] = open_where();
    if (!$r || !sql("SELECT 1 FROM requests r WHERE r.id = ? AND $where", array_merge([$id], $params))->fetchColumn()) {
        not_found();
    }
    if (!sql('SELECT 1 FROM responses WHERE request_id = ? AND worker_id = ?', [$id, $u['id']])->fetchColumn()) {
        $note = mb_substr(input('note'), 0, 1000);
        insert('responses', ['request_id' => $id, 'worker_id' => $u['id'], 'note' => $note, 'created_at' => now()]);
        $manager = sql('SELECT * FROM users WHERE id = ?', [$r['manager_id']])->fetch();
        [$avg, $n] = worker_rating((int) $u['id']);
        $rating = $n ? number_format($avg, 1, ',', '')." iš 5 ($n įvert.)" : 'įvertinimų dar nėra';
        send_mail($manager['email'], 'Nauja kandidatūra: '.(ROLES[$r['role']] ?? '').' – '.first_name($u['name']),
            "Laba diena,\n\n"
            .first_name($u['name'])." atsiliepė į jūsų poreikį „".(ROLES[$r['role']] ?? '')."“ (".period($r).").\n"
            ."Įvertinimas: $rating\n".($note !== '' ? "\nŽinutė: $note\n" : '')
            ."\nKandidatūrą galite peržiūrėti ir patvirtinti čia:\n".url('/darbas/'.$id));
    }
    flash('Kandidatūra pateikta. Darbdavys gaus pranešimą el. paštu.');
    redirect('/darbas/'.$id);
}

function withdraw_action(int $id): never
{
    $u = require_user('worker');
    check_csrf();
    sql("UPDATE responses SET status = 'withdrawn' WHERE request_id = ? AND worker_id = ? AND status = 'sent'", [$id, $u['id']]);
    redirect('/darbas/'.$id);
}

function close_request_action(int $id): never
{
    $u = require_user('manager');
    check_csrf();
    $r = find_request($id);
    if (!$r || (int) $r['manager_id'] !== (int) $u['id']) {
        not_found();
    }
    $accepted = (int) sql("SELECT COUNT(*) FROM responses WHERE request_id = ? AND status = 'accepted'", [$id])->fetchColumn();
    sql('UPDATE requests SET status = ?, filled_at = COALESCE(filled_at, ?) WHERE id = ?', [$accepted ? 'filled' : 'cancelled', $accepted ? now() : null, $id]);
    decline_waiting($r);
    flash('Poreikis uždarytas.');
    redirect('/mano');
}

function decide_response_action(int $responseId, bool $accept): never
{
    $u = require_user('manager');
    check_csrf();
    $s = sql('SELECT * FROM responses WHERE id = ?', [$responseId])->fetch();
    $r = $s ? find_request((int) $s['request_id']) : null;
    if (!$r || (int) $r['manager_id'] !== (int) $u['id']) {
        not_found();
    }
    if ($s['status'] !== 'sent') {
        redirect('/darbas/'.$r['id']);
    }
    $worker = sql('SELECT * FROM users WHERE id = ?', [$s['worker_id']])->fetch();
    $role = ROLES[$r['role']] ?? '';
    if (!$accept) {
        sql("UPDATE responses SET status = 'declined' WHERE id = ?", [$responseId]);
        send_mail($worker['email'], 'Dėl jūsų kandidatūros: '.$role.' – '.$r['venue_name'], "Laba diena,\n\ndėkojame, kad atsiliepėte į {$r['venue_name']} darbo pasiūlymą. Šį kartą darbdavys pasirinko kitą kandidatą.\n\nKviečiame peržiūrėti kitus aktualius darbo pasiūlymus:\n".url('/darbai'));
        redirect('/darbas/'.$r['id']);
    }
    sql("UPDATE responses SET status = 'accepted' WHERE id = ?", [$responseId]);
    insert('contracts', ['request_id' => $r['id'], 'worker_id' => $worker['id'], 'venue_id' => $r['venue_id'], 'created_at' => now()]);
    send_mail($worker['email'], 'Patvirtinta: '.$role.' – '.$r['venue_name'],
        "Laba diena,\n\n{$r['venue_name']} patvirtino jūsų kandidatūrą.\n\n"
        ."Kada: ".period($r).($r['hours'] !== '' ? ", {$r['hours']}" : '')."\n"
        ."Kur: ".($r['address'] ?: CITIES[$r['city']])."\n"
        ."Kontaktas: {$u['name']}, {$u['phone']}\n\n"
        ."Rekomenduojame susisiekti su darbdaviu ir aptarti darbo detales. Darbo sąlygos ir sutartis derinamos tiesiogiai su darbdaviu.\n\n"
        ."Baigę darbą galėsite įvertinti darbdavį savo paskyroje:\n".url('/mano'), $u['email']);
    $accepted = (int) sql("SELECT COUNT(*) FROM responses WHERE request_id = ? AND status = 'accepted'", [$r['id']])->fetchColumn();
    if ($accepted >= (int) $r['people']) {
        sql("UPDATE requests SET status = 'filled', filled_at = ? WHERE id = ?", [now(), $r['id']]);
        decline_waiting($r);
        flash('Kandidatūra patvirtinta, visos vietos užpildytos. Darbuotojo kontaktai: '.first_name($worker['name']).', '.$worker['phone'].'.');
    } else {
        flash('Kandidatūra patvirtinta. Darbuotojo kontaktai: '.first_name($worker['name']).', '.$worker['phone'].'.');
    }
    redirect('/darbas/'.$r['id']);
}

// Lets everyone still waiting know the place is taken.
function decline_waiting(array $r): void
{
    $waiting = sql("SELECT w.email, w.name FROM responses s JOIN users w ON w.id = s.worker_id WHERE s.request_id = ? AND s.status = 'sent'", [$r['id']])->fetchAll();
    sql("UPDATE responses SET status = 'declined' WHERE request_id = ? AND status = 'sent'", [$r['id']]);
    foreach ($waiting as $w) {
        send_mail($w['email'], 'Dėl jūsų kandidatūros: '.(ROLES[$r['role']] ?? '').' – '.$r['venue_name'], "Laba diena,\n\ndėkojame, kad atsiliepėte į {$r['venue_name']} darbo pasiūlymą. Informuojame, kad visos vietos jau užpildytos.\n\nKviečiame peržiūrėti kitus aktualius darbo pasiūlymus:\n".url('/darbai'));
    }
}

function worker_profile_page(int $id): never
{
    $u = require_user();
    $w = sql("SELECT * FROM users WHERE id = ? AND kind = 'worker'", [$id])->fetch();
    if (!$w) {
        not_found();
    }
    if ($u['kind'] !== 'manager' && (int) $u['id'] !== $id && !is_admin($u)) {
        flash('Darbuotojų profilius gali peržiūrėti tik darbdaviai.');
        redirect('/mano');
    }
    [$avg, $n] = worker_rating($id);
    $reviews = reviews_html(worker_reviews($id), fn ($r) => 'Darbdavys, '.(CITIES[$r['city']] ?? ''));
    $ranges = availability_ranges($id);
    $free = $ranges ? '<p class="status on">● Laisvas darbo pasiūlymams: '.e(implode('; ', array_map('range_lt', array_slice($ranges, 0, 6)))).'</p>' : '';
    page(first_name($w['name']), '<section class="wrap narrow"><div class="greeting">'.avatar($w, 'large').'<div><h1>'.e(first_name($w['name'])).'</h1>'
        .'<p class="meta">'.stars($avg, $n).' · Darbų per GANG: '.completed_count($id).'</p></div></div>'.$free
        .'<ul class="facts"><li><b>Pareigos ir patirtis</b>'.e(roles_with_experience($id)).'</li>'.'<li><b>Miestai</b>'.e(labels($w['cities'], CITIES)).'</li></ul>'
        .($w['about'] ? '<div class="desc">'.nl2br(e($w['about'])).'</div>' : '')
        .'<h2>Įvertinimai</h2>'.$reviews.'</section>', ['noindex' => true]);
}
