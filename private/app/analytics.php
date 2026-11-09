<?php

declare(strict_types=1);

// /admin → Analitika: the main visitor numbers from Google Analytics 4 and Microsoft Clarity
// (read through Windsor.ai's API), next to GANG's own sign-ups, with short conclusions in Lithuanian.
// The Windsor API key is entered by the admin on this tab and kept in settings (windsor_key).
// Answers are cached in settings for ANALYTICS_CACHE_HOURS: Clarity allows only 10 API calls a day.
const ANALYTICS_CACHE_HOURS = 3;
const ANALYTICS_DAYS = 7;
const ANALYTICS_CHANNELS = [
    'Direct' => 'Tiesiogiai (nuoroda, žinutė, adresas)',
    'Organic Search' => 'Google paieška',
    'Organic Social' => 'Socialiniai tinklai',
    'Paid Social' => 'Socialinių tinklų reklama',
    'Paid Search' => 'Paieškos reklama',
    'Referral' => 'Kitos svetainės',
    'Email' => 'El. laiškai',
    'Unassigned' => 'Nenustatyta',
];
const ANALYTICS_DEVICES = ['mobile' => 'Telefonas', 'desktop' => 'Kompiuteris', 'tablet' => 'Planšetė'];

function windsor_api(): string
{
    return rtrim((string) (config()['windsor_api'] ?? 'https://connectors.windsor.ai'), '/');
}

// Saves (or, with an empty field, removes) the Windsor API key. Old cached answers go with it.
function analytics_save_key(): never
{
    $key = trim((string) ($_POST['windsor_key'] ?? ''));
    if ($key !== '' && !preg_match('/^[A-Za-z0-9_\-]{16,128}$/', $key)) {
        flash('Toks Windsor API raktas neatrodo tikras. Nukopijuokite jį visą.');
        redirect('/admin?tab=analytics');
    }
    set_setting('windsor_key', $key);
    analytics_clear_cache();
    flash($key === '' ? 'Windsor raktas pašalintas.' : 'Windsor raktas išsaugotas.');
    redirect('/admin?tab=analytics');
}

function analytics_clear_cache(bool $keepClarity = false): void
{
    sql("DELETE FROM settings WHERE name LIKE 'an_cache_%'".($keepClarity ? " AND name NOT LIKE 'an_cache_clarity%'" : ''));
}

// Fetches several Windsor queries at once (in parallel when curl is there) and caches each answer.
// $queries: name => [connector, fields, date_from, date_to]. Returns name => rows (null = failed).
function windsor_fetch(array $queries, ?bool &$fromCache = null): array
{
    $key = setting('windsor_key');
    $out = [];
    $todo = [];
    $fromCache = true;
    foreach ($queries as $name => [$connector, $fields, $from, $to]) {
        $cacheName = 'an_cache_'.$name.'_'.substr(md5($connector.implode(',', $fields).$from.$to), 0, 10);
        $cached = json_decode(setting($cacheName), true);
        if (is_array($cached) && ($cached['t'] ?? 0) > time() - ANALYTICS_CACHE_HOURS * 3600) {
            $out[$name] = $cached['rows'];
            continue;
        }
        $todo[$name] = [$cacheName, windsor_api().'/'.$connector.'?'.http_build_query(['api_key' => $key, 'date_from' => $from, 'date_to' => $to, 'fields' => implode(',', $fields)])];
    }
    if (!$todo) {
        return $out;
    }
    $fromCache = false;
    $bodies = [];
    if (function_exists('curl_multi_init')) {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($todo as $name => [, $url]) {
            $h = curl_init($url);
            curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10]);
            curl_multi_add_handle($multi, $h);
            $handles[$name] = $h;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $name => $h) {
            $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
            $bodies[$name] = $code >= 200 && $code < 300 ? (string) curl_multi_getcontent($h) : null;
            curl_multi_remove_handle($multi, $h);
        }
        curl_multi_close($multi);
    } else {
        foreach ($todo as $name => [, $url]) {
            $res = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 40, 'ignore_errors' => true]]));
            $bodies[$name] = $res !== false && preg_match('/ 2\d\d /', $http_response_header[0] ?? '') ? $res : null;
        }
    }
    foreach ($todo as $name => [$cacheName]) {
        $json = $bodies[$name] === null ? null : json_decode($bodies[$name], true);
        $rows = is_array($json) ? ($json['data'] ?? (array_is_list($json) ? $json : null)) : null;
        if (!is_array($rows)) {
            $out[$name] = null;
            continue;
        }
        // Keep the cache small: long lists are cut to 200 rows.
        $out[$name] = array_slice($rows, 0, 200);
        set_setting($cacheName, json_encode(['t' => time(), 'rows' => $out[$name]], JSON_UNESCAPED_UNICODE));
    }
    return $out;
}

