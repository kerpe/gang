<?php

declare(strict_types=1);

// Requests still worth showing: urgent, short and seasonal ones until their last day,
// permanent ones for request_days after posting.
function open_where(string $alias = 'r'): array
{
    $sql = "$alias.status = 'open' AND (($alias.kind <> 'nuolat' AND COALESCE($alias.end_date, $alias.start_date) >= ?) OR ($alias.kind = 'nuolat' AND $alias.created_at > ?))";
    return [$sql, [today(), date('Y-m-d H:i:s', strtotime('-'.(int) config()['request_days'].' days'))]];
}

const REQUEST_SELECT = 'SELECT r.*, v.name AS venue_name, v.city, v.address, v.manager_id, v.logo AS venue_logo, v.website AS venue_website FROM requests r JOIN venues v ON v.id = r.venue_id';

function find_request(int $id): ?array
{
    return sql(REQUEST_SELECT.' WHERE r.id = ?', [$id])->fetch() ?: null;
}

function open_requests(array $filters = [], int $limit = 200): array
{
    [$where, $params] = open_where();
    if (!empty($filters['city'])) {
        $where .= ' AND v.city = ?';
        $params[] = $filters['city'];
    }
    if (!empty($filters['role'])) {
        $where .= ' AND r.role = ?';
        $params[] = $filters['role'];
    }
    if (!empty($filters['kind'])) {
        $where .= ' AND r.kind = ?';
        $params[] = $filters['kind'];
    }
    if (!empty($filters['roles'])) {
        $in = implode(',', array_fill(0, count($filters['roles']), '?'));
        $where .= " AND r.role IN ($in)";
        array_push($params, ...$filters['roles']);
    }
    if (!empty($filters['kinds'])) {
        $in = implode(',', array_fill(0, count($filters['kinds']), '?'));
        $where .= " AND r.kind IN ($in)";
        array_push($params, ...$filters['kinds']);
    }
    if (!empty($filters['cities'])) {
        $in = implode(',', array_fill(0, count($filters['cities']), '?'));
        $where .= " AND v.city IN ($in)";
        array_push($params, ...$filters['cities']);
    }
    // Team first: until public_at only the invited team sees the request.
    $where .= ' AND (r.public_at IS NULL OR r.public_at <= ?)';
    $params[] = now();
    $order = "CASE WHEN r.kind = 'skubu' THEN 0 ELSE 1 END, r.created_at DESC";
    return sql(REQUEST_SELECT." WHERE $where ORDER BY $order LIMIT $limit", $params)->fetchAll();
}

// A review shows once the other side has rated too, or REVIEW_HIDE_DAYS after it was written.
function visible_review_sql(string $alias = 'rv'): string
{
    return "(EXISTS (SELECT 1 FROM reviews o WHERE o.contract_id = $alias.contract_id AND o.author <> $alias.author) OR $alias.created_at <= ?)";
}

function review_cutoff(): string
{
    return date('Y-m-d H:i:s', time() - REVIEW_HIDE_DAYS * 86400);
}

function worker_rating(int $workerId): array
{
    $row = sql('SELECT AVG(rv.rating) AS avg, COUNT(*) AS n FROM reviews rv JOIN contracts c ON c.id = rv.contract_id
        WHERE c.worker_id = ? AND rv.author = \'manager\' AND '.visible_review_sql(), [$workerId, review_cutoff()])->fetch();
    return [$row['avg'] !== null ? (float) $row['avg'] : null, (int) $row['n']];
}

function venue_rating(int $venueId): array
{
    $row = sql('SELECT AVG(rv.rating) AS avg, COUNT(*) AS n FROM reviews rv JOIN contracts c ON c.id = rv.contract_id
        WHERE c.venue_id = ? AND rv.author = \'worker\' AND '.visible_review_sql(), [$venueId, review_cutoff()])->fetch();
    return [$row['avg'] !== null ? (float) $row['avg'] : null, (int) $row['n']];
}

function worker_reviews(int $workerId): array
{
    return sql('SELECT rv.*, v.city FROM reviews rv JOIN contracts c ON c.id = rv.contract_id JOIN venues v ON v.id = c.venue_id
        WHERE c.worker_id = ? AND rv.author = \'manager\' AND '.visible_review_sql().' ORDER BY rv.created_at DESC LIMIT 50', [$workerId, review_cutoff()])->fetchAll();
}

