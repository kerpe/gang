<?php

declare(strict_types=1);

const VENUE_FIELDS = ['name', 'type', 'concept', 'city', 'address', 'hours', 'about', 'team_size', 'website', 'instagram', 'facebook', 'tiktok'];

function venue_form_page(?int $id = null): never
{
    $u = require_user('manager');
    $venue = $id ? sql('SELECT * FROM venues WHERE id = ? AND manager_id = ?', [$id, $u['id']])->fetch() : null;
    if ($id && !$venue) {
        not_found();
    }
    $v = array_fill_keys(VENUE_FIELDS, '') + ['perks' => [], 'logo' => null, 'id' => 0];
    if ($venue) {
        $v = array_merge($v, array_intersect_key($venue, $v), ['perks' => from_list($venue['perks'])]);
    }
    $errors = [];
    if (is_post()) {
        check_csrf();
        foreach (VENUE_FIELDS as $field) {
            $v[$field] = input($field);
        }
        $v['perks'] = (array) ($_POST['perks'] ?? []);
        if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 120) {
            $errors['name'] = 'Įrašykite darbovietės pavadinimą.';
        }
        if (!isset(VENUE_TYPES[$v['type']])) {
            $errors['type'] = 'Pasirinkite darbovietės tipą.';
        }
        if (mb_strlen($v['concept']) > 120) {
            $errors['concept'] = 'Koncepcija gali būti ne ilgesnė nei 120 simbolių.';
        }
        if (!isset(CITIES[$v['city']])) {
            $errors['city'] = 'Pasirinkite miestą.';
        }
        if (mb_strlen($v['address']) > 190) {
            $errors['address'] = 'Adresas gali būti ne ilgesnis nei 190 simbolių.';
        }
        if (mb_strlen($v['hours']) > 190) {
            $errors['hours'] = 'Darbo laikas gali būti ne ilgesnis nei 190 simbolių.';
        }
        if (mb_strlen($v['about']) < VENUE_ABOUT_MIN) {
            $errors['about'] = 'Aprašymas padeda darbuotojams apsispręsti. Parašykite bent '.VENUE_ABOUT_MIN.' simbolių: kokia tai vieta, kokia komanda ir kokios darbo sąlygos.';
        } elseif (mb_strlen($v['about']) > 2000) {
            $errors['about'] = 'Tekstas gali būti ne ilgesnis nei 2000 simbolių.';
        }
        if ($v['team_size'] !== '' && !isset(TEAM_SIZES[$v['team_size']])) {
            $v['team_size'] = '';
        }
        foreach (array_keys(VENUE_LINKS) as $link) {
            if ($v[$link] === '') {
                continue;
            }
            $url = venue_link($link, $v[$link]);
            if ($url === '') {
                $errors[$link] = [
                    'website' => 'Įrašykite svetainės adresą, pavyzdžiui, www.jusu-baras.lt.',
                    'instagram' => 'Įrašykite Instagram paskyros vardą arba nuorodą, pavyzdžiui, @jusu_baras.',
                    'facebook' => 'Įrašykite Facebook puslapio nuorodą, pavyzdžiui, facebook.com/jusubaras.',
                    'tiktok' => 'Įrašykite TikTok paskyros vardą arba nuorodą, pavyzdžiui, @jusu_baras.',
                ][$link];
            } else {
                $v[$link] = $url;
            }
        }
        $logo = $venue['logo'] ?? null;
        if (!empty($_FILES['logo']['name'])) {
            $stored = store_photo($_FILES['logo'], true);
            if ($stored[0] === '!') {
                $errors['logo'] = substr($stored, 1);
            } else {
                $logo = $stored;
            }
        } elseif (!empty($_POST['remove_logo'])) {
            $logo = null;
        }
        if ($errors && $logo !== ($venue['logo'] ?? null)) {
            delete_photo($logo);
            $logo = $venue['logo'] ?? null;
        }
        if (!$errors) {
            $row = array_intersect_key($v, array_flip(VENUE_FIELDS)) + ['perks' => to_list($v['perks'], VENUE_PERKS), 'logo' => $logo];
            if ($venue) {
                sql('UPDATE venues SET '.implode(', ', array_map(fn ($k) => "$k = ?", array_keys($row))).' WHERE id = ?', [...array_values($row), $venue['id']]);
                if ($logo !== $venue['logo']) {
                    delete_photo($venue['logo']);
                }
                if ($row['name'] !== $venue['name']) {
                    refresh_invite_slug($u);
                }
                flash('Darbovietės duomenys išsaugoti.');
                redirect('/mano');
            }
            $venueId = insert('venues', ['manager_id' => $u['id']] + $row + ['created_at' => now()]);
            if (!manager_verified($u)) {
                verification_requested($u, $venueId);
                flash('Darbovietė užregistruota. Patikrinsime ją ir el. paštu pranešime, kai galėsite skelbti paieškas.');
                redirect('/mano');
            }
            flash('Darbovietė užregistruota. Dabar galite skelbti darbuotojų paieškas.');
            redirect('/mano');
        }
        $v['logo'] = $logo;
    }
    $title = $venue ? 'Darbovietės duomenys' : 'Užregistruokite savo barą, restoraną ar kavinę';
    $field = fn (string $label, string $control, string $name, string $hint = '', string $class = '') => '<label class="field'.($class ? " $class" : '').'"><span class="field-label">'.$label.'</span>'.$control
        .($hint !== '' ? '<span class="field-hint">'.$hint.'</span>' : '').err($errors, $name).'</label>';
    $text = fn (string $name, int $max, string $placeholder = '', string $extra = '') => '<input name="'.$name.'" value="'.e($v[$name]).'" maxlength="'.$max.'"'.($placeholder !== '' ? ' placeholder="'.e($placeholder).'"' : '').$extra.'>';
    $optional = ' <small>neprivaloma</small>';
    $section = fn (string $heading, string $hint, string $body) => '<fieldset class="form-sec"><div class="form-sec-head"><legend>'.$heading.'</legend><p>'.$hint.'</p></div><div class="form-sec-body">'.$body.'</div></fieldset>';
    $remove = !empty($v['logo']) ? '<label class="check"><input type="checkbox" name="remove_logo"> Pašalinti logotipą</label>' : '';
    $logoField = '<div class="photo-row logo-row">'.venue_logo(['id' => $v['id'] ?: ($venue['id'] ?? 0), 'name' => $v['name'] ?: 'GANG', 'logo' => $v['logo']], 'large')
        .'<div><span class="field-label">Logotipas'.$optional.'</span><input type="file" name="logo" accept="image/jpeg,image/png,image/webp" aria-label="Darbovietės logotipas" data-crop="logo">'
        .'<p class="meta">JPG, PNG arba WEBP formatu, iki '.PHOTO_MAX_MB.' MB. Rodomas darbo skelbimuose ir darbovietės puslapyje.</p>'.$remove.err($errors, 'logo').'</div></div>';
    $perks = '<div class="choices perk-choices">';
    foreach (VENUE_PERKS as $key => $label) {
        $perks .= '<label class="choice"><input type="checkbox" name="perks[]" value="'.$key.'"'.(in_array($key, $v['perks'], true) ? ' checked' : '').'> '.e($label).'</label>';
    }
    $perks .= '</div>';
    $body = $section('Pagrindinė informacija', 'Pavadinimas, tipas ir logotipas padeda darbuotojams iš karto atpažinti jūsų darbovietę.',
            $field('Pavadinimas', $text('name', 120, 'Pavyzdžiui: baras Gintaras', ' required autocomplete="organization"'), 'name')
            .'<div class="row">'.$field('Tipas', '<select name="type" required>'.options(['' => 'Pasirinkite'] + VENUE_TYPES, $v['type']).'</select>', 'type')
            .$field('Koncepcija ar virtuvė'.$optional, $text('concept', 120, 'Pavyzdžiui: itališka virtuvė'), 'concept').'</div>'
            .$logoField)
        .$section('Vieta ir darbo laikas', 'Adresas matomas darbo skelbimuose ir darbovietės puslapyje. Telefono numeris darbuotojams nerodomas.',
            '<div class="row">'.$field('Miestas', '<select name="city" required>'.options(['' => 'Pasirinkite'] + CITIES, $v['city']).'</select>', 'city')
            .$field('Adresas', $text('address', 190, 'Pavyzdžiui: Basanavičiaus g. 12', ' autocomplete="street-address"'), 'address').'</div>'
            .$field('Darbo laikas'.$optional, $text('hours', 190, 'Pavyzdžiui: I–IV 12:00–23:00, V–VI 12:00–02:00'), 'hours', 'Kada darbovietė atidaryta lankytojams.'))
        .$section('Apie darbovietę', 'Darbuotojai renkasi ne tik pagal atlygį. Papasakokite, kokia tai vieta ir kaip jums dirbama.',
            $field('Aprašymas', '<textarea name="about" rows="6" minlength="'.VENUE_ABOUT_MIN.'" maxlength="2000" required data-min="'.VENUE_ABOUT_MIN.'" placeholder="Pavyzdžiui: kokteilių baras senamiestyje, 40 vietų. Dirbame komandoje po 3–4 žmones, daugiausia vakarais. Naujus kolegas apmokome vietoje.">'.e($v['about']).'</textarea><span class="counter meta" aria-live="polite"></span>', 'about'))
        .$section('Komanda ir sąlygos', 'Pažymėkite, ką siūlote darbuotojams. Tai viena svarbiausių priežasčių, kodėl žmonės renkasi darbovietę.',
            '<div class="row">'.$field('Komandos dydis'.$optional, '<select name="team_size">'.options(['' => 'Nenurodyta'] + TEAM_SIZES, $v['team_size']).'</select>', 'team_size').'</div>'
            .'<div class="field"><span class="field-label">Darbuotojams siūlome'.$optional.'</span>'.$perks.'</div>')
        .$section('Internete', 'Nuorodos padeda darbuotojams geriau pažinti jūsų darbovietę prieš priimant pasiūlymą.',
            '<div class="row">'.$field('Svetainė'.$optional, $text('website', 255, 'www.jusu-baras.lt', ' inputmode="url" autocomplete="url"'), 'website')
            .$field('Instagram'.$optional, $text('instagram', 255, '@jusu_baras'), 'instagram').'</div>'
            .'<div class="row">'.$field('Facebook'.$optional, $text('facebook', 255, 'facebook.com/jusubaras', ' inputmode="url"'), 'facebook')
            .$field('TikTok'.$optional, $text('tiktok', 255, '@jusu_baras'), 'tiktok').'</div>');
    $cancel = '<a class="btn ghost" href="'.($venue ? '/darboviete/'.(int) $venue['id'] : '/mano').'">Atšaukti</a>';
    $step = !$venue && !sql('SELECT 1 FROM venues WHERE manager_id = ?', [$u['id']])->fetchColumn() ? onboarding_step(2) : '';
    page($title, '<section class="wrap venue-edit">'.$step.'<h1>'.$title.'</h1>'
        .'<form method="post" class="form venue-form" enctype="multipart/form-data" novalidate>'.csrf_field()
        .$body
        .'<div class="form-actions"><button class="btn">'.($venue ? 'Išsaugoti pakeitimus' : 'Užregistruoti darbovietę').'</button>'.$cancel.'</div></form></section>'
        .counter_script().photo_resize_script(), ['noindex' => true]);
}

// Turns what the manager typed (a URL, a domain, "@handle" or a bare handle) into a full https link.
// Returns '' when it cannot be a valid link of that kind.
function venue_link(string $kind, string $raw): string
{
    $raw = trim($raw);
    $hosts = ['instagram' => 'instagram.com', 'facebook' => 'facebook.com', 'tiktok' => 'tiktok.com'];
    if ($kind !== 'website' && preg_match('/^@?([A-Za-z0-9._-]{2,60})$/', $raw, $m) && !str_contains($m[1], '.com')) {
        return 'https://www.'.$hosts[$kind].'/'.($kind === 'tiktok' ? '@' : '').$m[1];
    }
    $url = preg_match('#^https?://#i', $raw) ? $raw : 'https://'.$raw;
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !str_contains($host, '.') || mb_strlen($url) > 255) {
        return '';
    }
    if ($kind !== 'website') {
        $allowed = $kind === 'facebook' ? ['facebook.com', 'fb.com'] : [$hosts[$kind]];
        $ok = false;
        foreach ($allowed as $a) {
            $ok = $ok || $host === $a || str_ends_with($host, '.'.$a);
        }
        if (!$ok || trim($parts['path'] ?? '', '/') === '') {
            return '';
        }
    }
    return $url;
}