function an_num(float $n, int $decimals = 0): string
{
    return number_format($n, $decimals, ',', ' ');
}

function an_pct(float $part, float $whole): string
{
    return $whole > 0 ? an_num($part / $whole * 100).' %' : '–';
}

// "+12 %" / "−8 %" compared with the week before (nothing when there is no earlier week).
function an_delta(float $now, float $before): string
{
    if ($before <= 0) {
        return '';
    }
    $d = round(($now - $before) / $before * 100);
    return ' <i class="'.($d < 0 ? 'down' : '').'">'.($d < 0 ? '−'.abs($d) : '+'.$d).' %</i>';
}

function an_sum(?array $rows, string $field): float
{
    return array_sum(array_map(fn ($r) => (float) ($r[$field] ?? 0), $rows ?? []));
}

// Rows grouped by one field and summed, biggest first.
function an_group(?array $rows, string $by, array $sum): array
{
    $g = [];
    foreach ($rows ?? [] as $r) {
        $k = (string) ($r[$by] ?? '');
        foreach ($sum as $f) {
            $g[$k][$f] = ($g[$k][$f] ?? 0) + (float) ($r[$f] ?? 0);
        }
    }
    uasort($g, fn ($a, $b) => $b[$sum[0]] <=> $a[$sum[0]]);
    return $g;
}