function venue_reviews(int $venueId): array
{
    return sql('SELECT rv.* FROM reviews rv JOIN contracts c ON c.id = rv.contract_id
        WHERE c.venue_id = ? AND rv.author = \'worker\' AND '.visible_review_sql().' ORDER BY rv.created_at DESC LIMIT 50', [$venueId, review_cutoff()])->fetchAll();
}

// Jobs actually worked through GANG: a confirmed contract counts only once its last
// work day has passed (a permanent job, once its first day has passed), and only if it was
// neither cancelled nor marked as a no-show.
function completed_count(int $workerId): int
{
    return (int) sql("SELECT COUNT(*) FROM contracts c JOIN requests r ON r.id = c.request_id
        WHERE c.worker_id = ? AND c.cancelled_at IS NULL AND c.no_show_at IS NULL
        AND (CASE WHEN r.kind = 'nuolat' THEN r.start_date ELSE COALESCE(r.end_date, r.start_date) END) < ?", [$workerId, today()])->fetchColumn();
}

// Share of jobs a worker kept: completed jobs against late cancellations and no-shows.
// null while there is nothing to measure yet.
function worker_reliability(int $workerId): ?int
{
    $done = completed_count($workerId);
    $missed = (int) sql("SELECT COUNT(*) FROM contracts WHERE worker_id = ? AND ((cancelled_by = 'worker' AND late = 1) OR no_show_at IS NOT NULL)", [$workerId])->fetchColumn();
    return $done + $missed ? (int) round(100 * $done / ($done + $missed)) : null;
}

// The same for an employer: contracts kept against ones the employer cancelled at the last moment.
function venue_reliability(int $venueId): ?int
{
    $done = (int) sql("SELECT COUNT(*) FROM contracts c JOIN requests r ON r.id = c.request_id
        WHERE c.venue_id = ? AND (c.cancelled_at IS NULL OR c.cancelled_by = 'worker')
        AND (CASE WHEN r.kind = 'nuolat' THEN r.start_date ELSE COALESCE(r.end_date, r.start_date) END) < ?", [$venueId, today()])->fetchColumn();
    $missed = (int) sql("SELECT COUNT(*) FROM contracts WHERE venue_id = ? AND cancelled_by = 'manager' AND late = 1", [$venueId])->fetchColumn();
    return $done + $missed ? (int) round(100 * $done / ($done + $missed)) : null;
}

// Track record shown next to a worker: completed jobs and reliability, or a newcomer note.
function gang_jobs_text(int $workerId): string
{
    $done = completed_count($workerId);
    $reliable = worker_reliability($workerId);
    if ($reliable === null) {
        return 'GANG naujokas';
    }
    return 'Darbų per GANG: '.$done.' · Patikimumas: '.$reliable.' %';
}

// Open to offers on at least one day between $from and $to (default: today).
function is_available(int $userId, ?string $from = null, ?string $to = null): bool
{
    $from ??= today();
    return (bool) sql('SELECT 1 FROM availability WHERE user_id = ? AND start_date <= ? AND end_date >= ? LIMIT 1', [$userId, $to ?? $from, $from])->fetchColumn();
}

function availability_ranges(int $userId): array
{
    return sql('SELECT start_date, end_date FROM availability WHERE user_id = ? AND end_date >= ? ORDER BY start_date', [$userId, today()])->fetchAll();
}

function availability_days(int $userId): array
{
    $days = [];
    foreach (availability_ranges($userId) as $r) {
        for ($t = strtotime($r['start_date']); $t <= strtotime($r['end_date']); $t = strtotime('+1 day', $t)) {
            $days[date('Y-m-d', $t)] = true;
        }
    }
    return $days;
}

// Replaces the upcoming availability with the given days, stored as consecutive ranges.
function save_availability(int $userId, array $days): void
{
    $last = availability_last_day();
    $days = array_unique(array_filter($days, fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d >= today() && $d <= $last));
    sort($days);
    // Past days stay as history; a range running through today is cut at yesterday.
    sql('UPDATE availability SET end_date = ? WHERE user_id = ? AND start_date < ? AND end_date >= ?', [date('Y-m-d', strtotime('-1 day')), $userId, today(), today()]);
    sql('DELETE FROM availability WHERE user_id = ? AND start_date >= ?', [$userId, today()]);
    $start = $prev = null;
    foreach ([...$days, null] as $d) {
        if ($d !== null && $prev !== null && $d === date('Y-m-d', strtotime($prev.' +1 day'))) {
            $prev = $d;
            continue;
        }
        if ($start !== null) {
            insert('availability', ['user_id' => $userId, 'start_date' => $start, 'end_date' => $prev]);
        }
        $start = $prev = $d;
    }
}

function availability_last_day(): string
{
    // Same limit as job dates: PLANNING_MONTHS ahead of today.
    return date('Y-m-d', strtotime('+'.PLANNING_MONTHS.' months'));
}

function range_lt(array $r): string
{
    return $r['start_date'] === $r['end_date'] ? date_lt($r['start_date']) : date_lt($r['start_date']).' – '.date_lt($r['end_date']);
}

function first_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $last = count($parts) > 1 ? ' '.mb_substr(end($parts), 0, 1).'.' : '';
    return $parts[0].$last;
}

// Emails every matching worker about a new request; available workers are told it is urgent first.
function notify_workers(array $r, array $skip = []): int
{
    $workers = sql("SELECT * FROM users WHERE kind = 'worker' AND blocked = 0 AND notify = 1 AND cities LIKE ?
        AND (durations = '' OR durations LIKE ?)
        AND EXISTS (SELECT 1 FROM worker_roles wr WHERE wr.user_id = users.id AND wr.role = ? AND (wr.experience >= ? OR ? <= 1)) LIMIT 500",
        ['%,'.$r['city'].',%', '%,'.$r['kind'].',%', $r['role'], (int) $r['min_experience'], (int) $r['min_experience']])->fetchAll();
    // Team members invited personally already got their own email.
    $workers = array_values(array_filter($workers, fn ($w) => !in_array((int) $w['id'], $skip, true)));
    $role = ROLES[$r['role']] ?? $r['role'];
    $city = CITIES[$r['city']] ?? '';
    $subject = ($r['kind'] === 'skubu' ? 'Skubus darbo pasiūlymas: ' : 'Darbo pasiūlymas: ').$role.' – '.$r['venue_name'].', '.$city;
    $mail = [
        'kicker' => $r['kind'] === 'skubu' ? 'Skubus darbo pasiūlymas' : 'Darbo pasiūlymas',
        'title' => $role,
        'lead' => "**{$r['venue_name']}** ($city) ieško darbuotojo. Jei pasiūlymas jums tinka, pateikite kandidatūrą.",
        'facts' => array_filter([
            'Darbo trukmė' => KINDS[$r['kind']] ?? '',
            'Laikas' => period($r).($r['hours'] !== '' ? ", {$r['hours']}" : ''),
            'Atlygis' => $r['pay'],
            'Patirtis' => EXPERIENCE_REQUIRED[(int) $r['min_experience']] ?? '',
        ], fn ($v) => $v !== ''),
        'note' => ['label' => 'Darbo laikas ir aprašymas', 'text' => $r['description']],
        'cta' => ['Pateikti kandidatūrą', url('/darbas/'.$r['id'])],
        'footnote' => 'Šį laišką gavote, nes jūsų GANG profilyje pažymėtos šios pareigos, miestas, patirtis ir darbo trukmė. Pranešimų atsisakyti galite [profilio nustatymuose]('.url('/profilis').').',
    ];
    foreach ($workers as $w) {
        send_mail($w['email'], $subject, $mail);
    }
    sql('UPDATE requests SET notified = ? WHERE id = ?', [count($workers), $r['id']]);
    return count($workers);
}

// Matching workers, and how many of them marked $date as free.
function matching_workers_count(string $role, string $city, string $date, int $minExperience = 1, string $kind = ''): array
{
    $row = sql("SELECT COUNT(*) AS n, SUM(CASE WHEN EXISTS (SELECT 1 FROM availability a WHERE a.user_id = u.id AND a.start_date <= ? AND a.end_date >= ?) THEN 1 ELSE 0 END) AS free FROM users u
        WHERE kind = 'worker' AND blocked = 0 AND cities LIKE ? AND (? = '' OR durations = '' OR durations LIKE ?)
        AND EXISTS (SELECT 1 FROM worker_roles wr WHERE wr.user_id = u.id AND wr.role = ? AND (wr.experience >= ? OR ? <= 1))", [$date, $date, '%,'.$city.',%', $kind, '%,'.$kind.',%', $role, $minExperience, $minExperience])->fetch();
    return [(int) $row['n'], (int) $row['free']];
}

// [role => experience level] for a worker, in ROLES order.
function role_experience(int $userId): array
{
    $rows = sql('SELECT role, experience FROM worker_roles WHERE user_id = ?', [$userId])->fetchAll();
    $map = array_column($rows, 'experience', 'role');
    return array_map('intval', array_intersect_key(array_replace(ROLES, $map), $map));
}

function save_role_experience(int $userId, array $map): void
{
    sql('DELETE FROM worker_roles WHERE user_id = ?', [$userId]);
    foreach ($map as $role => $level) {
        insert('worker_roles', ['user_id' => $userId, 'role' => $role, 'experience' => (int) $level]);
    }
}

// Durations a worker is interested in; an empty list (profiles from before version 10) means all.
function worker_durations(array $u): array
{
    return from_list($u['durations'] ?? '') ?: array_keys(KINDS);
}

// "Skubiai, trumpam, sezonui, nuolat"
function durations_text(array $u): string
{
    $names = array_map(fn ($k) => KINDS[$k] ?? $k, worker_durations($u));
    return ucfirst(mb_strtolower(implode(', ', $names)));
}

// "Barmenas (1–3 metai), Virėjas (3–5 metai)"
function roles_with_experience(int $userId): string
{
    $out = [];
    foreach (role_experience($userId) as $role => $level) {
        $out[] = (ROLES[$role] ?? $role).(isset(EXPERIENCE[$level]) ? ' ('.mb_strtolower(EXPERIENCE[$level]).')' : '');
    }
    return implode(', ', $out);
}

// "Mano komanda": workers who already had a GANG contract with one of the manager's venues,
// most frequent first, then people who joined through the manager's invitation link.
// jobs = contracts together, last = date of the latest one.
function team_members(int $managerId): array
{
    $worked = sql("SELECT u.id, u.name, u.photo, u.roles, u.email, u.notify, COUNT(c.id) AS jobs, MAX(r.start_date) AS last
        FROM contracts c JOIN venues v ON v.id = c.venue_id JOIN requests r ON r.id = c.request_id JOIN users u ON u.id = c.worker_id
        WHERE v.manager_id = ? AND u.blocked = 0 AND c.cancelled_at IS NULL
        GROUP BY u.id, u.name, u.photo, u.roles, u.email, u.notify ORDER BY jobs DESC, last DESC LIMIT 60", [$managerId])->fetchAll();
    $joined = sql("SELECT u.id, u.name, u.photo, u.roles, u.email, u.notify, 0 AS jobs, NULL AS last
        FROM team_links t JOIN users u ON u.id = t.worker_id
        WHERE t.manager_id = ? AND u.blocked = 0 AND u.kind = 'worker' AND u.roles <> '' ORDER BY t.created_at DESC LIMIT 100", [$managerId])->fetchAll();
    $ids = array_column($worked, 'id');
    return [...$worked, ...array_values(array_filter($joined, fn ($w) => !in_array($w['id'], $ids)))];
}

// A worker joins a manager's team (invitation link). Returns false when already in it.
function join_team(int $managerId, int $workerId): bool
{
    if (sql('SELECT 1 FROM team_links WHERE manager_id = ? AND worker_id = ?', [$managerId, $workerId])->fetchColumn()) {
        return false;
    }
    insert('team_links', ['manager_id' => $managerId, 'worker_id' => $workerId, 'created_at' => now()]);
    $manager = sql("SELECT * FROM users WHERE id = ? AND kind = 'manager'", [$managerId])->fetch();
    $worker = sql('SELECT * FROM users WHERE id = ?', [$workerId])->fetch();
    if ($manager && $worker) {
        send_mail($manager['email'], 'Prie jūsų komandos prisijungė '.first_name($worker['name']), [
            'kicker' => 'Mano komanda',
            'title' => 'Naujas komandos narys',
            'lead' => '**'.first_name($worker['name']).'** priėmė jūsų kvietimą ir prisijungė prie jūsų komandos GANG.',
            'facts' => array_filter(['Pareigos' => labels($worker['roles'], ROLES), 'Miestai' => labels($worker['cities'], CITIES)]),
            'body' => 'Skelbdami paiešką, šį žmogų galėsite pakviesti asmeniškai, o pažymėję „Pirmiausia pasiūlyti komandai“ pasiūlymą jūsų komanda gaus anksčiau nei kiti darbuotojai.',
            'cta' => ['Mano GANG', url('/mano')],
        ]);
    }
    return true;
}

// Words for the personal link /k/{word}: what the person does, a noun of honour with a wink.
// No gendered adjectives, so the link fits everyone; Lithuanian letters become plain Latin ones.
const INVITE_DOMAINS = [
    'barmenas' => ['baro', 'kokteiliu'], 'barback' => ['baro', 'ledo'], 'padavejas' => ['sales', 'padeklu'], 'runneris' => ['sales', 'padeklu'], 'barista' => ['kavos', 'espreso'],
    'virejas' => ['virtuves', 'keptuves'], 'sefas' => ['virtuves', 'peilio'], 'plovejas' => ['puodu', 'indu'],
    'administratorius' => ['rezervaciju', 'sales'], 'vadovas' => ['pamainos', 'sales'], 'someljee' => ['vyno', 'taures'],
    'pagalbinis' => ['pamainos', 'virtuves'],
];
const INVITE_NOUNS = ['legenda', 'zvaigzde', 'raketa', 'nindze', 'masina', 'viesulas', 'audra', 'greitojipagalba'];
const INVITE_CITIES_OF = ['vilnius' => 'vilniaus', 'kaunas' => 'kauno', 'klaipeda' => 'klaipedos', 'palanga' => 'palangos', 'siauliai' => 'siauliu', 'panevezys' => 'panevezio'];
const INVITE_CITIES_IN = ['vilnius' => 'vilniuje', 'kaunas' => 'kaune', 'klaipeda' => 'klaipedoje', 'palanga' => 'palangoje', 'siauliai' => 'siauliuose', 'panevezys' => 'panevezyje'];
const INVITE_VENUE_TAILS = ['ieskolegendu', 'turivietoslegendai', 'laukiazvaigzdziu', 'komandaauga', 'kviecia'];

function ascii_slug(string $text): string
{
    $text = strtr(mb_strtolower($text), ['ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i', 'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z']);
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $text) : false;
    return preg_replace('/[^a-z0-9]/', '', $ascii !== false ? strtolower($ascii) : $text);
}

// Link words to choose from, best first. Empty until the profile or venue says enough.
function invite_slug_options(array $u): array
{
    if ($u['kind'] === 'manager') {
        $name = (string) sql('SELECT name FROM venues WHERE manager_id = ? ORDER BY id LIMIT 1', [$u['id']])->fetchColumn();
        $venue = substr(ascii_slug($name), 0, 40);
        return strlen($venue) >= 2 ? array_map(fn ($tail) => $venue.$tail, INVITE_VENUE_TAILS) : [];
    }
    $role = array_values(array_filter(explode(',', (string) $u['roles']), fn ($r) => isset(INVITE_DOMAINS[$r])))[0] ?? '';
    if ($role === '') {
        return [];
    }
    $city = array_values(array_filter(explode(',', (string) $u['cities']), fn ($c) => isset(INVITE_CITIES_OF[$c])))[0] ?? '';
    $options = [];
    foreach (INVITE_DOMAINS[$role] as $domain) {
        foreach (INVITE_NOUNS as $noun) {
            $options[] = $city !== '' ? INVITE_CITIES_OF[$city].$domain.$noun : $domain.$noun;
            if ($city !== '') {
                $options[] = $domain.$noun.INVITE_CITIES_IN[$city];
            }
        }
    }
    return $options;
}

// Is the word in use by someone else (current word, old code or an earlier word)?
function invite_slug_taken(string $slug, int $userId): bool
{
    return (bool) sql('SELECT 1 FROM users WHERE (invite_slug = ? OR invite_code = ?) AND id <> ?', [$slug, $slug, $userId])->fetchColumn()
        || (bool) sql('SELECT 1 FROM invite_aliases WHERE slug = ? AND user_id <> ?', [$slug, $userId])->fetchColumn();
}

// Free words in random order; if every one is taken, the first one with a number.
function new_invite_slug(array $u): string
{
    $options = invite_slug_options($u);
    if (!$options) {
        return '';
    }
    $taken = fn ($s) => invite_slug_taken($s, (int) $u['id']);
    shuffle($options);
    foreach ($options as $slug) {
        if (!$taken($slug)) {
            return $slug;
        }
    }
    for ($n = 2; $taken($options[0].$n); $n++) {
    }
    return $options[0].$n;
}

// The person behind /k/{word}: the readable word, or the older random code, which keeps working.
function invite_owner(string $code): array|false
{
    $user = sql('SELECT * FROM users WHERE (invite_slug = ? OR invite_code = ?) AND blocked = 0 ORDER BY invite_slug = ? DESC LIMIT 1', [$code, $code, $code])->fetch();
    if ($user) {
        return $user;
    }
    // A word used before the venue was renamed still leads to the same person.
    return sql('SELECT u.* FROM invite_aliases a JOIN users u ON u.id = a.user_id WHERE a.slug = ? AND u.blocked = 0 ORDER BY a.id DESC LIMIT 1', [$code])->fetch();
}

// Does the manager's link word still come from the venue's current name? (Words may end in a number.)
function invite_slug_fits(array $u, string $slug): bool
{
    return $u['kind'] !== 'manager' || in_array(preg_replace('/\d+$/', '', $slug), invite_slug_options($u), true);
}

// A new link word after the venue was renamed; the earlier word is kept so links already shared keep working.
function refresh_invite_slug(array $u): void
{
    $old = (string) ($u['invite_slug'] ?? '');
    if ($old === '' || invite_slug_fits($u, $old)) {
        return;
    }
    $slug = new_invite_slug($u);
    if ($slug === '') {
        return;
    }
    sql('DELETE FROM invite_aliases WHERE user_id = ? AND slug = ?', [$u['id'], $slug]);
    if (!sql('SELECT 1 FROM invite_aliases WHERE user_id = ? AND slug = ?', [$u['id'], $old])->fetchColumn()) {
        insert('invite_aliases', ['user_id' => $u['id'], 'slug' => $old, 'created_at' => now()]);
    }
    sql('UPDATE users SET invite_slug = ? WHERE id = ?', [$slug, $u['id']]);
}

// The personal link word (/k/{word}), made on first use and then kept, so shared links stay valid.
// A manager gets a new word when the venue is renamed; the earlier words keep working (invite_aliases).
// Until the profile or venue says enough for a word, a random code stands in (and keeps working later).
function invite_code(array $u): string
{
    if (!empty($u['invite_slug'])) {
        if (invite_slug_fits($u, $u['invite_slug'])) {
            return $u['invite_slug'];
        }
        refresh_invite_slug($u);
        return (string) sql('SELECT invite_slug FROM users WHERE id = ?', [$u['id']])->fetchColumn();
    }
    $slug = new_invite_slug($u);
    if ($slug !== '') {
        sql('UPDATE users SET invite_slug = ? WHERE id = ?', [$slug, $u['id']]);
        return $slug;
    }
    if (!empty($u['invite_code'])) {
        return $u['invite_code'];
    }
    do {
        $code = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 8);
        $code = strtolower(preg_replace('/[^a-z0-9]/i', 'x', $code));
    } while (sql('SELECT 1 FROM users WHERE invite_code = ? OR invite_slug = ?', [$code, $code])->fetchColumn());
    sql('UPDATE users SET invite_code = ? WHERE id = ?', [$code, $u['id']]);
    return $code;
}

// Team first: once public_at has passed, the request goes to every matching worker.
// Runs from cron and on page views, so the hour-long window is not stretched by the hourly cron.
function release_team_requests(): void
{
    $due = sql("SELECT id FROM requests WHERE public_at IS NOT NULL AND public_at <= ? LIMIT 20", [now()])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($due as $id) {
        // Only the call that clears public_at sends the emails.
        if (sql('UPDATE requests SET public_at = NULL WHERE id = ? AND public_at IS NOT NULL', [$id])->rowCount() !== 1) {
            continue;
        }
        $r = find_request((int) $id);
        [$where, $params] = open_where();
        if ($r && sql("SELECT 1 FROM requests r WHERE r.id = ? AND $where", array_merge([$id], $params))->fetchColumn()) {
            $skip = array_map('intval', sql('SELECT worker_id FROM invites WHERE request_id = ? UNION SELECT worker_id FROM responses WHERE request_id = ?', [$id, $id])->fetchAll(PDO::FETCH_COLUMN));
            notify_workers($r, $skip);
        }
    }
}

// Usual pay for a role in a city: [low, high, number of GANG requests it is based on (0 = PAY_GUIDE)].
function pay_guide(string $role, string $city): ?array
{
    $rows = sql("SELECT r.pay FROM requests r JOIN venues v ON v.id = r.venue_id WHERE r.role = ? AND v.city = ? AND r.created_at > ?",
        [$role, $city, date('Y-m-d H:i:s', strtotime('-'.PAY_GUIDE_DAYS.' days'))])->fetchAll(PDO::FETCH_COLUMN);
    $rates = array_values(array_filter(array_map('pay_number', $rows)));
    if (count($rates) >= PAY_GUIDE_MIN) {
        sort($rates);
        $at = fn (float $q) => $rates[(int) floor($q * (count($rates) - 1))];
        return [$at(0.25), $at(0.75), count($rates)];
    }
    return isset(PAY_GUIDE[$role]) ? [...PAY_GUIDE[$role], 0] : null;
}

// 12.5 from "12,50 €/val. į rankas"; null when the text has no hourly amount.
function pay_number(?string $pay): ?float
{
    return preg_match('/^(\d+(?:,\d+)?)\s*€/u', (string) $pay, $m) ? (float) str_replace(',', '.', $m[1]) : null;
}

// Pay at or above the usual upper end for the role and city.
function good_pay(array $r): bool
{
    $n = pay_number($r['pay'] ?? '');
    $g = $n !== null && isset($r['city']) ? pay_guide($r['role'], $r['city']) : null;
    return $g !== null && $n >= $g[1];
}

// "6–9 €" / "6,50–9 €"
function money(float $n): string
{
    return str_replace('.', ',', (string) ($n == floor($n) ? (int) $n : number_format($n, 2, '.', '')));
}

// Average of each review criterion: [c1 => 4.8, …] and how many reviews had criteria.
function criteria_averages(string $author, string $column, int $id): array
{
    $row = sql("SELECT AVG(rv.c1) AS c1, AVG(rv.c2) AS c2, AVG(rv.c3) AS c3, COUNT(rv.c1) AS n FROM reviews rv JOIN contracts c ON c.id = rv.contract_id
        WHERE c.$column = ? AND rv.author = ? AND rv.c1 IS NOT NULL AND ".visible_review_sql(), [$id, $author, review_cutoff()])->fetch();
    return [array_map(fn ($v) => $v !== null ? (float) $v : null, ['c1' => $row['c1'], 'c2' => $row['c2'], 'c3' => $row['c3']]), (int) $row['n']];
}

// Worker ids already invited to a request.
function invited_ids(int $requestId): array
{
    return array_map('intval', sql('SELECT worker_id FROM invites WHERE request_id = ?', [$requestId])->fetchAll(PDO::FETCH_COLUMN));
}

// Personal invitation from the manager to a team member; one per request and worker.
// The email respects the worker's notification setting; the invitation always shows in their account.
function invite_worker(array $r, array $worker): bool
{
    if (sql('SELECT 1 FROM invites WHERE request_id = ? AND worker_id = ?', [$r['id'], $worker['id']])->fetchColumn()
        || sql('SELECT 1 FROM responses WHERE request_id = ? AND worker_id = ?', [$r['id'], $worker['id']])->fetchColumn()) {
        return false;
    }
    insert('invites', ['request_id' => $r['id'], 'worker_id' => $worker['id'], 'created_at' => now()]);
    if ((int) $worker['notify'] === 1) {
        $role = ROLES[$r['role']] ?? $r['role'];
        send_mail($worker['email'], 'Asmeninis kvietimas: '.$role.' – '.$r['venue_name'], [
            'kicker' => 'Asmeninis kvietimas',
            'title' => $role,
            'lead' => "**{$r['venue_name']}** (".(CITIES[$r['city']] ?? '').') kviečia jus, kaip savo komandos narį, į darbą.',
            'facts' => array_filter([
                'Laikas' => period($r).($r['hours'] !== '' ? ", {$r['hours']}" : ''),
                'Atlygis' => $r['pay'],
            ], fn ($v) => $v !== ''),
            'note' => ['label' => 'Darbo laikas ir aprašymas', 'text' => $r['description']],
            'cta' => ['Peržiūrėti kvietimą', url('/darbas/'.$r['id'])],
            'footnote' => 'Šį asmeninį kvietimą gavote, nes esate šio darbdavio komandos narys GANG platformoje.',
        ]);
    }
    return true;
}