// Short readable form of a venue link: the domain for a website, the account name otherwise.
function venue_link_label(string $kind, string $url): string
{
    if ($kind === 'website') {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    $first = explode('/', $path)[0];
    return $kind === 'facebook' ? ($first === 'profile.php' ? 'Facebook' : $first) : '@'.ltrim($first, '@');
}

function venue_links_html(array $venue): string
{
    $out = '';
    foreach (VENUE_LINKS as $kind => $label) {
        if (!empty($venue[$kind])) {
            $out .= '<li><a href="'.e($venue[$kind]).'" target="_blank" rel="noopener nofollow ugc"><span class="link-kind">'.$label.'</span>'.e(venue_link_label($kind, $venue[$kind])).'</a></li>';
        }
    }
    return $out !== '' ? '<ul class="venue-links">'.$out.'</ul>' : '';
}

// Parses "2026-11-07:2026-11-08,2026-11-14:2026-11-15" into sorted, merged [start, end] pairs.
// Returns [] when anything is invalid, in the past or more than a year ahead.
function parse_ranges(string $raw): array
{
    $max = availability_last_day();
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
  var saved = out.value, unsaved = form.querySelector('.unsaved'), shown = 0;
  // Months side by side follow the calendar's own width: three on wide screens, two on narrower, one on phones.
  function count() { var w = box.clientWidth; return w >= 640 ? 3 : w >= 440 ? 2 : 1; }
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
    // The last page must still reach the last allowed month.
    shown = count();
    var first = view < min.slice(0, 7) ? min.slice(0, 7) : view, last = addMonth(max.slice(0, 7), 1 - shown);
    if (first > last) first = last; view = first;
    var status = pending
      ? '<p class="pick-status">Pažymėta ' + label(pending) + ' Spustelėkite paskutinę laikotarpio dieną arba <button type="button" class="linkish" data-single>palikite vieną dieną</button>.</p>'
      : '';
    var chips = ranges.length
      ? '<div class="chips">' + ranges.map(function (r, i) { return '<span class="chip">' + label(r) + '<button type="button" data-rm="' + i + '" aria-label="Pašalinti ' + label(r) + '">×</button></span>'; }).join('') + '</div>'
      : '<p class="meta">' + empty + '</p>';
    box.innerHTML = '<div class="pick-nav"><button type="button" class="btn small ghost" data-nav="-1"' + (view <= min.slice(0, 7) ? ' disabled' : '') + ' aria-label="Ankstesnis mėnuo">←</button>'
      + '<button type="button" class="btn small ghost" data-nav="1"' + (view >= last ? ' disabled' : '') + ' aria-label="Kitas mėnuo">→</button></div>'
      + '<div class="pick-months n' + shown + '">' + [0, 1, 2].slice(0, shown).map(function (i) { return month(addMonth(view, i)); }).join('') + '</div>' + status + chips;
  }
  function changed() { if (unsaved) unsaved.hidden = out.value === saved; out.dispatchEvent(new Event('change', {bubbles: true})); }
  box.addEventListener('click', function (e) {
    var t = e.target.closest('button'); if (!t) return;
    if (t.dataset.d) { pick(t.dataset.d); changed(); }
    else if (t.dataset.nav) { view = addMonth(view, +t.dataset.nav); render(); }
    else if (t.dataset.rm) { ranges.splice(+t.dataset.rm, 1); pending = null; render(); changed(); }
    else if ('single' in t.dataset) { pending = null; render(); }
  });
  // Range preview follows a mouse only: on touch screens a tap must simply pick the day.
  box.addEventListener('pointerover', function (e) {
    if (e.pointerType !== 'mouse') return;
    var t = e.target.closest('button[data-d]'); if (!pending || !t || t.dataset.d === hover) return;
    hover = t.dataset.d;
    box.querySelectorAll('button[data-d]').forEach(function (b) { b.classList.toggle('preview', b.dataset.d > pending[0] && b.dataset.d <= hover); });
  });
  form.querySelectorAll('input[name=kind]').forEach(function (k) {
    k.addEventListener('change', function () { if (permanent() && ranges.length) ranges = [[ranges[0][0], ranges[0][0]]]; pending = null; render(); });
  });
  if (window.ResizeObserver) new ResizeObserver(function () { if (count() !== shown) render(); }).observe(box);
  else window.addEventListener('resize', function () { if (count() !== shown) render(); });
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

// "Darbuotojams siūlome" chips of a venue.
function venue_perks_html(array $venue, string $heading): string
{
    $perks = array_values(array_intersect_key(VENUE_PERKS, array_flip(from_list($venue['perks']))));
    return $perks ? $heading.'<ul class="perk-list">'.implode('', array_map(fn ($p) => '<li>'.e($p).'</li>', $perks)).'</ul>' : '';
}

function venue_type_text(array $venue): string
{
    return trim(implode(' · ', array_filter([VENUE_TYPES[$venue['type']] ?? '', $venue['concept']])));
}

// Ratings and reliability of a venue are for workers (and the venue itself), not for other employers.
function sees_venue_reviews(?array $u, array $venue): bool
{
    return $u && ($u['kind'] === 'worker' || (int) $venue['manager_id'] === (int) $u['id'] || is_admin($u));
}

// The venue's description is public: everyone, signed in or not, sees it. Phone and e-mail are never shown here.
function venue_page(int $id): never
{
    $u = current_user();
    $venue = sql('SELECT v.*, m.verified_at FROM venues v JOIN users m ON m.id = v.manager_id WHERE v.id = ?', [$id])->fetch();
    if (!$venue || (!$venue['verified_at'] && !($u && ((int) $venue['manager_id'] === (int) $u['id'] || is_admin($u))))) {
        not_found();
    }
    $private = sees_venue_reviews($u, $venue);
    [$avg, $n] = venue_rating($id);
    $reliable = $private ? venue_reliability($id) : null;
    $reviewsHtml = match (true) {
        $private => '<h2>Darbuotojų įvertinimai</h2>'.criteria_html('worker', 'venue_id', $id).reviews_html(venue_reviews($id), fn ($r) => 'Darbuotojas'),
        !$u => '<div class="panel venue-join"><h2>Darbuotojų įvertinimai</h2><p>Darbuotojų įvertinimus ir darbdavio patikimumą mato registruoti GANG darbuotojai.</p><p><a class="btn" href="/prisijungti?kaip=darbuotojas">Susikurti profilį</a> <a href="/prisijungti">Jau turiu paskyrą</a></p></div>',
        default => '',
    };
    $jobs = open_requests([], 200);
    $jobs = array_filter($jobs, fn ($r) => (int) $r['venue_id'] === $id);
    $jobsHtml = $jobs ? '<h2>Aktualūs darbo pasiūlymai</h2><div class="cards">'.implode('', array_map('request_card', $jobs)).'</div>' : '';
    $type = venue_type_text($venue);
    $facts = array_filter([
        'Adresas' => full_address($venue),
        'Darbo laikas' => $venue['hours'],
        'Komanda' => TEAM_SIZES[$venue['team_size']] ?? '',
        'Patikimumas' => $reliable !== null ? $reliable.' % sutartų darbų įvyko' : '',
    ]);
    $factsHtml = '<dl class="venue-facts">'.implode('', array_map(fn ($k, $val) => '<div><dt>'.$k.'</dt><dd>'.e($val).'</dd></div>', array_keys($facts), $facts)).'</dl>';
    $perksHtml = venue_perks_html($venue, '<h2>Darbuotojams siūlome</h2>');
    $edit = $u && (int) $venue['manager_id'] === (int) $u['id'] ? '<p class="venue-actions"><a class="btn small ghost" href="/darboviete/'.$id.'/redaguoti">Redaguoti duomenis</a></p>' : '';
    page($venue['name'], '<section class="wrap narrow venue-page"><header class="venue-head">'.venue_logo($venue, 'large')
        .'<div>'.($type !== '' ? '<p class="eyebrow">'.e($type).'</p>' : '').'<h1>'.e($venue['name']).'</h1><p class="meta">'.e(CITIES[$venue['city']] ?? '').($private ? ' · '.stars($avg, $n) : '').'</p>'
        .venue_links_html($venue).'</div></header>'.$edit
        .$factsHtml
        .($venue['about'] ? '<h2>Apie darbovietę</h2><div class="desc">'.nl2br(e($venue['about'])).'</div>' : '')
        .$perksHtml
        .$jobsHtml.$reviewsHtml.'</section>', ['noindex' => true]);
}

function request_form_page(): never
{
    $u = require_user('manager');
    $venues = sql('SELECT * FROM venues WHERE manager_id = ? ORDER BY id', [$u['id']])->fetchAll();
    if (!$venues) {
        redirect('/darboviete/nauja');
    }
    if (!manager_verified($u)) {
        page('Laukiame patvirtinimo', '<section class="wrap narrow"><h1>Tikriname jūsų darbovietę</h1>'.verification_note().'<p><a class="btn ghost" href="/mano">Grįžti į Mano GANG</a></p></section>', ['noindex' => true]);
    }
    $venueOptions = array_column($venues, 'name', 'id');
    $v = ['venue_id' => (string) $venues[0]['id'], 'role' => '', 'kind' => 'skubu', 'people' => '1', 'dates' => '', 'pay' => '', 'min_experience' => '1', 'description' => '', 'event_title' => '', 'team_first' => ''];
    // Events: more roles on the same dates, each [role, people, pay]; every role becomes its own request.
    $extras = [];
    // "Pakartoti": the form comes filled in from an earlier request; only the dates are picked again.
    $from = !is_post() && ctype_digit($_GET['pagal'] ?? '') ? find_request((int) $_GET['pagal']) : null;
    if ($from && (int) $from['manager_id'] === (int) $u['id']) {
        preg_match('/^\d+(?:,\d+)?/', (string) $from['pay'], $pay);
        // Older requests kept the working hours in a separate field; they now go at the top of the description.
        $description = ($from['hours'] !== '' ? 'Darbo laikas: '.$from['hours']."\n\n" : '').$from['description'];
        $v = ['venue_id' => (string) $from['venue_id'], 'role' => $from['role'], 'kind' => $from['kind'], 'people' => (string) $from['people'], 'dates' => '',
            'pay' => valid_pay($pay[0] ?? '') ? $pay[0] : '', 'min_experience' => (string) $from['min_experience'], 'description' => $description, 'event_title' => $from['event_title'], 'team_first' => ''];
        if ($from['event_id'] && !ctype_digit($_GET['pakeisti'] ?? '')) {
            foreach (sql('SELECT role, people, pay FROM requests WHERE event_id = ? AND id <> ? ORDER BY id', [$from['event_id'], $from['id']])->fetchAll() as $x) {
                preg_match('/^\d+(?:,\d+)?/', (string) $x['pay'], $xp);
                $extras[] = ['role' => $x['role'], 'people' => (string) $x['people'], 'pay' => $xp[0] ?? ''];
            }
        }
    }
    // "Mano komanda": people the manager already worked with can be invited personally.
    $team = array_column(team_members((int) $u['id']), null, 'id');
    $invite = [];
    if (ctype_digit($_GET['kviesti'] ?? '')) {
        $invite = [(int) $_GET['kviesti']];
    } elseif ($from && (int) $from['manager_id'] === (int) $u['id']) {
        $invite = array_map('intval', sql('SELECT worker_id FROM contracts WHERE request_id = ? AND cancelled_at IS NULL', [$from['id']])->fetchAll(PDO::FETCH_COLUMN));
    }
    // "Rasti pakaitinį": a worker will not come tomorrow. Urgent request for tomorrow, the rest of the team invited.
    $replacing = $from && (int) $from['manager_id'] === (int) $u['id'] && ctype_digit($_GET['pakeisti'] ?? '') ? (int) $_GET['pakeisti'] : 0;
    if ($replacing) {
        $v['kind'] = 'skubu';
        $v['dates'] = tomorrow().':'.tomorrow();
        $invite = array_values(array_diff(array_keys($team), [$replacing]));
    }
    $errors = [];
    if (is_post()) {
        check_csrf();
        $invite = array_map('intval', array_filter((array) ($_POST['invite'] ?? []), 'is_string'));
        foreach ($v as $k => $_) {
            $v[$k] = input($k);
        }
        $extras = [];
        // Extra roles count only with "Renginiui reikalingi darbuotojai kelioms pareigoms" ticked.
        $eventOn = input('event') === '1' && in_array($v['kind'], EVENT_KINDS, true);
        foreach ($eventOn ? (array) ($_POST['extra_role'] ?? []) : [] as $i => $role) {
            $line = ['role' => trim((string) $role), 'people' => trim((string) ($_POST['extra_people'][$i] ?? '1')), 'pay' => trim((string) ($_POST['extra_pay'][$i] ?? ''))];
            if ($line['role'] !== '' || $line['pay'] !== '') {
                $extras[] = $line;
            }
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
        if (mb_strlen($v['description']) < REQUEST_DESC_MIN || mb_strlen($v['description']) > 3000) {
            $errors['description'] = 'Nurodykite darbo laiką ir trumpai aprašykite darbo pobūdį bei pareigas (bent '.REQUEST_DESC_MIN.' simbolių).';
        }
        $seen = [$v['role']];
        foreach (array_slice($extras, 0, EVENT_MAX_ROLES - 1) as $line) {
            if (!isset(ROLES[$line['role']]) || in_array($line['role'], $seen, true)) {
                $errors['extra'] = 'Prie kiekvienos papildomos eilutės pasirinkite kitas pareigas: tos pačios pareigos negali kartotis.';
            } elseif ((int) $line['people'] < 1 || (int) $line['people'] > 20) {
                $errors['extra'] = 'Darbuotojų skaičius prie kiekvienų pareigų turi būti nuo 1 iki 20.';
            } elseif (!valid_pay($line['pay'])) {
                $errors['extra'] = 'Prie kiekvienų pareigų nurodykite atlygį į rankas: skaičių iki 100, pavyzdžiui, 10 arba 12,50.';
            }
            $seen[] = $line['role'];
        }
        $extras = array_slice($extras, 0, EVENT_MAX_ROLES - 1);
        if ($eventOn && !$extras) {
            $errors['extra'] = 'Pasirinkite bent vienas papildomas pareigas arba nuimkite varnelę „Renginiui reikalingi darbuotojai kelioms pareigoms“.';
        }
        if ($extras && (mb_strlen($v['event_title']) < 3 || mb_strlen($v['event_title']) > 120)) {
            $errors['event_title'] = 'Įrašykite renginio pavadinimą, pavyzdžiui, „Įmonės kalėdinis vakarėlis“.';
        }
        $teamFirst = $v['team_first'] === '1';
        if ($teamFirst && !array_intersect($invite, array_keys($team))) {
            $errors['team_first'] = 'Pažymėkite bent vieną komandos narį arba atžymėkite „Pirmiausia pasiūlyti komandai“.';
        }
        if (!$errors) {
            $lines = [['role' => $v['role'], 'people' => $people, 'pay' => $v['pay']], ...$extras];
            $publicAt = $teamFirst ? date('Y-m-d H:i:s', time() + TEAM_FIRST_HOURS[$v['kind']] * 3600) : null;
            $ids = [];
            foreach ($lines as $line) {
                $ids[] = insert('requests', [
                    'venue_id' => (int) $v['venue_id'], 'role' => $line['role'], 'kind' => $v['kind'], 'people' => (int) $line['people'],
                    'start_date' => $ranges[0][0], 'end_date' => end($ranges)[1] !== $ranges[0][0] ? end($ranges)[1] : null, 'dates' => count($ranges) > 1 ? $v['dates'] : '', 'hours' => '',
                    'pay' => pay_label(str_replace(',', '.', $line['pay'])), 'min_experience' => (int) $v['min_experience'], 'description' => $v['description'], 'created_at' => now(),
                    'public_at' => $publicAt, 'event_title' => $extras ? $v['event_title'] : '',
                ]);
            }
            if (count($ids) > 1) {
                sql('UPDATE requests SET event_id = ? WHERE id IN ('.implode(',', $ids).')', [$ids[0]]);
            }
            $id = $ids[0];
            $requests = array_map('find_request', $ids);
            // A team member is invited to the role they do; for an event, the first matching one.
            $invited = [];
            foreach (array_unique($invite) as $wid) {
                if (!isset($team[$wid])) {
                    continue;
                }
                $fits = array_values(array_filter($requests, fn ($r) => in_array($r['role'], from_list($team[$wid]['roles']), true)));
                if (invite_worker($fits[0] ?? $requests[0], $team[$wid])) {
                    $invited[] = $wid;
                }
            }
            $sent = 0;
            if (!$publicAt) {
                foreach ($requests as $r) {
                    $sent += notify_workers($r, $invited);
                }
            }
            $what = count($ids) > 1 ? 'Renginio paieška paskelbta: '.count($ids).' pareigos.' : 'Paieška paskelbta.';
            $teamNote = $invited ? ' Asmeninį kvietimą gavo jūsų komandos narių: '.count($invited).'.' : '';
            flash(match (true) {
                $publicAt !== null => $what.$teamNote.' Kitiems tinkamiems darbuotojams pasiūlymą išsiųsime '.when_lt($publicAt).', jei vietos dar nebus užimtos.',
                $sent > 0 => "$what Pranešimą siunčiame tinkamiems darbuotojams: $sent.$teamNote Atsiliepimus matysite šiame puslapyje ir gausite el. paštu.",
                $teamNote !== '' => $what.$teamNote.' Atsiliepimus matysite šiame puslapyje ir gausite el. paštu.',
                default => $what.' Tinkamų registruotų darbuotojų kol kas nėra, tačiau skelbimą matys visi svetainės lankytojai. Pakvieskite savo darbuotojus prisijungti – nuorodą rasite žemiau.',
            });
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
        $venueData .= ' data-v'.(int) $venue['id'].'="'.e($venue['name'].' · '.(CITIES[$venue['city']] ?? '')).'" data-c'.(int) $venue['id'].'="'.e($venue['city']).'"';
    }
    // Usual pay per role in the manager's cities, for the hint under the pay field.
    $guide = [];
    foreach (array_unique(array_column($venues, 'city')) as $city) {
        foreach (ROLES as $role => $_) {
            if ($g = pay_guide($role, $city)) {
                $guide[$role][$city] = [$g[0], $g[1], $g[2], ROLES_DATIVE[$role] ?? '', CITIES_IN[$city] ?? ''];
            }
        }
    }
    $venueData .= " data-guide='".e(json_encode($guide, JSON_UNESCAPED_UNICODE))."'";
    $repeat = $replacing ? '<p class="notice tip">Pakaitinio darbuotojo paieška rytojui. Forma užpildyta pagal ankstesnę paiešką, pažymėti jūsų komandos nariai gaus asmeninį kvietimą. Patikrinkite duomenis ir paskelbkite.</p>'
        : ($from && (int) $from['manager_id'] === (int) $u['id'] ? '<p class="notice tip">Forma užpildyta pagal ankstesnę paiešką. Pažymėkite naujas datas ir, jei reikia, pakoreguokite kitus laukus.</p>' : '');
    $step = !sql('SELECT 1 FROM requests r JOIN venues v ON v.id = r.venue_id WHERE v.manager_id = ? LIMIT 1', [$u['id']])->fetchColumn() ? onboarding_step(3) : '';
    page('Nauja paieška', '<section class="wrap request-wrap">'.$step.'<h1>Kokio darbuotojo ieškote?</h1>'.$repeat.($errors ? '<p class="notice tip">Kai kurie laukai užpildyti ne iki galo.</p>' : '')
        .'<form method="post" class="form request-form" novalidate'.$venueData.'>'.csrf_field()
        .'<div class="live-preview" aria-live="polite"><h2 class="eyebrow">Taip skelbimą matys darbuotojai</h2><p class="preview-count" data-p="count" hidden></p><div class="preview-cards"><div class="card job-card"><div class="card-link"><div class="card-head"><div><h3 data-p="role">Pareigos</h3>'
        .'<p class="event-name" data-p="event" hidden></p><p class="meta" data-p="venue"></p></div></div><p class="card-pay" data-p="pay" hidden><strong></strong> <span>/val. į rankas</span></p><p class="tags"><span class="tag" data-p="kind"></span><span class="tag event" data-p="eventtag" hidden>Renginys</span></p>'
        .'<div class="card-when"><div><strong data-p="when">Datos nepažymėtos</strong></div></div></div></div></div></div>'
        .(count($venues) > 1 ? '<label>Darbovietė <select name="venue_id">'.options($venueOptions, $v['venue_id']).'</select>'.err($errors, 'venue_id').'</label>' : '<input type="hidden" name="venue_id" value="'.(int) $venues[0]['id'].'">')
        .'<div class="row"><label>Pareigos <select name="role" required>'.options(['' => 'Pasirinkite'] + ROLES, $v['role']).'</select>'.err($errors, 'role').'</label>'
        .'<label>Darbuotojų skaičius <input type="number" name="people" min="1" max="20" value="'.e($v['people']).'">'.err($errors, 'people').'</label></div>'
        .'<fieldset><legend>Darbo trukmė</legend>'.$kinds.err($errors, 'kind').'</fieldset>'
        .'<fieldset><legend>Reikalaujama darbo patirtis</legend><p class="hint">Apie skelbimą el. paštu informuosime tik reikalavimą atitinkančius darbuotojus.</p>'.radios('min_experience', EXPERIENCE_REQUIRED, $v['min_experience']).err($errors, 'min_experience').'</fieldset>'
        .'<fieldset class="when"><legend>Kada reikalingas darbuotojas?</legend>'
        .'<p class="hint when-hint" data-nuolat="Pažymėkite darbo pradžios dieną.">Spustelėkite dieną, kad pažymėtumėte vieną dieną. Spustelėjus kitą dieną, pažymimas laikotarpis nuo–iki. Galite pažymėti kelis laikotarpius, pavyzdžiui, kelis savaitgalius.</p>'
        .'<div class="picker" data-min="'.today().'" data-max="'.availability_last_day().'"><noscript><p class="notice">Kalendoriui reikalingas „JavaScript“. Įjunkite jį naršyklėje.</p></noscript></div>'
        .'<input type="hidden" name="dates" value="'.e($v['dates']).'">'.err($errors, 'dates').'</fieldset>'
        .'<label class="pay-field">Atlygis į rankas <span class="input-suffix"><input name="pay" value="'.e($v['pay']).'" inputmode="decimal" maxlength="6" required placeholder="10" pattern="\d{1,3}([.,]\d{1,2})?" aria-describedby="pay-live"><span aria-hidden="true">€/val.</span></span>'
        .err($errors, 'pay').'<span class="err-live" id="pay-live" role="alert" hidden>'.e(PAY_ERROR).'</span><span class="hint pay-guide" id="pay-guide" hidden></span></label>'
        .event_roles_fieldset($extras, $v['event_title'], $errors, in_array($v['kind'], EVENT_KINDS, true))
        .'<label>Darbo laikas ir aprašymas <textarea name="description" rows="6" maxlength="3000" required data-min="'.REQUEST_DESC_MIN.'" placeholder="Pavyzdžiui: penktadienį ir šeštadienį 16–24 val. Dėl darbuotojo ligos ieškome virėjo karštajai virtuvei. Meniu nesudėtingas, dirbsite kartu su su-šefu.">'.e($v['description']).'</textarea><span class="counter meta" aria-live="polite"></span>'.err($errors, 'description').'</label>'
        .team_invite_fieldset($team, $invite, $v['team_first'] === '1', $errors)
        .'<div class="submit-bar"><button class="btn">Paskelbti ir informuoti darbuotojus</button></div></form></section>'.date_picker_script().request_preview_script().counter_script(), ['noindex' => true]);
}

// Team members to invite personally along with the new request; nothing when the team is empty.
// "Pirmiausia pasiūlyti komandai" holds the request back from everyone else for TEAM_FIRST_HOURS.
function team_invite_fieldset(array $team, array $checked, bool $teamFirst = false, array $errors = []): string
{
    if (!$team) {
        return '';
    }
    $items = '';
    foreach ($team as $w) {
        $together = (int) $w['jobs'] ? 'kartu dirbta: '.(int) $w['jobs'] : 'prisijungė pagal jūsų kvietimą';
        $items .= '<label class="choice team-choice"><input type="checkbox" name="invite[]" value="'.(int) $w['id'].'"'.(in_array((int) $w['id'], $checked, true) ? ' checked' : '').'>'
            .avatar($w, 'small').'<span><span class="choice-name">'.e(first_name($w['name'])).'</span><small>'.e(labels($w['roles'], ROLES)).' · '.$together.'</small></span></label>';
    }
    return '<fieldset class="team-invite"><legend>Pakviesti savo komandą</legend>'
        .'<p class="hint">Pažymėti jūsų komandos nariai gaus asmeninį kvietimą el. paštu ir matys jį savo paskyroje.</p>'
        .'<div class="choices">'.$items.'</div>'
        .'<label class="choice team-first"><input type="checkbox" name="team_first" value="1"'.($teamFirst ? ' checked' : '').'>'
        .'<span><span class="choice-name">Pirmiausia pasiūlyti komandai</span><small>Kitiems tinkamiems darbuotojams pasiūlymą išsiųsime tik po '.TEAM_FIRST_HOURS['skubu'].' val. skubiam darbui, po '.TEAM_FIRST_HOURS['trumpai'].' val. trumpam ir po '.TEAM_FIRST_HOURS['sezonas'].' val. sezoniniam ar nuolatiniam darbui, jei vietos dar nebus užimtos. Neprivaloma.</small></span></label>'
        .err($errors, 'team_first').'</fieldset>';
}

const EVENT_MAX_ROLES = 6;
// Several roles for one event only with "Skubiai": a one-day, repeated one-day or few-day event.
const EVENT_KINDS = ['skubu'];

// Events need several roles on the same dates: one row per extra role, each posted as its own request.
function event_roles_fieldset(array $extras, string $title, array $errors, bool $shown = true): string
{
    $row = fn (array $x) => '<div class="extra-row"><label>Pareigos <select name="extra_role[]">'.options(['' => 'Pasirinkite'] + ROLES, $x['role']).'</select></label>'
        .'<label>Darbuotojų skaičius <input type="number" name="extra_people[]" min="1" max="20" value="'.e($x['people']).'"></label>'
        .'<label>Atlygis į rankas <span class="input-suffix"><input name="extra_pay[]" value="'.e($x['pay']).'" inputmode="decimal" maxlength="6" placeholder="10"><span aria-hidden="true">€/val.</span></span></label>'
        .'<button type="button" class="btn small ghost" data-rm-role aria-label="Pašalinti šias pareigas">×</button></div>';
    $on = $extras && $shown;
    // Without the tick the part stays visible but greyed out and disabled, so nothing from it is sent.
    $rows = implode('', array_map($row, $extras ?: [['role' => '', 'people' => '1', 'pay' => '']]));
    return '<fieldset class="event-roles'.($on ? ' on' : '').'"'.($shown ? '' : ' hidden').'><legend class="sr-only">Renginys</legend>'
        .'<label class="choice event-toggle"><input type="checkbox" name="event" value="1"'.($on ? ' checked' : '').'>'
        .'<span><span class="choice-name">Renginiui reikalingi darbuotojai kelioms pareigoms</span><small>Pavyzdžiui, barmenai, padavėjai ir virėjai tam pačiam renginiui.</small></span></label>'
        .'<fieldset class="event-body"'.($on ? '' : ' disabled').'>'
        .'<p class="hint">Pridėkite kitas pareigas su darbuotojų skaičiumi ir atlygiu. Datos, darbo laikas ir aprašymas bus bendri, o kiekvienos pareigos bus paskelbtos kaip atskiras skelbimas su renginio pavadinimu.</p>'
        .'<label class="event-title">Renginio pavadinimas <input name="event_title" value="'.e($title).'" maxlength="120" placeholder="Pavyzdžiui: įmonės kalėdinis vakarėlis, 120 svečių">'.err($errors, 'event_title').'</label>'
        .'<div class="extra-rows">'.$rows.'</div>'.err($errors, 'extra')
        .'<button type="button" class="btn small ghost" data-add-role>+ Pridėti pareigas</button></fieldset>'
        .'<template id="extra-row">'.$row(['role' => '', 'people' => '1', 'pay' => '']).'</template></fieldset>';
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
    var venue = f.dataset['v' + val('venue_id')] || '';
    function venueLine(n) { return venue + (n > 1 ? ' · reikia darbuotojų: ' + n : ''); }
    el('role').textContent = role && role.value ? role.options[role.selectedIndex].text : 'Pareigos';
    el('venue').textContent = venueLine(+val('people'));
    var dates = f.querySelector('input[name=dates]');
    el('when').textContent = (dates && dates.dataset.label) || 'Datos nepažymėtos';
    var k = el('kind');
    k.textContent = kind ? kind.parentNode.querySelector('.choice-name').textContent : '';
    k.className = 'tag' + (kind ? ' k-' + kind.value : '') + (kind && kind.value === 'skubu' ? ' urgent' : '');
    var pay = val('pay').trim().replace('.', ','), ok = payOk(pay);
    el('pay').hidden = !ok;
    el('pay').firstChild.textContent = pay + ' €';
    // Event: every extra role shows straight away as its own card, the same way workers will see it.
    var on = f.querySelector('input[name=event]').checked, title = val('event_title').trim();
    el('event').hidden = el('eventtag').hidden = !on;
    el('event').textContent = title || 'Renginio pavadinimas';
    var list = f.querySelector('.preview-cards'), main = list.firstElementChild;
    while (list.children.length > 1) list.removeChild(list.lastElementChild);
    if (on) f.querySelectorAll('.extra-row').forEach(function (row) {
      var sel = row.querySelector('select'); if (!sel.value) return;
      var card = main.cloneNode(true), xp = row.querySelector('[name="extra_pay[]"]').value.trim().replace('.', ',');
      card.querySelector('[data-p=role]').textContent = sel.options[sel.selectedIndex].text;
      card.querySelector('[data-p=venue]').textContent = venueLine(+row.querySelector('[name="extra_people[]"]').value);
      var cp = card.querySelector('[data-p=pay]'); cp.hidden = !payOk(xp); cp.firstChild.textContent = xp + ' €';
      card.querySelectorAll('[data-p]').forEach(function (x) { x.removeAttribute('data-p'); });
      list.appendChild(card);
    });
    var n = list.children.length;
    el('count').hidden = n < 2;
    el('count').textContent = 'Bus paskelbti ' + n + ' skelbimai';
    list.classList.toggle('many', n > 1);
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
  // Usual pay for the chosen role in the venue's city, and how the typed amount compares.
  var guide = JSON.parse(f.dataset.guide || '{}'), guideOut = document.getElementById('pay-guide');
  function money(n) { return String(n).replace('.', ','); }
  function showGuide() {
    var role = f.querySelector('select[name=role]').value, city = f.dataset['c' + val('venue_id')] || '', g = guide[role] && guide[role][city];
    if (!g) { guideOut.hidden = true; return; }
    var text = (g[2] ? g[4] + ' ' + g[3] + ' GANG paieškose' : g[3].charAt(0).toUpperCase() + g[3].slice(1)) + ' dažniausiai siūloma ' + money(g[0]) + '\u2060–\u2060' + money(g[1]) + '\u00a0€/val. į rankas.';
    var p = payIn.value.trim().replace(',', '.');
    if (payOk(payIn.value.trim())) {
      if (+p < g[0]) text += ' Jūsų siūlomas atlygis mažesnis nei įprasta, todėl darbuotoją rasti gali būti sunkiau.';
      else if (+p >= g[1]) text += ' Skelbimas bus pažymėtas kaip „Geras atlygis“.';
    }
    guideOut.textContent = text; guideOut.hidden = false;
  }
  // Event roles: the tick turns the part on; without it the part is greyed out and nothing from it is sent.
  var box = f.querySelector('.event-roles'), body = box.querySelector('.event-body'), tick = box.querySelector('input[name=event]');
  var rows = box.querySelector('.extra-rows'), tpl = document.getElementById('extra-row');
  function addRow() { rows.appendChild(tpl.content.cloneNode(true)); }
  function syncEvent() {
    if (!rows.children.length) addRow();
    body.disabled = !tick.checked;
    box.classList.toggle('on', tick.checked);
    box.querySelector('[data-add-role]').hidden = rows.children.length >= 5;
  }
  // Only for Skubiai: for any other kind the part is hidden and switched off.
  f.querySelectorAll('input[name=kind]').forEach(function (k) {
    k.addEventListener('change', function () {
      var shown = k.value === 'skubu';
      box.hidden = !shown;
      if (!shown && tick.checked) tick.checked = false;
      syncEvent(); update();
    });
  });
  tick.addEventListener('change', function () {
    syncEvent();
    if (tick.checked) (box.querySelector('input[name=event_title]').value ? rows.querySelector('select') : box.querySelector('input[name=event_title]')).focus();
  });
  box.addEventListener('click', function (e) {
    if (e.target.closest('[data-add-role]')) { addRow(); syncEvent(); rows.lastElementChild.querySelector('select').focus(); }
    var rm = e.target.closest('[data-rm-role]'); if (rm) { rm.closest('.extra-row').remove(); syncEvent(); }
  });
  syncEvent();
  f.addEventListener('input', update); f.addEventListener('change', update); f.addEventListener('click', function () { setTimeout(update); });
  f.addEventListener('input', showGuide); f.addEventListener('change', showGuide);
  update(); showGuide();
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
    $responded = $u && sql('SELECT 1 FROM responses WHERE request_id = ? AND worker_id = ?', [$id, $u['id']])->fetchColumn();
    if (!$isOpen && !$owner && !$responded && !is_admin($u)) {
        not_found();
    }
    // Team first: until public_at only the owner and the invited team see the request.
    $teamOnly = !empty($r['public_at']) && $r['public_at'] > now();
    if ($teamOnly && !$owner && !$responded && !is_admin($u) && !($u && in_array((int) $u['id'], invited_ids($id), true))) {
        not_found();
    }
    $role = ROLES[$r['role']] ?? $r['role'];
    $city = CITIES[$r['city']] ?? '';
    $place = full_address($r);
    [$avg, $n] = venue_rating((int) $r['venue_id']);
    $venueReliable = venue_reliability((int) $r['venue_id']);
    // Dates as separate chips; pay split into the amount and its unit so the amount stands out.
    $dates = !empty($r['dates']) ? array_map(fn ($p) => span_lt(...explode(':', $p)), explode(',', $r['dates'])) : [period($r)];
    $chips = '<ul class="date-chips">'.implode('', array_map(fn ($d) => '<li>'.e($d).'</li>', $dates)).'</ul>';
    $peopleText = (string) (int) $r['people'];
    // Some days already taken: everyone sees which days are still open (and how many people each needs),
    // the owner also sees the filled days.
    if ($isOpen && request_splittable($r)) {
        $need = request_needs($r);
        $ahead = array_values(array_filter(ranges_days(request_ranges($r)), fn ($d) => $d >= today()));
        if ($need && (array_keys($need) !== $ahead || max($need) < (int) $r['people'])) {
            $uneven = count(array_unique($need)) > 1;
            $peopleText = $uneven ? 'iki '.max($need) : (string) max($need);
            $chipDays = $owner ? $ahead : array_keys($need);
            $chips = '<ul class="date-chips">'.implode('', array_map(function ($x) use ($need, $uneven) {
                $n = $need[$x[0]] ?? 0;
                return $n ? '<li>'.e(span_lt($x[0], $x[1])).($uneven ? ' <small>· reikia '.$n.'</small>' : '').'</li>'
                    : '<li class="full">'.e(span_lt($x[0], $x[1])).' <small>· užpildyta</small></li>';
            }, days_ranges($chipDays, fn ($d) => $need[$d] ?? 0))).'</ul>';
        }
    }
    // "Geras atlygis" helps someone still choosing; the owner and a worker who already applied or got the job don't need it.
    $mineStatus = $u ? sql('SELECT status FROM responses WHERE request_id = ? AND worker_id = ?', [$id, $u['id']])->fetchColumn() : false;
    $showGood = !$owner && !in_array($mineStatus, ['sent', 'accepted'], true) && good_pay($r);
    $payBox = '';
    if ($r['pay'] !== '') {
        $payBox = preg_match('/^([\d,]+)\s*€/u', $r['pay'], $m)
            ? '<div class="pay-box"><span class="label">Atlygis į rankas</span><strong>'.e($m[1]).'&nbsp;€</strong><p class="pay-unit"><span class="unit">už valandą</span>'.($showGood ? '<span class="tag good">Geras atlygis</span>' : '').'</p></div>'
            : '<div class="pay-box"><span class="label">Atlygis</span><strong class="text">'.e($r['pay']).'</strong></div>';
    }
    $facts = '<dl class="job-facts">'
        .'<div><dt>Darbo trukmė</dt><dd>'.e(KINDS[$r['kind']] ?? '').'</dd></div>'
        .'<div><dt>Reikalinga patirtis</dt><dd>'.e(EXPERIENCE_REQUIRED[(int) $r['min_experience']] ?? '').'</dd></div>'
        .'<div><dt>Reikia darbuotojų</dt><dd>'.e($peopleText).'</dd></div>'
        .'<div><dt>Darbovietė</dt><dd><a href="/darboviete/'.(int) $r['venue_id'].'">'.e($r['venue_name']).'</a></dd></div>'
        .'<div><dt>Adresas</dt><dd>'.e($place).'</dd></div>'
        .($venueReliable !== null && $u && $u['kind'] === 'worker' ? '<div><dt>Darbdavio patikimumas</dt><dd>'.$venueReliable.' % sutartų darbų įvyko</dd></div>' : '').'</dl>';
    $when = '<section class="job-sec"><h2>Kada</h2>'.$chips.($r['hours'] !== '' ? '<p class="job-hours"><b>Darbo laikas:</b> '.e($r['hours']).'</p>' : '').'</section>';
    $venue = sql('SELECT * FROM venues WHERE id = ?', [$r['venue_id']])->fetch();
    $venueUrl = '/darboviete/'.(int) $r['venue_id'];
    $venueSec = venue_section_html($venue, $venueUrl, $city, sees_venue_reviews($u, $venue) ? stars($avg, $n) : '');
    $kindTag = kind_tag($r['kind']);
    $action = $owner ? owner_panel($r, $isOpen) : worker_panel($r, $u, $isOpen);
    $desc = nl2br(e($r['description']));
    $e = 'e';
    $ld = '';
    // Other roles of the same event.
    $event = '';
    if ($r['event_id']) {
        $siblings = sql(REQUEST_SELECT." WHERE r.event_id = ? AND r.id <> ? AND $where ORDER BY r.id", array_merge([$r['event_id'], $id], $params))->fetchAll();
        if (!$teamOnly) {
            $siblings = array_filter($siblings, fn ($x) => empty($x['public_at']) || $x['public_at'] <= now() || $owner);
        }
        $event = $siblings ? '<section class="job-sec"><h2>Kitos šio renginio pareigos</h2><ul class="event-links">'
            .implode('', array_map(fn ($x) => '<li><a href="/darbas/'.(int) $x['id'].'">'.e(ROLES[$x['role']] ?? '').'</a><span class="meta">'.e($x['pay']).' · reikia: '.(int) $x['people'].'</span></li>', $siblings)).'</ul></section>' : '';
    }
    $eventTag = $r['event_title'] !== '' ? '<span class="tag event">Renginys</span>' : '';
    $eventLine = $r['event_title'] !== '' ? '<p class="event-name">'.e($r['event_title']).'</p>' : '';
    if ($isOpen && !$teamOnly) {
        $ld = '<script type="application/ld+json">'.json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => $role,
            'description' => nl2br(e($r['description'])),
            'datePosted' => substr($r['created_at'], 0, 10),
            'validThrough' => ($r['end_date'] ?: ($r['kind'] === 'nuolat' ? date('Y-m-d', strtotime($r['created_at'].' +30 days')) : $r['start_date'])).'T23:59:00+02:00',
            'employmentType' => ['skubu' => 'PER_DIEM', 'trumpai' => 'TEMPORARY', 'sezonas' => 'TEMPORARY', 'nuolat' => 'FULL_TIME'][$r['kind']],
            'hiringOrganization' => ['@type' => 'Organization', 'name' => $r['venue_name']]
                + ($r['venue_website'] ? ['sameAs' => $r['venue_website']] : [])
                + ($r['venue_logo'] ? ['logo' => rtrim(config()['public_url'], '/').venue_logo_url(['id' => $r['venue_id'], 'logo' => $r['venue_logo']])] : []),
            'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress'] + (trim($r['address']) !== '' ? ['streetAddress' => trim($r['address'])] : []) + ['addressLocality' => $city, 'addressCountry' => 'LT']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script>';
    }
    page("$role – {$r['venue_name']}, $city", <<<HTML
<article class="wrap job">
  <p class="back"><a href="/darbai">← Visi darbo pasiūlymai</a></p>
  <header class="job-head">
    <p class="job-tags">$kindTag$eventTag</p>
    <h1>{$e($role)}</h1>$eventLine
  </header>
  <div class="job-grid">
    <div class="job-side">$payBox$facts</div>
    <div class="job-main">
      $venueSec
      $when
      <section class="job-sec"><h2>Darbo aprašymas</h2><div class="desc">$desc</div></section>
      $event
      $action
    </div>
  </div>
</article>
HTML, [
        'description' => mb_substr("$role, {$r['venue_name']}, $city: ".preg_replace('/\s+/', ' ', $r['description']), 0, 155),
        'canonical' => '/darbas/'.$id,
        'head' => $ld,
        'noindex' => !$isOpen || $teamOnly,
    ]);
}

// "Apie darbovietę" at the top of the job page: what the employer filled in about the venue, visible to everyone.
// The city and (for members who may see them) the venue's ratings sit under the name.
function venue_section_html(array $venue, string $url, string $city = '', string $rating = ''): string
{
    $type = implode(' · ', array_filter([venue_type_text($venue), $city], fn ($x) => $x !== ''));
    $facts = array_filter([
        'Darbo laikas' => $venue['hours'],
        'Komanda' => TEAM_SIZES[$venue['team_size']] ?? '',
    ]);
    $factsHtml = $facts ? '<dl class="venue-facts">'.implode('', array_map(fn ($k, $val) => '<div><dt>'.$k.'</dt><dd>'.e($val).'</dd></div>', array_keys($facts), $facts)).'</dl>' : '';
    return '<section class="job-sec job-venue-sec"><h2>Apie darbovietę</h2><div class="venue-box">'
        .'<div class="venue-card-head">'.venue_logo($venue, 'small').'<div><strong><a href="'.$url.'">'.e($venue['name']).'</a></strong>'
        .($type !== '' ? '<span class="meta">'.e($type).'</span>' : '')
        .($rating !== '' ? '<span class="meta venue-rating">'.$rating.'</span>' : '').'</div></div>'
        .($venue['about'] ? '<div class="desc">'.nl2br(e($venue['about'])).'</div>' : '')
        .$factsHtml
        .venue_perks_html($venue, '<h3>Darbuotojams siūlome</h3>')
        .venue_links_html($venue)
        .'<p class="venue-more"><a href="'.$url.'">Visa darbovietės informacija →</a></p></div></section>';
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
    // A withdrawn application can be sent again while the request is open.
    $again = $mine && $mine['status'] === 'withdrawn' && $isOpen;
    if ($mine && $mine['status'] === 'sent' && $mine['recheck_at'] && $isOpen) {
        return recheck_panel($r);
    }
    if ($mine && !$again) {
        return match ($mine['status']) {
            'accepted' => accepted_panel($r, $u),
            'declined' => '<div class="panel"><p>Dėkojame už susidomėjimą. Šį kartą darbdavys pasirinko kitą kandidatą. Kviečiame peržiūrėti <a href="/darbai">kitus darbo pasiūlymus</a>.</p></div>',
            'withdrawn' => '<div class="panel"><p>Savo atsiliepimą atšaukėte.</p></div>',
            'cancelled' => '<div class="panel"><p>Šis GANG kontraktas atšauktas. Kviečiame peržiūrėti <a href="/darbai">kitus darbo pasiūlymus</a>.</p></div>',
            default => '<div class="panel"><h2>Jūsų atsiliepimas išsiųstas</h2>'
                .(request_splittable($r) && $mine['dates'] !== '' ? '<p><b>Jūsų nurodytos dienos:</b> '.e(days_text(response_days($mine, $r))).'</p>' : '')
                .'<p>Darbdavys mato jūsų profilį ir įvertinimus. Darbdaviui patvirtinus jūsų kandidatūrą, el. paštu gausite jo kontaktus ir galėsite susirašinėti GANG pokalbyje.</p>'
                .($isOpen ? response_note_form($r, $mine) : '')
                .post_button('/darbas/'.$r['id'].'/atsaukti', 'Atšaukti atsiliepimą', [], 'btn small ghost', 'Ar tikrai norite atšaukti atsiliepimą?').'</div>',
        };
    }
    if (!$isOpen) {
        return '<div class="panel"><p>Šis darbo pasiūlymas nebegalioja.</p></div>';
    }
    // The employer sees the candidate's name, roles and experience, so the profile comes first.
    if (!profile_complete($u)) {
        return '<div class="panel" id="atsiliepti"><h2>Atsiliepti į pasiūlymą</h2><p>Prieš teikdami kandidatūrą, užpildykite profilį: darbdavys matys jūsų vardą, pareigas ir patirtį.</p>'
            .'<p><a class="btn" href="/profilis">Užpildyti profilį</a></p></div>';
    }
    $below = (int) $r['min_experience'] > 1 && (role_experience((int) $u['id'])[$r['role']] ?? 0) < (int) $r['min_experience']
        ? '<p class="notice tip">Darbdavio reikalaujama patirtis: '.e(mb_strtolower(EXPERIENCE_REQUIRED[(int) $r['min_experience']] ?? '')).'. Jūsų profilyje šioms pareigoms nurodyta mažesnė patirtis, todėl rekomenduojame žinutėje trumpai paaiškinti, kodėl šis darbas jums tinka.</p>'
        : '';
    $invited = sql('SELECT 1 FROM invites WHERE request_id = ? AND worker_id = ?', [$r['id'], $u['id']])->fetchColumn()
        ? '<p class="notice ok invite-note">Darbdavys jus pakvietė asmeniškai, nes esate jo komandos narys.</p>'
        : '';
    $again = $again ? '<p class="notice tip">Kandidatūrą buvote atšaukę. Jei vėl galite dirbti, pateikite ją iš naujo.</p>' : '';
    // Days of this job the worker already works through GANG: their own call whether to apply anyway.
    $busy = array_intersect_key(gang_work_days((int) $u['id'], today()), array_flip(array_keys(request_needs($r))));
    $other = [];
    foreach ($busy as $d => $cs) {
        foreach ($cs as $c) {
            $other[(int) $c['id']] ??= $c + ['days' => []];
            $other[(int) $c['id']]['days'][] = $d;
        }
    }
    $overlap = $busy ? '<div class="notice tip overlap"><p><strong>Šiomis dienomis jau turite GANG darbą.</strong></p><ul>'
        .implode('', array_map(fn ($c) => '<li>'.e(days_text($c['days']).': '.$c['venue_name'].', '.mb_strtolower(ROLES[$c['role']] ?? '')).'</li>', $other)).'</ul>'
        .'<p>Ar tikrai norite kandidatuoti ir į šį darbą?'.(request_splittable($r) && count(request_needs($r)) > 1 ? ' Jei tomis dienomis dirbti negalėsite, žemiau nuimkite jų žymėjimą.' : '').'</p></div>' : '';
    return '<div class="panel" id="atsiliepti"><h2>Atsiliepti į pasiūlymą</h2>'.$again.$invited.$below.$overlap
        .'<form method="post" action="/darbas/'.(int) $r['id'].'/atsiliepti" class="form">'.csrf_field()
        .days_fieldset($r, array_keys($busy))
        .'<label>Žinutė darbdaviui <small>(neprivaloma)</small><textarea name="note" rows="3" maxlength="1000" placeholder="Pavyzdžiui: Laba diena! Galiu atvykti per valandą, turiu dvejų metų patirtį šiose pareigose."></textarea></label>'
        .'<p class="notice ok shift-note"><strong>Atvykimo patvirtinimas.</strong> '.e(shift_notice()).'</p>'
        .'<button class="btn">'.($busy ? 'Vis tiek kandidatuoti' : 'Pateikti kandidatūrą').'</button></form>'
        .'<p class="meta">Darbdavys matys jūsų profilį ir įvertinimus. Telefono numeris nerodomas: patvirtinus kandidatūrą bendrausite GANG žinutėse.</p></div>';
}

// The employer changed the dates or pay after the worker applied: "Tinka" or "Atsiimti kandidatūrą".
function recheck_panel(array $r): string
{
    return '<div class="panel recheck" id="atsiliepti"><h2>Pasikeitė darbo sąlygos</h2>'
        .'<p>Po jūsų kandidatūros darbdavys pakeitė šio darbo datas arba atlygį. Patvirtinkite, ar naujos sąlygos jums tinka. Kol to nepadarysite, darbdavys jūsų kandidatūros patvirtinti negalės.</p>'
        .'<ul class="facts"><li><b>Kada</b>'.e(period($r)).'</li><li><b>Atlygis</b>'.e($r['pay']).'</li></ul>'
        .'<form method="post" action="/darbas/'.(int) $r['id'].'/tinka" class="form">'.csrf_field().days_fieldset($r)
        .'<div class="owner-actions"><button class="btn">Tinka, kandidatuoju</button></div></form>'
        .post_button('/darbas/'.$r['id'].'/atsaukti', 'Atsiimti kandidatūrą', [], 'btn small ghost', 'Ar tikrai norite atsiimti kandidatūrą?').'</div>';
}

// Longest list of single days a worker can tick; a longer job is picked by its date ranges.
const PICK_DAYS_MAX = 31;
const WEEKDAYS_SHORT = ['', 'Pr', 'An', 'Tr', 'Kt', 'Pn', 'Št', 'Sk'];

// "Kuriomis dienomis galite dirbti?": every day still open, all ticked; the worker unticks the ones they can't work.
// $busy: days the worker already works through GANG, marked in the list.
function days_fieldset(array $r, array $busy = []): string
{
    if (!request_splittable($r)) {
        return '';
    }
    $days = array_keys(request_needs($r));
    if (count($days) < 2) {
        return '';
    }
    $items = count($days) <= PICK_DAYS_MAX
        ? array_map(fn ($d) => [$d, date_lt($d).' <small>'.WEEKDAYS_SHORT[(int) date('N', strtotime($d))].'</small>'.(in_array($d, $busy, true) ? ' <small class="busy">· jau turite GANG darbą</small>' : '')], $days)
        : array_map(fn ($x) => [$x[0].':'.$x[1], e(span_lt($x[0], $x[1]))], days_ranges($days));
    return '<fieldset class="pick-days"><legend>Kuriomis dienomis galite dirbti?</legend>'
        .'<p class="hint">Pažymėtos visos darbo dienos. Jei kuriomis nors dienomis dirbti negalite, nuimkite žymėjimą – darbdavys galės patvirtinti jūsų kandidatūrą toms dienoms, kurias pasirinkote.</p>'
        .'<div class="day-checks">'.implode('', array_map(fn ($x) => '<label class="choice"><input type="checkbox" name="days[]" value="'.e($x[0]).'" checked> <span>'.$x[1].'</span></label>', $items)).'</div></fieldset>';
}

// The days ticked in days_fieldset(), kept to the days the job still needs. [] = none ticked.
function posted_days(array $r): array
{
    $need = array_keys(request_needs($r));
    if (!request_splittable($r) || count($need) < 2) {
        return $need;
    }
    $picked = [];
    foreach ((array) ($_POST['days'] ?? []) as $v) {
        $v = (string) $v;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?::(\d{4}-\d{2}-\d{2}))?$/', $v, $m)) {
            $picked = [...$picked, ...ranges_days([[$m[1], $m[2] ?? $m[1]]])];
        }
    }
    return array_values(array_intersect($need, $picked));
}