function admin_analytics(): string
{
    $key = setting('windsor_key');
    $keyForm = '<form method="post" action="/admin?tab=analytics" class="form analytics-key">'.csrf_field().'<input type="hidden" name="action" value="windsor_key">'
        .'<label>Windsor API raktas <input type="password" name="windsor_key" autocomplete="off" placeholder="'.($key ? 'Išsaugotas – įveskite naują, jei norite pakeisti' : 'Įklijuokite raktą').'"></label>'
        .'<button class="btn small">Išsaugoti</button></form>';
    if ($key === '') {
        return '<p>Čia rodomi svarbiausi gang.lt lankomumo skaičiai iš Google Analytics ir Microsoft Clarity bei išvados. Duomenys imami per Windsor.ai.</p>'
            .'<ol class="meta"><li>Prisijunkite prie <a href="https://onboard.windsor.ai" target="_blank" rel="noopener">Windsor.ai</a> (GANG paskyra).</li>'
            .'<li>Atidarykite paskyros nustatymus ir nukopijuokite <strong>API key</strong>.</li><li>Įklijuokite jį žemiau. Raktas saugomas tik gang.lt duomenų bazėje.</li></ol>'.$keyForm;
    }

    $today = today();
    $d = fn (int $days) => date('Y-m-d', strtotime("$today -$days days"));
    $from = $d(ANALYTICS_DAYS - 1);
    $prevFrom = $d(2 * ANALYTICS_DAYS - 1);
    $prevTo = $d(ANALYTICS_DAYS);
    $totals = ['sessions', 'active_users', 'newusers', 'screen_page_views', 'engaged_sessions', 'user_engagement_duration'];
    $data = windsor_fetch([
        'daily' => ['googleanalytics4', ['date', 'sessions', 'active_users'], $d(27), $today],
        'now' => ['googleanalytics4', $totals, $from, $today],
        'prev' => ['googleanalytics4', $totals, $prevFrom, $prevTo],
        'channels' => ['googleanalytics4', ['session_default_channel_group', 'sessions', 'engaged_sessions'], $from, $today],
        'sources' => ['googleanalytics4', ['session_source_medium', 'sessions'], $from, $today],
        'landing' => ['googleanalytics4', ['landing_page', 'sessions', 'engaged_sessions'], $from, $today],
        'devices' => ['googleanalytics4', ['devicecategory', 'sessions'], $from, $today],
        'cities' => ['googleanalytics4', ['city', 'active_users'], $from, $today],
        'clarity' => ['microsoft_clarity', ['metric_type', 'total_session_count', 'distinct_user_count', 'pages_per_session_percentage', 'average_scroll_depth', 'active_time', 'total_time',
            'rage_click_sessions_with_metric_percentage', 'dead_click_sessions_with_metric_percentage', 'quickback_click_sessions_with_metric_percentage', 'script_error_sessions_with_metric_percentage'], $d(2), $today],
    ], $fromCache);

    if ($data['now'] === null && $data['daily'] === null) {
        return '<div class="attention"><strong>Nepavyko gauti duomenų iš Windsor</strong><span>Patikrinkite, ar raktas teisingas ir ar Windsor paskyra aktyvi.</span></div>'.$keyForm;
    }

    $now = ['s' => an_sum($data['now'], 'sessions'), 'u' => an_sum($data['now'], 'active_users'), 'n' => an_sum($data['now'], 'newusers'), 'v' => an_sum($data['now'], 'screen_page_views'), 'e' => an_sum($data['now'], 'engaged_sessions'), 't' => an_sum($data['now'], 'user_engagement_duration')];
    $prev = ['s' => an_sum($data['prev'], 'sessions'), 'u' => an_sum($data['prev'], 'active_users'), 'e' => an_sum($data['prev'], 'engaged_sessions')];
    $count = fn (string $q, array $p = []) => (int) sql($q, $p)->fetchColumn();
    $since = $from.' 00:00:00';
    $prevSince = $prevFrom.' 00:00:00';
    $signups = $count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$since]);
    $signupsPrev = $count('SELECT COUNT(*) FROM users WHERE created_at >= ? AND created_at < ?', [$prevSince, $since]);
    $workers = $count("SELECT COUNT(*) FROM users WHERE kind = 'worker' AND created_at >= ?", [$since]);
    $managers = $signups - $workers;
    $requests = $count('SELECT COUNT(*) FROM requests WHERE created_at >= ?', [$since]);

    $html = '<p class="meta">Paskutinės '.ANALYTICS_DAYS.' dienos ('.e($from).' – '.e($today).'), lyginant su ankstesne savaite. Skaičiuojami tik lankytojai, paspaudę „Sutinku“ slapukų juostoje.</p>';
    $tiles = [
        ['Lankytojai', an_num($now['u']).an_delta($now['u'], $prev['u'])],
        ['Nauji lankytojai', an_num($now['n'])],
        ['Apsilankymai', an_num($now['s']).an_delta($now['s'], $prev['s'])],
        ['Peržiūrėti puslapiai', an_num($now['v'])],
        ['Įsitraukę apsilankymai', an_pct($now['e'], $now['s'])],
        ['Vid. laikas lankytojui', $now['u'] > 0 ? an_num($now['t'] / $now['u'] / 60, 1).' min.' : '–'],
        ['Registracijos', $signups.an_delta($signups, $signupsPrev)],
        ['Registravosi iš lankytojų', an_pct($signups, $now['u'])],
    ];
    $html .= '<div class="tiles kpis">';
    foreach ($tiles as [$label, $value]) {
        $html .= '<div class="tile"><span>'.$label.'</span><strong>'.$value.'</strong></div>';
    }
    $html .= '</div>';

    // Insights first: they are what the page is for.
    $insights = analytics_insights($data, $now, $prev, $signups, $workers, $managers, $requests);
    $html .= '<h2>Išvados</h2><ul class="insights">'.implode('', array_map(fn ($i) => '<li>'.$i.'</li>', $insights)).'</ul>';

    $html .= '<h2>Apsilankymai per 28 dienas</h2>'.analytics_bars($data['daily'], $d(27), $today);

    $channels = an_group($data['channels'], 'session_default_channel_group', ['sessions', 'engaged_sessions']);
    if ($channels) {
        $html .= '<h2>Iš kur ateina</h2><table class="data"><tr><th>Kanalas</th><th>Apsilankymai</th><th>Dalis</th><th>Įsitraukę</th></tr>';
        foreach ($channels as $k => $r) {
            $html .= '<tr><td>'.e(ANALYTICS_CHANNELS[$k] ?? $k).'</td><td>'.an_num($r['sessions']).'</td><td>'.an_pct($r['sessions'], $now['s']).'</td><td>'.an_pct($r['engaged_sessions'], $r['sessions']).'</td></tr>';
        }
        $html .= '</table>';
    }
    $sources = array_slice(an_group($data['sources'], 'session_source_medium', ['sessions']), 0, 8, true);
    if ($sources) {
        $html .= '<h2>Šaltiniai</h2><table class="data"><tr><th>Šaltinis / tipas</th><th>Apsilankymai</th></tr>';
        foreach ($sources as $k => $r) {
            $html .= '<tr><td>'.e($k).'</td><td>'.an_num($r['sessions']).'</td></tr>';
        }
        $html .= '</table>';
    }
    $landing = array_slice(an_group($data['landing'], 'landing_page', ['sessions', 'engaged_sessions']), 0, 10, true);
    if ($landing) {
        $html .= '<h2>Pirmas aplankytas puslapis</h2><table class="data"><tr><th>Puslapis</th><th>Apsilankymai</th><th>Įsitraukę</th></tr>';
        foreach ($landing as $k => $r) {
            $html .= '<tr><td>'.e($k).'</td><td>'.an_num($r['sessions']).'</td><td>'.an_pct($r['engaged_sessions'], $r['sessions']).'</td></tr>';
        }
        $html .= '</table>';
    }
    $devices = an_group($data['devices'], 'devicecategory', ['sessions']);
    $cities = array_slice(an_group($data['cities'], 'city', ['active_users']), 0, 8, true);
    if ($devices || $cities) {
        $html .= '<div class="analytics-two">';
        if ($devices) {
            $html .= '<div><h2>Įrenginiai</h2><table class="data"><tr><th>Įrenginys</th><th>Dalis</th></tr>';
            foreach ($devices as $k => $r) {
                $html .= '<tr><td>'.e(ANALYTICS_DEVICES[strtolower($k)] ?? $k).'</td><td>'.an_pct($r['sessions'], $now['s']).'</td></tr>';
            }
            $html .= '</table></div>';
        }
        if ($cities) {
            $html .= '<div><h2>Miestai</h2><table class="data"><tr><th>Miestas</th><th>Lankytojai</th></tr>';
            foreach ($cities as $k => $r) {
                $html .= '<tr><td>'.e($k === '(not set)' || $k === '' ? 'Nenustatyta' : $k).'</td><td>'.an_num($r['active_users']).'</td></tr>';
            }
            $html .= '</table></div>';
        }
        $html .= '</div>';
    }
    $c = analytics_clarity($data['clarity']);
    if ($c && $c['sessions'] > 0) {
        $html .= '<h2>Kaip naršo (Clarity, 3 d.)</h2><div class="tiles kpis">'
            .'<div class="tile"><span>Apsilankymai</span><strong>'.an_num($c['sessions']).'</strong></div>'
            .'<div class="tile"><span>Nuslinko vidutiniškai</span><strong>'.an_num($c['scroll']).' %</strong></div>'
            .'<div class="tile"><span>Pikti paspaudimai</span><strong>'.an_num($c['rage'], 1).' %</strong></div>'
            .'<div class="tile"><span>Paspaudimai be atsako</span><strong>'.an_num($c['dead'], 1).' %</strong></div>'
            .'<div class="tile"><span>Greitai grįžo atgal</span><strong>'.an_num($c['quickback'], 1).' %</strong></div>'
            .'<div class="tile"><span>JavaScript klaidos</span><strong>'.an_num($c['errors'], 1).' %</strong></div></div>'
            .'<p class="meta">Procentai rodo, kokioje dalyje apsilankymų tai įvyko. Įrašus ir karščio žemėlapius žiūrėkite <a href="https://clarity.microsoft.com/projects/view/'.e(CLARITY_ID).'/dashboard" target="_blank" rel="noopener">Clarity</a>.</p>';
    }
    $html .= '<form method="post" action="/admin?tab=analytics" class="analytics-refresh">'.csrf_field().'<input type="hidden" name="action" value="analytics_refresh">'
        .'<p class="meta">Duomenys atnaujinami kas '.ANALYTICS_CACHE_HOURS.' val.'.($fromCache ? '' : ' Ką tik atnaujinta.').' Google Analytics skaičiai vėluoja iki paros. <button class="linkish">Atnaujinti dabar</button></p></form>'
        .'<details class="analytics-settings"><summary>Windsor raktas</summary>'.$keyForm.'</details>';
    return $html;
}

// Clarity returns one row per metric block; each value sits in its own block.
function analytics_clarity(?array $rows): ?array
{
    if (!$rows) {
        return null;
    }
    $max = fn (string $f) => max(array_map(fn ($r) => (float) ($r[$f] ?? 0), $rows));
    return [
        'sessions' => $max('total_session_count'),
        'scroll' => $max('average_scroll_depth'),
        'rage' => $max('rage_click_sessions_with_metric_percentage'),
        'dead' => $max('dead_click_sessions_with_metric_percentage'),
        'quickback' => $max('quickback_click_sessions_with_metric_percentage'),
        'errors' => $max('script_error_sessions_with_metric_percentage'),
    ];
}

// Daily sessions as simple bars.
function analytics_bars(?array $rows, string $from, string $to): string
{
    $byDay = [];
    foreach ($rows ?? [] as $r) {
        $day = preg_replace('/^(\d{4})-?(\d{2})-?(\d{2}).*$/', '$1-$2-$3', (string) ($r['date'] ?? ''));
        $byDay[$day] = ($byDay[$day] ?? 0) + (float) ($r['sessions'] ?? 0);
    }
    $days = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $days[date('Y-m-d', $t)] = $byDay[date('Y-m-d', $t)] ?? 0;
    }
    $max = max(1, max($days));
    $html = '<div class="an-bars" role="img" aria-label="Apsilankymai kiekvieną dieną">';
    foreach ($days as $day => $n) {
        $html .= '<span style="--h:'.round($n / $max * 100, 1).'%" title="'.e($day).': '.an_num($n).'"></span>';
    }
    return $html.'</div><div class="an-bars-axis"><span>'.e(substr($from, 5)).'</span><span>'.e(substr($to, 5)).'</span></div>';
}