function accepted_panel(array $r, array $u): string
{
    $c = sql(str_replace(' FROM contracts c', ", (SELECT COUNT(*) FROM reviews rv WHERE rv.contract_id = c.id AND rv.author = 'worker') AS reviewed FROM contracts c", CONTRACT_SELECT)
        .' WHERE c.request_id = ? AND c.worker_id = ?', [$r['id'], $u['id']])->fetch();
    // The chat first: opening it marks the messages read, so the card's "Žinutės" count is already right.
    $chat = $c && !$c['cancelled_at'] ? chat_box($c, $u).chat_script() : '';
    return '<div class="panel ok"><h2>Darbdavys patvirtino jūsų kandidatūrą</h2>'
        .'<p>Susisiekite su darbdaviu ir aptarkite darbo detales. Darbo sąlygos ir sutartis derinamos tiesiogiai su darbdaviu.</p>'
        .'<p class="shift-note"><strong>Atvykimo patvirtinimas.</strong> '.e(shift_notice()).'</p>'
        .($c ? contract_card($c, 'worker', (bool) $c['reviewed']) : '').'</div>'
        .$chat;
}

function owner_panel(array $r, bool $isOpen): string
{
    $responses = sql("SELECT s.*, w.name, w.about, w.roles, w.photo, w.phone, wr.experience FROM responses s JOIN users w ON w.id = s.worker_id
        LEFT JOIN worker_roles wr ON wr.user_id = s.worker_id AND wr.role = ?
        WHERE s.request_id = ? AND s.status IN ('sent', 'accepted') ORDER BY CASE WHEN s.status = 'accepted' THEN 0 ELSE 1 END, s.created_at", [$r['role'], $r['id']])->fetchAll();
    $list = '';
    $split = request_splittable($r);
    $allDays = ranges_days(request_ranges($r));
    $need = $split ? request_needs($r) : [];
    $chats = false;
    foreach ($responses as $s) {
        [$avg, $n] = worker_rating((int) $s['worker_id']);
        $badges = [];
        // Days: what the worker can do, and what approving now would cover (days already filled drop out).
        $take = null;
        if ($split) {
            $days = response_days($s, $r);
            if ($s['status'] === 'accepted') {
                $c = sql('SELECT r.kind, '.CONTRACT_DATES.' FROM contracts c JOIN requests r ON r.id = c.request_id WHERE c.request_id = ? AND c.worker_id = ? AND c.cancelled_at IS NULL', [$r['id'], $s['worker_id']])->fetch();
                $cDays = $c ? ranges_days(request_ranges($c)) : $days;
                if ($cDays !== $allDays) {
                    $badges[] = '<span class="badge picked">Patvirtinta: '.e(days_text($cDays)).'</span>';
                }
            } else {
                $take = array_values(array_intersect($days, array_keys($need)));
                if ($days !== $allDays) {
                    $badges[] = '<span class="badge picked">Gali dirbti: '.e(days_text($days)).'</span>';
                }
                if ($take && $take !== $days) {
                    $badges[] = '<span class="badge picked">Patvirtinsite: '.e(days_text($take)).'</span>';
                }
            }
        }
        if ($s['status'] === 'sent' && $s['recheck_at']) {
            $badges[] = '<span class="badge wait">Laukiama, kol patvirtins naujas sąlygas</span>';
        }
        if (isset(EXPERIENCE[(int) $s['experience']])) {
            $badges[] = '<span class="badge">Patirtis: '.e(mb_strtolower(EXPERIENCE[(int) $s['experience']])).'</span>';
        }
        $badges[] = '<span class="badge">'.gang_jobs_text((int) $s['worker_id']).'</span>';
        // A confirmed worker: the chat with them right under their card, and "Atšaukti darbą" (with a reason) instead of editing or closing the search.
        $c = $s['status'] === 'accepted' && current_user()
            ? sql(CONTRACT_SELECT.' WHERE c.request_id = ? AND c.worker_id = ? AND c.cancelled_at IS NULL ORDER BY c.id DESC', [$r['id'], $s['worker_id']])->fetch()
            : false;
        $buttons = $s['status'] === 'accepted'
            ? '<p class="ok-text">Patvirtinta</p>'.($c && contract_end($c) >= today() ? '<a class="btn small ghost quiet" href="/kontraktas/'.(int) $c['id'].'/atsaukti">Atšaukti darbą</a>' : '')
            : ($s['recheck_at'] ? '' : ($take === [] ? '<p class="meta">Nurodytos dienos jau užpildytos</p>' : post_button('/atsiliepimas/'.(int) $s['id'].'/patvirtinti', 'Patvirtinti', [], 'btn small')))
                .post_button('/atsiliepimas/'.(int) $s['id'].'/atmesti', 'Atsisakyti', [], 'btn small ghost');
        $chat = '';
        if ($c) {
            $chat = chat_box($c, current_user());
            $chats = true;
        }
        $list .= '<div class="responder'.($s['status'] === 'accepted' ? ' accepted' : '').'" id="kandidatas-'.(int) $s['id'].'">'.avatar(array_merge($s, ['id' => $s['worker_id']])).'<div class="who"><strong><a href="/darbuotojas/'.(int) $s['worker_id'].'">'.e(first_name($s['name'])).'</a></strong> '.stars($avg, $n)
            .'<p class="badges">'.implode('', $badges).'</p>'
            .($chat === '' ? application_notes_html($s) : '').($s['about'] ? '<details><summary>Apie save</summary><p>'.nl2br(e($s['about'])).'</p></details>' : '')
            .'</div><div class="actions">'.$buttons.'</div>'.$chat.'</div>';
    }
    $list .= $chats ? chat_script() : '';
    $teamHtml = $isOpen ? team_panel($r, array_map('intval', array_column($responses, 'worker_id'))) : '';
    $total = matching_workers_count($r['role'], $r['city'], (int) $r['min_experience'], $r['kind']);
    $teamOnly = $isOpen && !empty($r['public_at']) && $r['public_at'] > now()
        ? '<div class="notice ok team-only"><p><strong>Šiuo metu paieška matoma tik jūsų komandai.</strong> Kitiems tinkamiems darbuotojams pasiūlymą išsiųsime '.e(when_lt($r['public_at'])).', jei vietos dar nebus užimtos.</p>'
            .post_button('/darbas/'.(int) $r['id'].'/skelbti', 'Paskelbti visiems dabar', [], 'btn small').'</div>'
        : '';
    // Early days: nobody matches yet, so the employer's own people are the quickest way to fill GANG.
    $invite = $isOpen && $responses === [] && $total === 0
        ? '<div class="notice tip invite-nudge"><p><strong>Tinkamų darbuotojų GANG dar nedaug.</strong> Pakvieskite savo darbuotojus ir pažįstamus: prisijungę jie atsiras jūsų komandoje, o tinkantys gaus šį pasiūlymą.</p>'
            .share_link(url('/k/'.invite_code(current_user())), 'Prisijunkite prie mūsų komandos GANG platformoje').'</div>'
        : '';
    $leftover = leftover_question($r, $isOpen);
    $reach = $teamOnly.$invite.$leftover.'<ul class="reach"><li><b>'.(int) $r['notified'].'</b> gavo pranešimą el. paštu</li><li><b>'.$total.'</b> tinkami darbuotojai mieste</li></ul>';
    $status = ['open' => 'Aktyvi', 'filled' => 'Užpildyta', 'cancelled' => 'Atšaukta', 'expired' => 'Pasibaigusi'][$r['status']] ?? $r['status'];
    // Once a worker is confirmed the search is settled: dates and pay are agreed, the talk is in the chat.
    // No "Redaguoti", "Uždaryti paiešką" or "Pakartoti paiešką" then: a job is cancelled per worker ("Atšaukti darbą", with a reason).
    // If some days are still open, a quiet line says so and lets the employer stop looking for them.
    $confirmed = in_array('accepted', array_column($responses, 'status'), true);
    $still = '';
    // Right after a partial approval the question above asks the same, so the quiet line waits for the next visit.
    if ($isOpen && $confirmed && $leftover === '' && ($need = request_needs($r))) {
        $more = max($need) > 1 ? ' (iki '.max($need).' darbuotojų)' : '';
        $still = '<div class="owner-actions still-looking"><p class="meta">'.($r['kind'] === 'nuolat' ? 'Dar ieškome darbuotojų'.e($more).'.' : e(rtrim('Dar ieškome: '.days_text(array_keys($need)).$more, '.')).'.').'</p>'
            .post_button('/darbas/'.(int) $r['id'].'/uzdaryti', 'Nebeieškoti', [], 'btn small ghost quiet', 'Daugiau darbuotojų neieškosime ir skelbimas nebebus matomas. Patvirtinti darbuotojai ir pokalbiai su jais lieka.').'</div>';
    }
    $close = $isOpen && !$confirmed ? post_button('/darbas/'.$r['id'].'/uzdaryti', 'Uždaryti paiešką', [], 'btn small ghost', 'Ar tikrai norite uždaryti šią paiešką? Darbuotojai jos nebematys.') : '';
    return '<div class="panel owner" id="kandidatai"><div class="owner-head"><h2>Atsiliepę darbuotojai</h2><span class="tag'.($isOpen ? ' live' : '').'">'.e($isOpen ? 'Aktyvi' : $status).'</span></div>'.$reach
        .($list ?: '<p class="empty">Atsiliepusių darbuotojų dar nėra. Kai kas nors atsilieps, informuosime jus el. paštu.</p>').$teamHtml
        .$still.($isOpen && !$confirmed ? '<div class="owner-actions"><a class="btn small ghost" href="/darbas/'.(int) $r['id'].'/redaguoti">Redaguoti</a>'.$close.'</div>' : '').'</div>';
}

// After approving a worker for only some days: "Ar skelbime paliekame tik gruodžio 31 d.?"
// Shown once, right after the approval (?likusios=1), while other days are still open.
function leftover_question(array $r, bool $isOpen): string
{
    if (!$isOpen || ($_GET['likusios'] ?? '') !== '1' || !request_splittable($r)) {
        return '';
    }
    $need = request_needs($r);
    $ahead = array_values(array_filter(ranges_days(request_ranges($r)), fn ($d) => $d >= today()));
    $full = array_values(array_diff($ahead, array_keys($need)));
    if (!$need || !$full) {
        return '';
    }
    $open = days_text(array_keys($need));
    $count = max($need);
    $people = count(array_unique($need)) > 1 ? 'iki '.$count.' darbuotojų' : $count.' '.($count === 1 ? 'darbuotojo' : 'darbuotojų');
    return '<div class="notice ok leftover" id="likusios"><p><strong>Ar skelbime paliekame tik '.e($open).'?</strong></p>'
        .'<p>'.e(ucfirst(days_text($full))).' jau '.(count($full) > 1 ? 'užpildytos' : 'užpildyta').'. '.e(ucfirst($open)).' dar reikia '.e($people).'.</p>'
        .'<div class="owner-actions">'.post_button('/darbas/'.(int) $r['id'].'/palikti', 'Taip, ieškoti toliau', [], 'btn small')
        .post_button('/darbas/'.(int) $r['id'].'/uzdaryti', 'Ne, uždaryti paiešką', [], 'btn small ghost', 'Uždarius paiešką, darbuotojai jos nebematys, o laukiantiems kandidatams pranešime, kad vietos užpildytos.').'</div></div>';
}

function keep_open_action(int $id): never
{
    $u = require_user('manager');
    check_csrf();
    $r = find_request($id);
    if (!$r || (int) $r['manager_id'] !== (int) $u['id']) {
        not_found();
    }
    $need = request_needs($r);
    flash($need ? 'Paieška tęsiama. Skelbime liko: '.days_text(array_keys($need)) : 'Paieška tęsiama.');
    redirect('/darbas/'.$id.'#kandidatai');
}

// The "Nauja kandidatūra" email links here: the employer signs in if needed and lands on that candidate in the job's list.
// "Redaguoti": while a search is open the employer can correct its dates, pay and description.
// Once a worker is confirmed the dates and pay stay as agreed and only the description can change.
// Nobody is emailed while there are no candidates; candidates who already applied get one email
// about new dates or pay and confirm them ("Tinka") before the employer can approve them.
// An event's roles share their dates and description, so those change for every open role of the event.
function request_edit_page(int $id): never
{
    $u = require_user('manager');
    $r = find_request($id);
    if (!$r || (int) $r['manager_id'] !== (int) $u['id']) {
        not_found();
    }
    [$where, $params] = open_where();
    $group = $r['event_id']
        ? sql(REQUEST_SELECT." WHERE r.event_id = ? AND $where ORDER BY r.id", array_merge([$r['event_id']], $params))->fetchAll()
        : sql(REQUEST_SELECT." WHERE r.id = ? AND $where", array_merge([$id], $params))->fetchAll();
    if (!in_array($id, array_map('intval', array_column($group, 'id')), true)) {
        flash('Uždarytos ar pasibaigusios paieškos redaguoti negalima. Galite ją pakartoti su naujomis datomis.');
        redirect('/darbas/'.$id);
    }
    $ids = implode(',', array_map('intval', array_column($group, 'id')));
    $locked = (bool) sql("SELECT 1 FROM contracts WHERE request_id IN ($ids) AND cancelled_at IS NULL LIMIT 1")->fetchColumn();
    $candidates = (int) sql("SELECT COUNT(*) FROM responses WHERE request_id IN ($ids) AND status = 'sent'")->fetchColumn();
    // Days already past drop out of the form: only the days ahead can be changed.
    $ahead = [];
    foreach (request_ranges($r) as [$from, $to]) {
        if ($r['kind'] === 'nuolat' || $to >= today()) {
            $ahead[] = [$r['kind'] === 'nuolat' ? $from : max($from, today()), $to];
        }
    }
    $oldDates = ranges_string($ahead);
    preg_match('/^\d+(?:,\d+)?/', (string) $r['pay'], $m);
    $oldPay = $m[0] ?? '';
    $v = ['dates' => $oldDates, 'pay' => $oldPay, 'description' => ($r['hours'] !== '' ? 'Darbo laikas: '.$r['hours']."\n\n" : '').$r['description']];
    $errors = [];
    if (is_post()) {
        check_csrf();
        foreach ($v as $k => $_) {
            $v[$k] = input($k);
        }
        $ranges = [];
        if ($locked) {
            $v['dates'] = $oldDates;
            $v['pay'] = $oldPay;
        } else {
            $ranges = parse_ranges($v['dates']);
            if (!$ranges) {
                $errors['dates'] = 'Pažymėkite kalendoriuje bent vieną dieną, kada reikalingas darbuotojas.';
            } elseif ($r['kind'] === 'nuolat') {
                $ranges = [[$ranges[0][0], $ranges[0][0]]];
            }
            $v['dates'] = ranges_string($ranges) ?: $v['dates'];
            if (!valid_pay($v['pay'])) {
                $errors['pay'] = PAY_ERROR;
            }
        }
        if (mb_strlen($v['description']) < REQUEST_DESC_MIN || mb_strlen($v['description']) > 3000) {
            $errors['description'] = 'Nurodykite darbo laiką ir trumpai aprašykite darbo pobūdį bei pareigas (bent '.REQUEST_DESC_MIN.' simbolių).';
        }
        if (!$errors) {
            $datesChanged = !$locked && $v['dates'] !== $oldDates;
            $newPay = $locked ? $r['pay'] : pay_label(str_replace(',', '.', $v['pay']));
            $payChanged = $newPay !== $r['pay'];
            if ($datesChanged) {
                // Cancelled contracts on the whole job keep the dates they were made for.
                sql("UPDATE contracts SET start_date = (SELECT start_date FROM requests WHERE requests.id = contracts.request_id),
                    end_date = (SELECT end_date FROM requests WHERE requests.id = contracts.request_id),
                    dates = (SELECT dates FROM requests WHERE requests.id = contracts.request_id)
                    WHERE request_id IN ($ids) AND start_date IS NULL");
                sql("UPDATE requests SET start_date = ?, end_date = ?, dates = ? WHERE id IN ($ids)", [
                    $ranges[0][0], end($ranges)[1] !== $ranges[0][0] ? end($ranges)[1] : null, count($ranges) > 1 ? $v['dates'] : '',
                ]);
            }
            sql("UPDATE requests SET description = ?, hours = '' WHERE id IN ($ids)", [$v['description']]);
            sql('UPDATE requests SET pay = ? WHERE id = ?', [$newPay, $id]);
            $asked = 0;
            foreach ($group as $old) {
                $mine = (int) $old['id'] === $id;
                if ($datesChanged || ($payChanged && $mine)) {
                    $asked += ask_to_recheck($old, find_request((int) $old['id']), $datesChanged, $payChanged && $mine);
                }
            }
            flash(match (true) {
                !$datesChanged && !$payChanged => 'Pakeitimai išsaugoti.',
                $asked > 0 => 'Pakeitimai išsaugoti. Laišką gavo kandidatų: '.$asked.'. Jie turės patvirtinti, ar naujos sąlygos jiems tinka.',
                default => 'Pakeitimai išsaugoti. Skelbime jau rodomos naujos sąlygos.',
            });
            redirect('/darbas/'.$id);
        }
    }
    $role = ROLES[$r['role']] ?? '';
    $event = count($group) > 1 ? ' Datos ir aprašymas pasikeis visoms šio renginio pareigoms.' : '';
    $who = $locked
        ? '<p class="notice tip">Šiai paieškai jau patvirtinote darbuotoją, todėl datos ir atlygis lieka tokie, dėl kurių susitarėte. Jei jie keičiasi, susitarkite su darbuotoju GANG pokalbyje. Aprašymą galite keisti.</p>'
        : ($candidates
            ? '<p class="notice tip">Kandidatūrų jau gauta: '.$candidates.'. Pakeitus datas ar atlygį, kandidatai gaus laišką ir turės patvirtinti, ar naujos sąlygos jiems tinka. Kol jie to nepadarys, jų kandidatūrų patvirtinti negalėsite.'.$event.'</p>'
            : '<p class="notice ok">Kandidatūrų dar negauta, todėl pakeitimai bus išsaugoti be papildomų laiškų. Darbuotojai, atidarę skelbimą, matys atnaujintą informaciją.'.$event.'</p>');
    $kind = $r['kind'] === 'nuolat' ? '<input type="radio" name="kind" value="nuolat" checked hidden>' : '';
    $when = $locked
        ? '<fieldset><legend>Kada reikalingas darbuotojas?</legend><p><b>'.e(period($r)).'</b></p></fieldset>'
            .'<p><b>Atlygis į rankas:</b> '.e($r['pay']).'</p>'
        : '<fieldset class="when"><legend>Kada reikalingas darbuotojas?</legend>'.$kind
            .'<p class="hint when-hint" data-nuolat="Pažymėkite darbo pradžios dieną.">Spustelėkite dieną, kad pažymėtumėte vieną dieną. Spustelėjus kitą dieną, pažymimas laikotarpis nuo–iki. Pažymėtą dieną ar laikotarpį pašalinsite spustelėję jį dar kartą.</p>'
            .'<div class="picker" data-min="'.today().'" data-max="'.availability_last_day().'"><noscript><p class="notice">Kalendoriui reikalingas „JavaScript“. Įjunkite jį naršyklėje.</p></noscript></div>'
            .'<input type="hidden" name="dates" value="'.e($v['dates']).'">'.err($errors, 'dates').'</fieldset>'
            .'<label class="pay-field">Atlygis į rankas <span class="input-suffix"><input name="pay" value="'.e($v['pay']).'" inputmode="decimal" maxlength="6" required placeholder="10" pattern="\d{1,3}([.,]\d{1,2})?"><span aria-hidden="true">€/val.</span></span>'.err($errors, 'pay').'</label>';
    page('Redaguoti paiešką', '<section class="wrap narrow request-edit"><p class="back"><a href="/darbas/'.$id.'">← Atgal į skelbimą</a></p>'
        .'<h1>Redaguoti paiešką</h1><p class="meta">'.e($role.' · '.$r['venue_name'].', '.(CITIES[$r['city']] ?? '')).'</p>'.$who
        .($errors ? '<p class="notice tip">Kai kurie laukai užpildyti ne iki galo.</p>' : '')
        .'<form method="post" class="form" novalidate>'.csrf_field().$when
        .'<label>Darbo laikas ir aprašymas <textarea name="description" rows="6" maxlength="3000" required data-min="'.REQUEST_DESC_MIN.'">'.e($v['description']).'</textarea><span class="counter meta" aria-live="polite"></span>'.err($errors, 'description').'</label>'
        .'<p class="hint">Pareigų, miesto ir darbo trukmės pakeisti negalima. Jei jie kitokie, uždarykite šią paiešką ir paskelbkite naują.</p>'
        .'<div class="owner-actions"><button class="btn">Išsaugoti pakeitimus</button><a class="btn ghost" href="/darbas/'.$id.'">Atšaukti</a></div></form></section>'
        .($locked ? '' : date_picker_script()).counter_script(), ['noindex' => true]);
}

// After new dates or pay: every waiting candidate of the request confirms the new terms again
// (and picks the days again, the old days may be gone). Returns how many candidates were emailed.
function ask_to_recheck(array $old, array $new, bool $dates, bool $pay): int
{
    $waiting = sql("SELECT s.id, w.email, w.name FROM responses s JOIN users w ON w.id = s.worker_id WHERE s.request_id = ? AND s.status = 'sent'", [$new['id']])->fetchAll();
    if (!$waiting) {
        return 0;
    }
    sql("UPDATE responses SET recheck_at = ?".($dates ? ", dates = ''" : '')." WHERE request_id = ? AND status = 'sent'", [now(), $new['id']]);
    $role = ROLES[$new['role']] ?? '';
    $what = $dates && $pay ? 'datas ir atlygį' : ($dates ? 'datas' : 'atlygį');
    $facts = [];
    if ($dates) {
        $facts['Naujos datos'] = period($new);
        $facts['Buvo'] = period($old);
    }
    if ($pay) {
        $facts['Naujas atlygis'] = $new['pay'];
        $facts[$dates ? 'Buvęs atlygis' : 'Buvo'] = $old['pay'];
    }
    foreach ($waiting as $w) {
        send_mail($w['email'], 'Pasikeitė '.($dates && $pay ? 'darbo datos ir atlygis' : ($dates ? 'darbo datos' : 'atlygis')).': '.$role.' – '.$new['venue_name'], [
            'kicker' => 'Pasikeitė darbo sąlygos',
            'title' => $role,
            'lead' => "**{$new['venue_name']}** pakeitė darbo pasiūlymo, į kurį atsiliepėte, $what. Patvirtinkite, ar naujos sąlygos jums tinka. Kol to nepadarysite, darbdavys jūsų kandidatūros patvirtinti negalės.",
            'facts' => $facts,
            'cta' => ['Peržiūrėti ir atsakyti', url('/darbas/'.(int) $new['id'].'#atsiliepti')],
            'after' => 'Jei naujos sąlygos netinka, kandidatūrą galite atsiimti tame pačiame puslapyje.',
        ]);
    }
    return count($waiting);
}

// The candidate's "Tinka" after the employer changed the dates or pay; the employer hears about it.
function confirm_changes_action(int $id): never
{
    $u = require_user('worker');
    check_csrf();
    $r = find_request($id);
    [$where, $params] = open_where();
    if (!$r || !sql("SELECT 1 FROM requests r WHERE r.id = ? AND $where", array_merge([$id], $params))->fetchColumn()) {
        not_found();
    }
    $s = sql("SELECT * FROM responses WHERE request_id = ? AND worker_id = ? AND status = 'sent' AND recheck_at IS NOT NULL", [$id, $u['id']])->fetch();
    if (!$s) {
        redirect('/darbas/'.$id);
    }
    $days = posted_days($r);
    if (!$days) {
        flash('Pažymėkite bent vieną dieną, kurią galite dirbti.');
        redirect('/darbas/'.$id.'#atsiliepti');
    }
    $dates = request_splittable($r) ? implode(',', array_map(fn ($x) => $x[0].':'.$x[1], days_ranges($days))) : '';
    sql('UPDATE responses SET recheck_at = NULL, dates = ? WHERE id = ?', [$dates, $s['id']]);
    $some = $dates !== '' && $days !== ranges_days(request_ranges($r));
    $manager = sql('SELECT * FROM users WHERE id = ?', [$r['manager_id']])->fetch();
    $role = ROLES[$r['role']] ?? '';
    send_mail($manager['email'], 'Kandidatas patvirtino naujas sąlygas: '.$role.' – '.first_name($u['name']), [
        'kicker' => 'Kandidatūra atnaujinta',
        'title' => $role,
        'lead' => '**'.first_name($u['name']).'** patvirtino, kad naujos darbo sąlygos tinka'.($some ? ', ir nurodė, kad gali dirbti tik kai kuriomis dienomis' : '').'. Dabar galite patvirtinti kandidatūrą.',
        'facts' => ['Kandidatas' => first_name($u['name'])] + ($some ? ['Gali dirbti' => days_text($days)] : ['Darbo laikas' => period($r)]) + ['Atlygis' => $r['pay']],
        'cta' => ['Peržiūrėti kandidatūrą', url('/kandidatura/'.(int) $s['id'])],
    ]);
    flash('Ačiū. Darbdavys gaus pranešimą, kad naujos sąlygos jums tinka.');
    redirect('/darbas/'.$id);
}

function application_link_page(int $responseId): never
{
    $s = sql('SELECT s.id, s.status, s.request_id, v.manager_id FROM responses s JOIN requests r ON r.id = s.request_id JOIN venues v ON v.id = r.venue_id WHERE s.id = ?', [$responseId])->fetch();
    if (!$s) {
        not_found();
    }
    $u = current_user();
    if (!$u) {
        require_user();
    }
    if ((int) $s['manager_id'] !== (int) $u['id'] && !is_admin($u)) {
        // Signed in with another account (e.g. a worker one): offer to switch to the employer account.
        $e = 'e';
        $button = post_button('/kandidatura/'.$responseId.'/prisijungti', 'Prisijungti darbdavio paskyra', [], 'btn');
        page('Kandidatūra', <<<HTML
<section class="wrap narrow">
  <h1>Kandidatūra skirta darbdaviui</h1>
  <p>Šią kandidatūrą gali peržiūrėti tik paiešką paskelbęs darbdavys. Dabar esate prisijungę kaip <b>{$e($u['email'])}</b>. Prisijunkite darbdavio paskyra – po prisijungimo iš karto atidarysime kandidatūrą.</p>
  <p>$button</p>
</section>
HTML, ['noindex' => true]);
    }
    if (!in_array($s['status'], ['sent', 'accepted'], true)) {
        flash('Ši kandidatūra jau nebeaktyvi.');
        redirect('/darbas/'.(int) $s['request_id'].'#kandidatai');
    }
    redirect('/darbas/'.(int) $s['request_id'].'#kandidatas-'.$responseId);
}

function application_switch_action(int $responseId): never
{
    check_csrf();
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['after_login'] = '/kandidatura/'.$responseId;
    redirect('/prisijungti');
}

// On an open request: the manager's team, each with a one-click personal invitation.
function team_panel(array $r, array $responded): string
{
    $team = array_filter(team_members((int) $r['manager_id']), fn ($w) => !in_array((int) $w['id'], $responded, true));
    if (!$team) {
        return '';
    }
    $invited = invited_ids((int) $r['id']);
    $rows = '';
    foreach ($team as $w) {
        $action = in_array((int) $w['id'], $invited, true)
            ? '<span class="meta done">Pakviesta</span>'
            : post_button('/darbas/'.(int) $r['id'].'/kviesti/'.(int) $w['id'], 'Pakviesti', [], 'btn small');
        $rows .= '<div class="responder">'.avatar($w).'<div class="who"><strong><a href="/darbuotojas/'.(int) $w['id'].'">'.e(first_name($w['name'])).'</a></strong>'
            .'<p class="badges"><span class="badge">'.((int) $w['jobs'] ? 'Kartu dirbta: '.(int) $w['jobs'] : 'Prisijungė pagal kvietimą').'</span></p></div><div class="actions">'.$action.'</div></div>';
    }
    return '<h3 class="team-head">Mano komanda</h3><p class="meta">Žmonės, su kuriais jau dirbote per GANG. Pakviestas žmogus gaus asmeninį kvietimą el. paštu.</p>'.$rows;
}

function invite_action(int $requestId, int $workerId): never
{
    $u = require_user('manager');
    check_csrf();
    $r = find_request($requestId);
    if (!$r || (int) $r['manager_id'] !== (int) $u['id']) {
        not_found();
    }
    $team = array_column(team_members((int) $u['id']), null, 'id');
    [$where, $params] = open_where();
    $open = (bool) sql("SELECT 1 FROM requests r WHERE r.id = ? AND $where", array_merge([$requestId], $params))->fetchColumn();
    if ($open && isset($team[$workerId]) && invite_worker($r, $team[$workerId])) {
        flash(first_name($team[$workerId]['name']).' gavo asmeninį kvietimą.');
    }
    redirect('/darbas/'.$requestId);
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
    if (!empty($r['public_at']) && $r['public_at'] > now() && !in_array((int) $u['id'], invited_ids($id), true)) {
        not_found();
    }
    if (!profile_complete($u)) {
        flash('Prieš teikdami kandidatūrą, užpildykite profilį.');
        redirect('/profilis');
    }
    $previous = sql('SELECT id, status FROM responses WHERE request_id = ? AND worker_id = ?', [$id, $u['id']])->fetch();
    if (!$previous || $previous['status'] === 'withdrawn') {
        $note = mb_substr(input('note'), 0, 1000);
        $days = posted_days($r);
        if (!$days) {
            flash('Pažymėkite bent vieną dieną, kurią galite dirbti.');
            redirect('/darbas/'.$id.'#atsiliepti');
        }
        $all = ranges_days(request_ranges($r));
        $dates = request_splittable($r) ? implode(',', array_map(fn ($x) => $x[0].':'.$x[1], days_ranges($days))) : '';
        if ($previous) {
            // Applying again after withdrawing: the same row comes back (one row per worker and request).
            $again = sql("UPDATE responses SET status = 'sent', note = ?, dates = ?, created_at = ?, recheck_at = NULL WHERE id = ? AND status = 'withdrawn'", [$note, $dates, now(), $previous['id']]);
            if (!$again->rowCount()) {
                redirect('/darbas/'.$id.'#atsiliepti');
            }
            $responseId = (int) $previous['id'];
            sql('DELETE FROM response_notes WHERE response_id = ?', [$responseId]);
        } else {
            try {
                $responseId = insert('responses', ['request_id' => $id, 'worker_id' => $u['id'], 'note' => $note, 'dates' => $dates, 'created_at' => now()]);
            } catch (PDOException) {
                // Two tabs sent the same application at once: the unique index kept one.
                redirect('/darbas/'.$id.'#atsiliepti');
            }
        }
        $some = $dates !== '' && $days !== $all;
        $manager = sql('SELECT * FROM users WHERE id = ?', [$r['manager_id']])->fetch();
        [$avg, $n] = worker_rating((int) $u['id']);
        $rating = $n ? number_format($avg, 1, ',', '')." iš 5 ($n įvert.)" : 'Įvertinimų dar nėra';
        $role = ROLES[$r['role']] ?? '';
        send_mail($manager['email'], 'Nauja kandidatūra: '.$role.' – '.first_name($u['name']), [
            'kicker' => 'Nauja kandidatūra',
            'title' => $role,
            'lead' => '**'.first_name($u['name']).'** atsiliepė į jūsų darbuotojo paiešką'.($some ? ' ir nurodė, kad gali dirbti tik kai kuriomis dienomis' : '').'. Peržiūrėkite kandidato profilį ir, jei kandidatūra tinka, patvirtinkite ją.',
            'facts' => ['Kandidatas' => first_name($u['name'])] + ($some ? ['Gali dirbti' => days_text($days)] : ['Darbo laikas' => period($r)]) + ['Įvertinimas' => $rating],
            'note' => ['label' => 'Kandidato žinutė', 'text' => $note],
            'cta' => ['Peržiūrėti kandidatūrą', url('/kandidatura/'.(int) $responseId)],
        ]);
    }
    flash('Kandidatūra pateikta. Darbdavys gaus pranešimą el. paštu.');
    redirect('/darbas/'.$id);
}

// Before the employer decides, the worker can add messages to the application
// ("Pamiršau paminėti, kad…"). They show under the application and later open the chat.
// No counter is shown: a message written soon after the previous one joins it, so a few quick
// lines read as one message, and a quiet cap stops flooding. Emails to the employer stay limited.
const RESPONSE_NOTES_MAX = 10;
// A message written within this many minutes of the previous one is appended to it.
const RESPONSE_NOTE_JOIN_MINUTES = 30;
const RESPONSE_NOTE_MAX = 1000;
// One email to the employer per application in this many hours; later additions wait on the job page.
const RESPONSE_NOTE_MAIL_HOURS = 12;

function response_notes(int $responseId): array
{
    return sql('SELECT * FROM response_notes WHERE response_id = ? ORDER BY id', [$responseId])->fetchAll();
}

function response_notes_left(int $responseId): int
{
    return max(0, RESPONSE_NOTES_MAX - (int) sql('SELECT COUNT(*) FROM response_notes WHERE response_id = ?', [$responseId])->fetchColumn());
}

// The note sent with the application and the messages added later, as the employer sees them.
function application_notes_html(array $s): string
{
    $html = $s['note'] ? '<p class="note">'.e($s['note']).'</p>' : '';
    foreach (response_notes((int) $s['id']) as $n) {
        $html .= '<p class="note added"><span class="note-when">Papildyta '.e(when_lt($n['created_at'])).'</span>'.nl2br(e($n['body'])).'</p>';
    }
    return $html;
}

// Under the worker's pending application: what they wrote and a form to add a message.
function response_note_form(array $r, array $mine): string
{
    $sent = application_notes_html($mine);
    $html = '<div class="app-notes" id="papildyti">'.($sent !== '' ? '<h3>Jūsų žinutės darbdaviui</h3>'.$sent : '');
    if (response_notes_left((int) $mine['id']) <= 0) {
        return $html.'<p class="meta">Daugiau parašyti galėsite GANG pokalbyje, kai darbdavys patvirtins jūsų kandidatūrą.</p></div>';
    }
    return $html.'<form method="post" action="/darbas/'.(int) $r['id'].'/papildyti" class="form">'.csrf_field()
        .'<label>Papildoma žinutė darbdaviui'
        .'<textarea name="body" rows="3" maxlength="'.RESPONSE_NOTE_MAX.'" required placeholder="Pavyzdžiui: pamiršau paminėti, kad dvejus metus dirbau virėju restorane „…“."></textarea></label>'
        .'<button class="btn small">Siųsti žinutę</button></form></div>';
}

// /darbas/{id}/papildyti: the worker adds a message to an application still waiting for the employer.
function response_note_action(int $id): never
{
    $u = require_user('worker');
    check_csrf();
    $r = find_request($id);
    $s = $r ? sql("SELECT * FROM responses WHERE request_id = ? AND worker_id = ? AND status = 'sent'", [$id, $u['id']])->fetch() : false;
    if (!$r || !$s || $r['status'] !== 'open') {
        flash('Kandidatūros papildyti nebegalima: darbdavys jau priėmė sprendimą arba paieška uždaryta.');
        redirect('/darbas/'.$id);
    }
    $body = trim(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')));
    $body = (string) preg_replace("/\n{3,}/", "\n\n", $body);
    if ($body === '') {
        flash('Parašykite žinutę.');
        redirect('/darbas/'.$id.'#papildyti');
    }
    if (mb_strlen($body) > RESPONSE_NOTE_MAX) {
        flash('Žinutė per ilga: daugiausia '.RESPONSE_NOTE_MAX.' simbolių.');
        redirect('/darbas/'.$id.'#papildyti');
    }
    // Written soon after the previous message: it joins that message instead of becoming a new one.
    $last = sql('SELECT id, body, created_at FROM response_notes WHERE response_id = ? ORDER BY id DESC LIMIT 1', [$s['id']])->fetch();
    $join = $last && strtotime($last['created_at']) > strtotime('-'.RESPONSE_NOTE_JOIN_MINUTES.' minutes')
        && mb_strlen($last['body']) + mb_strlen($body) + 1 <= RESPONSE_NOTE_MAX * 2;
    if (!$join && response_notes_left((int) $s['id']) <= 0) {
        flash('Daugiau parašyti galėsite GANG pokalbyje, kai darbdavys patvirtins jūsų kandidatūrą.');
        redirect('/darbas/'.$id.'#papildyti');
    }
    // An earlier addition already emailed in the last hours: this one waits on the job page.
    $mailed = (bool) sql('SELECT 1 FROM response_notes WHERE response_id = ? AND created_at > ?',
        [$s['id'], date('Y-m-d H:i:s', strtotime('-'.RESPONSE_NOTE_MAIL_HOURS.' hours'))])->fetchColumn();
    if ($join) {
        sql('UPDATE response_notes SET body = ? WHERE id = ?', [$last['body']."\n".$body, $last['id']]);
    } else {
        insert('response_notes', ['response_id' => $s['id'], 'body' => $body, 'created_at' => now()]);
    }
    $email = $mailed ? false : sql('SELECT email FROM users WHERE id = ? AND blocked = 0', [$r['manager_id']])->fetchColumn();
    if ($email) {
        $role = ROLES[$r['role']] ?? '';
        $short = mb_strlen($body) > 600 ? rtrim(mb_substr($body, 0, 600)).'…' : $body;
        send_mail($email, 'Kandidatūra papildyta: '.$role.' – '.first_name($u['name']), [
            'kicker' => 'Kandidatūra papildyta',
            'title' => $role,
            'lead' => '**'.first_name($u['name']).'** papildė savo kandidatūrą žinute. Ji laukia jūsų sprendimo.',
            'facts' => ['Kandidatas' => first_name($u['name']), 'Darbo laikas' => period($r)],
            'note' => ['label' => 'Papildoma žinutė', 'text' => $short],
            'cta' => ['Peržiūrėti kandidatūrą', url('/kandidatura/'.(int) $s['id'])],
        ]);
    }
    flash('Žinutė išsiųsta. Darbdavys ją matys prie jūsų kandidatūros.');
    redirect('/darbas/'.$id.'#papildyti');
}

// Team first: the manager sends the request to everyone before the team-only window ends.
function release_now_action(int $id): never
{
    $u = require_user('manager');
    check_csrf();
    $r = find_request($id);
    if (!$r || (int) $r['manager_id'] !== (int) $u['id']) {
        not_found();
    }
    $ids = $r['event_id'] ? array_map('intval', sql('SELECT id FROM requests WHERE event_id = ?', [$r['event_id']])->fetchAll(PDO::FETCH_COLUMN)) : [$id];
    sql('UPDATE requests SET public_at = ? WHERE id IN ('.implode(',', $ids).') AND public_at IS NOT NULL', [now()]);
    release_team_requests();
    flash('Paieška paskelbta visiems tinkamiems darbuotojams.');
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
    flash('Paieška uždaryta.');
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
    if ($accept && $s['recheck_at']) {
        flash('Kandidatas dar nepatvirtino naujų darbo sąlygų. Kai patvirtins, gausite laišką.');
        redirect('/darbas/'.$r['id'].'#kandidatai');
    }
    $worker = sql('SELECT * FROM users WHERE id = ?', [$s['worker_id']])->fetch();
    $role = ROLES[$r['role']] ?? '';
    if (!$accept) {
        sql("UPDATE responses SET status = 'declined' WHERE id = ?", [$responseId]);
        send_mail($worker['email'], 'Dėl jūsų kandidatūros: '.$role.' – '.$r['venue_name'], declined_mail($r, 'Šį kartą darbdavys pasirinko kitą kandidatą.'));
        redirect('/darbas/'.$r['id']);
    }
    // A worker who picked some days (or whose other days are already taken) gets only the days still open.
    $split = request_splittable($r);
    $days = $split ? array_values(array_intersect(response_days($s, $r), array_keys(request_needs($r)))) : [];
    if ($split && !$days) {
        flash('Šio kandidato nurodytos dienos jau užpildytos.');
        redirect('/darbas/'.$r['id'].'#kandidatai');
    }
    $cDates = $split ? contract_dates($r, $days) : ['start_date' => null, 'end_date' => null, 'dates' => ''];
    $partial = $cDates['start_date'] !== null;
    sql("UPDATE responses SET status = 'accepted' WHERE id = ?", [$responseId]);
    insert('contracts', ['request_id' => $r['id'], 'worker_id' => $worker['id'], 'venue_id' => $r['venue_id'], 'created_at' => now()] + ($partial ? $cDates : []));
    $when = $partial ? days_text($days) : period($r);
    send_mail($worker['email'], 'Patvirtinta: '.$role.' – '.$r['venue_name'], [
        'kicker' => 'Kandidatūra patvirtinta',
        'title' => $role,
        'lead' => "**{$r['venue_name']}** patvirtino jūsų kandidatūrą".($partial ? ' šioms dienoms: **'.$when.'** ' : '. ').'Sveikiname!',
        'facts' => [
            'Kada' => $when.($r['hours'] !== '' ? ", {$r['hours']}" : ''),
            'Kur' => full_address($r),
            'Kontaktas' => $u['name'],
        ],
        'body' => [
            'Darbo detales aptarkite su darbdaviu GANG žinutėse: jas rasite skiltyje „Mano GANG“. Darbo sąlygos ir sutartis derinamos tiesiogiai su darbdaviu.',
            '**Atvykimo patvirtinimas.** '.shift_notice(),
        ],
        'cta' => ['Mano GANG', url('/mano')],
        'after' => 'Baigę darbą, savo paskyroje galėsite įvertinti darbdavį.',
    ]);
    $contact = ' Su '.first_name($worker['name']).' galite susirašinėti GANG žinutėse.';
    $need = request_needs($r);
    if (!$need) {
        sql("UPDATE requests SET status = 'filled', filled_at = ? WHERE id = ?", [now(), $r['id']]);
        decline_waiting($r);
        flash('Kandidatūra patvirtinta, visos vietos užpildytos.'.$contact);
        redirect('/darbas/'.$r['id']);
    }
    if (!$split) {
        flash('Kandidatūra patvirtinta.'.$contact);
        redirect('/darbas/'.$r['id']);
    }
    // Candidates waiting only for days that are now taken hear it straight away.
    foreach (sql("SELECT s.*, w.email FROM responses s JOIN users w ON w.id = s.worker_id WHERE s.request_id = ? AND s.status = 'sent'", [$r['id']])->fetchAll() as $w) {
        if (!array_intersect(response_days($w, $r), array_keys($need))) {
            sql("UPDATE responses SET status = 'declined' WHERE id = ?", [$w['id']]);
            send_mail($w['email'], 'Dėl jūsų kandidatūros: '.$role.' – '.$r['venue_name'], declined_mail($r, 'Informuojame, kad jūsų nurodytos dienos jau užpildytos.'));
        }
    }
    flash(($partial ? 'Kandidatūra patvirtinta šioms dienoms: '.$when : 'Kandidatūra patvirtinta.').$contact);
    redirect('/darbas/'.$r['id'].'?likusios=1#likusios');
}

// Lets everyone still waiting know the place is taken.
function decline_waiting(array $r): void
{
    $waiting = sql("SELECT w.email, w.name FROM responses s JOIN users w ON w.id = s.worker_id WHERE s.request_id = ? AND s.status = 'sent'", [$r['id']])->fetchAll();
    sql("UPDATE responses SET status = 'declined' WHERE request_id = ? AND status = 'sent'", [$r['id']]);
    foreach ($waiting as $w) {
        send_mail($w['email'], 'Dėl jūsų kandidatūros: '.(ROLES[$r['role']] ?? '').' – '.$r['venue_name'], declined_mail($r, 'Informuojame, kad visos vietos jau užpildytos.'));
    }
}

function declined_mail(array $r, string $why): array
{
    return [
        'kicker' => 'Dėl jūsų kandidatūros',
        'title' => ROLES[$r['role']] ?? '',
        'lead' => "dėkojame, kad atsiliepėte į **{$r['venue_name']}** darbo pasiūlymą. $why",
        'body' => 'Kviečiame peržiūrėti kitus aktualius darbo pasiūlymus.',
        'cta' => ['Darbo pasiūlymai', url('/darbai')],
    ];
}

function verification_note(): string
{
    return '<div class="panel"><p><strong>Tikriname jūsų darbovietę.</strong> Kad darbuotojų duomenys būtų saugūs, kiekvieną darbdavį GANG patikrina prieš leisdamas skelbti paieškas ir matyti darbuotojų profilius. Kai patvirtinsime, pranešime el. paštu.</p></div>';
}

// A new employer registered a venue: GANG gets an email to check it in /admin.
function verification_requested(array $u, int $venueId): void
{
    $email = admin_email();
    $venue = sql('SELECT * FROM venues WHERE id = ?', [$venueId])->fetch();
    if ($email === '' || !$venue) {
        return;
    }
    send_mail($email, 'Naujas darbdavys laukia patvirtinimo: '.$venue['name'], [
        'kicker' => 'Administravimas',
        'title' => 'Naujas darbdavys laukia patvirtinimo',
        'lead' => 'užregistruota nauja darbovietė: '.$venue['name'].', '.(CITIES[$venue['city']] ?? '').($venue['address'] !== '' ? ', '.$venue['address'] : '').'. Patikrinkite darbovietę ir darbdavį, tada paspauskite „Patvirtinti“.',
        'cta' => ['Atidaryti administravime', url('/admin?tab=user&id='.(int) $u['id'])],
    ]);
}

function worker_profile_page(int $id): never
{
    $u = is_admin() ? current_user() : require_user();
    $w = sql("SELECT * FROM users WHERE id = ? AND kind = 'worker'", [$id])->fetch();
    if (!$w) {
        not_found();
    }
    if (!sees_worker_profile($u, $id)) {
        flash(match (true) {
            $u['kind'] !== 'manager' => 'Darbuotojų profilius gali peržiūrėti tik darbdaviai.',
            default => 'Darbuotojo profilį matysite, kai jis pateiks kandidatūrą į jūsų paiešką arba prisijungs prie jūsų komandos.',
        });
        redirect('/mano');
    }
    [$avg, $n] = worker_rating($id);
    $reviews = reviews_html(worker_reviews($id), fn ($r) => 'Darbdavys, '.(CITIES[$r['city']] ?? ''));
    page(first_name($w['name']), '<section class="wrap narrow"><div class="greeting">'.avatar($w, 'large').'<div><h1>'.e(first_name($w['name'])).'</h1>'
        .'<p class="meta">'.stars($avg, $n).' · '.gang_jobs_text($id).'</p></div></div>'
        .'<ul class="facts"><li><b>Pareigos ir patirtis</b>'.e(roles_with_experience($id)).'</li>'.'<li><b>Miestai</b>'.e(labels($w['cities'], CITIES)).'</li>'.'<li><b>Domina darbas</b>'.e(durations_text($w)).'</li></ul>'
        .($w['about'] ? '<div class="desc">'.nl2br(e($w['about'])).'</div>' : '')
        .'<h2>Įvertinimai</h2>'.criteria_html('manager', 'worker_id', $id).$reviews.'</section>', ['noindex' => true]);
}