// Plain-language conclusions from the numbers. Each rule speaks only when it has enough data.
function analytics_insights(array $data, array $now, array $prev, int $signups, int $workers, int $managers, int $requests): array
{
    $out = [];
    if ($now['s'] < 1) {
        return ['Šios savaitės duomenų dar nėra. Google Analytics pirmuosius skaičius parodo po 24–48 val., o skaičiuojami tik lankytojai, paspaudę „Sutinku“.'];
    }
    // Trend.
    if ($prev['u'] >= 10) {
        $d = ($now['u'] - $prev['u']) / $prev['u'] * 100;
        if ($d >= 20) {
            $out[] = '<strong>Lankytojų daugėja:</strong> '.an_num($now['u']).' per savaitę, '.an_num($d).' % daugiau nei prieš savaitę.';
        } elseif ($d <= -20) {
            $out[] = '<strong>Lankytojų mažėja:</strong> '.an_num($now['u']).' per savaitę, '.an_num(abs($d)).' % mažiau nei prieš savaitę. Verta priminti apie GANG FB grupėse ar pažįstamiems.';
        } else {
            $out[] = 'Lankytojų skaičius stabilus: '.an_num($now['u']).' per savaitę.';
        }
    } else {
        $out[] = 'Per savaitę apsilankė '.an_num($now['u']).' lankytojų. Palyginimo su ankstesne savaite dar nėra.';
    }
    // Channels.
    $channels = an_group($data['channels'], 'session_default_channel_group', ['sessions']);
    $share = fn (string $k) => $now['s'] > 0 ? ($channels[$k]['sessions'] ?? 0) / $now['s'] * 100 : 0;
    $top = array_key_first($channels);
    if ($top !== null && $top !== '' && $share('Direct') < 60) {
        $out[] = 'Daugiausia lankytojų ateina per kanalą „'.e(ANALYTICS_CHANNELS[$top] ?? $top).'“ ('.an_num($share($top)).' %).';
    }
    if ($share('Direct') >= 60) {
        $out[] = 'Dauguma ateina tiesiogiai, t. y. iš jūsų nuorodų ir žinučių. Kol kas GANG randa daugiausia pažįstami, todėl nauji kanalai (FB grupės, Google) dar tik laukia.';
    }
    if ($share('Organic Search') < 5 && $now['s'] >= 30) {
        $out[] = 'Iš Google paieškos ateina labai nedaug. Verta pridėti gang.lt į Google Search Console ir ten pateikti svetainės žemėlapį.';
    } elseif ($share('Organic Search') >= 20) {
        $out[] = 'Google paieška jau atveda '.an_num($share('Organic Search')).' % apsilankymų.';
    }
    $social = $share('Organic Social') + $share('Paid Social');
    if ($social >= 25) {
        $out[] = 'Socialiniai tinklai veikia: iš jų ateina '.an_num($social).' % apsilankymų.';
    }
    // Conversion to sign-ups.
    if ($now['u'] >= 20) {
        $rate = $signups / $now['u'] * 100;
        if ($signups === 0) {
            $out[] = '<strong>Per savaitę neužsiregistravo nė vienas lankytojas.</strong> Clarity įrašuose pažiūrėkite, kur žmonės sustoja.';
        } elseif ($rate < 3) {
            $out[] = 'Užsiregistruoja '.an_num($rate, 1).' % lankytojų ('.$signups.'). Tai nedaug. Clarity įrašuose verta pažiūrėti, kur žmonės sustoja tarp pagrindinio puslapio ir registracijos.';
        } else {
            $out[] = 'Užsiregistruoja '.an_num($rate, 1).' % lankytojų ('.$signups.'). Tai geras rodiklis.';
        }
    }
    if ($signups > 0) {
        $out[] = 'Naujų narių per savaitę: '.$workers.' darbuotojų ir '.$managers.' darbdavių'.($requests ? ', paskelbta paieškų: '.$requests : '').'.'
            .($managers === 0 && $workers >= 5 ? ' Darbdavių trūksta, todėl verta skirti laiko jų pritraukimui.' : '')
            .($workers === 0 && $managers >= 2 ? ' Darbuotojų trūksta, todėl verta pasidalinti skelbimais FB grupėse.' : '');
    }
    // Engagement.
    if ($now['s'] >= 20) {
        $eng = $now['e'] / $now['s'] * 100;
        if ($eng < 45) {
            $out[] = 'Tik '.an_num($eng).' % apsilankymų įsitraukę: daug kas išeina per 10 sekundžių. Svarbiausia, kad pirmame ekrane iš karto būtų aišku, kam skirtas GANG.';
        } elseif ($eng >= 65) {
            $out[] = 'Įsitraukimas geras: '.an_num($eng).' % apsilankymų žmonės naršo ilgiau nei 10 sek. arba atidaro kelis puslapius.';
        }
        $weak = null;
        foreach (an_group($data['landing'], 'landing_page', ['sessions', 'engaged_sessions']) as $page => $r) {
            if ($r['sessions'] >= 10 && $r['engaged_sessions'] / $r['sessions'] < 0.35 && ($weak === null || $r['sessions'] > $weak[1])) {
                $weak = [$page, $r['sessions'], $r['engaged_sessions'] / $r['sessions'] * 100];
            }
        }
        if ($weak) {
            $out[] = 'Puslapis <strong>'.e($weak[0]).'</strong> sulaiko prasčiausiai: '.an_num($weak[1]).' apsilankymų, įsitraukę tik '.an_num($weak[2]).' %.';
        }
    }
    // Devices.
    $devices = an_group($data['devices'], 'devicecategory', ['sessions']);
    $mobile = $now['s'] > 0 ? ($devices['mobile']['sessions'] ?? 0) / $now['s'] * 100 : 0;
    if ($mobile >= 60) {
        $out[] = an_num($mobile).' % apsilankymų yra iš telefono, todėl naujus pakeitimus pirmiausia tikrinkite telefone.';
    }
    // Clarity.
    $c = analytics_clarity($data['clarity']);
    if ($c && $c['sessions'] >= 20) {
        if ($c['rage'] >= 3) {
            $out[] = 'Clarity rado piktų paspaudimų '.an_num($c['rage'], 1).' % apsilankymų (kai žmogus kelis kartus spaudžia tą pačią vietą). Clarity įrašuose pažiūrėkite, kur tai vyksta.';
        }
        if ($c['dead'] >= 10) {
            $out[] = 'Paspaudimų be atsako: '.an_num($c['dead'], 1).' % apsilankymų. Žmonės spaudžia tai, kas atrodo kaip mygtukas ar nuoroda, bet ja nėra.';
        }
        if ($c['errors'] >= 2) {
            $out[] = 'JavaScript klaidos pasitaiko '.an_num($c['errors'], 1).' % apsilankymų. Praneškite Claude, kad patikrintų.';
        }
        if ($c['rage'] < 3 && $c['dead'] < 10 && $c['errors'] < 2) {
            $out[] = 'Clarity didelių naudojimo kliūčių nemato: piktų paspaudimų, paspaudimų be atsako ir klaidų mažai.';
        }
    }
    return $out;
}
