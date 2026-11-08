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

// Version 17: a contract can cover only some of the job's days. Its own dates win; NULL start_date = the request's dates.
const CONTRACT_FROM = 'COALESCE(c.start_date, r.start_date)';
const CONTRACT_TO = "(CASE WHEN c.start_date IS NULL THEN (CASE WHEN r.kind = 'nuolat' THEN r.start_date ELSE COALESCE(r.end_date, r.start_date) END) ELSE COALESCE(c.end_date, c.start_date) END)";
const CONTRACT_DATES = 'COALESCE(c.start_date, r.start_date) AS start_date, CASE WHEN c.start_date IS NULL THEN r.end_date ELSE c.end_date END AS end_date, CASE WHEN c.start_date IS NULL THEN r.dates ELSE c.dates END AS dates';

// The work periods of a request or a contract as [from, to] pairs; a permanent job has one, its first day.
function request_ranges(array $r): array
{
    if (!empty($r['dates'])) {
        return array_map(fn ($p) => explode(':', $p), explode(',', $r['dates']));
    }
    return [[$r['start_date'], $r['kind'] === 'nuolat' ? $r['start_date'] : ($r['end_date'] ?: $r['start_date'])]];
}

// Every day in the ranges, sorted ("2026-12-26", …).
function ranges_days(array $ranges): array
{
    $days = [];
    foreach ($ranges as [$from, $to]) {
        for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
            $days[] = date('Y-m-d', $t);
        }
    }
    $days = array_values(array_unique($days));
    sort($days);
    return $days;
}

// Days back to [from, to] ranges: consecutive days join; with $key, only days with the same key join.
function days_ranges(array $days, ?callable $key = null): array
{
    sort($days);
    $ranges = [];
    foreach (array_values(array_unique($days)) as $d) {
        $last = count($ranges) - 1;
        if ($last >= 0 && $d === date('Y-m-d', strtotime($ranges[$last][1].' +1 day')) && (!$key || $key($d) === $key($ranges[$last][1]))) {
            $ranges[$last][1] = $d;
        } else {
            $ranges[] = [$d, $d];
        }
    }
    return $ranges;
}

// "gruodžio 26–27 d., gruodžio 31 d."
function days_text(array $days): string
{
    return implode(', ', array_map(fn ($r) => span_lt($r[0], $r[1]), days_ranges($days)));
}

// A worker can pick days only on a job of several days; a permanent job is taken as a whole.
function request_splittable(array $r): bool
{
    return $r['kind'] !== 'nuolat' && count(ranges_days(request_ranges($r))) > 1;
}

// How many more workers the request needs, per day still ahead: [day => n], only days with n > 0.
// Every confirmed (not cancelled) contract covers its own days.
function request_needs(array $r): array
{
    $need = [];
    foreach (ranges_days(request_ranges($r)) as $d) {
        if ($d >= today() || $r['kind'] === 'nuolat') {
            $need[$d] = (int) $r['people'];
        }
    }
    $contracts = sql('SELECT r.kind, '.CONTRACT_DATES.' FROM contracts c JOIN requests r ON r.id = c.request_id WHERE c.request_id = ? AND c.cancelled_at IS NULL', [$r['id']])->fetchAll();
    foreach ($contracts as $c) {
        foreach (ranges_days(request_ranges($c)) as $d) {
            if (isset($need[$d])) {
                $need[$d]--;
            }
        }
    }
    return array_filter($need, fn ($n) => $n > 0);
}

// The days a worker applied for: the ones they picked, or every day of the job.
function response_days(array $s, array $r): array
{
    return !empty($s['dates']) ? ranges_days(request_ranges(['dates' => $s['dates']] + $r)) : ranges_days(request_ranges($r));
}

// Contract columns for the days it covers: NULL dates when it is the whole job.
function contract_dates(array $r, array $days): array
{
    if (!$days || $days === ranges_days(request_ranges($r))) {
        return ['start_date' => null, 'end_date' => null, 'dates' => ''];
    }
    $ranges = days_ranges($days);
    $last = end($ranges)[1];
    return ['start_date' => $ranges[0][0], 'end_date' => $last !== $ranges[0][0] ? $last : null, 'dates' => count($ranges) > 1 ? implode(',', array_map(fn ($x) => $x[0].':'.$x[1], $ranges)) : ''];
}

// Dates still to fill, for lists and cards: a job with some days already taken shows only the open ones.
function open_period(array $r): string
{
    if ($r['status'] !== 'open' || !request_splittable($r)) {
        return period($r);
    }
    $need = request_needs($r);
    $all = array_values(array_filter(ranges_days(request_ranges($r)), fn ($d) => $d >= today()));
    return $need && array_keys($need) !== $all ? days_text(array_keys($need)) : period($r);
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
    $order = "CASE WHEN r.kind <> 'nuolat' AND r.start_date <= ? THEN 0 ELSE 1 END, r.created_at DESC";
    $params[] = urgent_until();
    return sql(REQUEST_SELECT." WHERE $where ORDER BY $order LIMIT $limit", $params)->fetchAll();
}

// Last start date that still counts as urgent.
function urgent_until(): string
{
    return date('Y-m-d', strtotime('+'.URGENT_DAYS.' days'));
}

// A short job starting within URGENT_DAYS: listed and emailed first.
function request_urgent(array $r): bool
{
    return $r['kind'] !== 'nuolat' && (string) $r['start_date'] !== '' && $r['start_date'] <= urgent_until();
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
        AND ".CONTRACT_TO.' < ?', [$workerId, today()])->fetchColumn();
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
        AND ".CONTRACT_TO.' < ?', [$venueId, today()])->fetchColumn();
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

function availability_last_day(): string
{
    // Same limit as job dates: PLANNING_MONTHS ahead of today.
    return date('Y-m-d', strtotime('+'.PLANNING_MONTHS.' months'));
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
    [$subject, $mail] = job_offer_mail($r);
    foreach ($workers as $w) {
        // Urgent offers first.
        $priority = request_urgent($r) ? 0 : 10;
        queue_mail($w['email'], $subject, array_merge($mail, ['footnote' => job_mail_footnote($w)]), job_mail_headers($w), $priority);
        job_mailed((int) $r['id'], (int) $w['id']);
    }
    sql('UPDATE requests SET notified = ? WHERE id = ?', [count($workers), $r['id']]);
    return count($workers);
}

function job_mailed(int $requestId, int $workerId): void
{
    insert('job_mails', ['request_id' => $requestId, 'worker_id' => $workerId, 'created_at' => now()]);
}

// [subject, mail] for one job offer.
function job_offer_mail(array $r): array
{
    $role = ROLES[$r['role']] ?? $r['role'];
    $city = CITIES[$r['city']] ?? '';
    $subject = (request_urgent($r) ? 'Skubus darbo pasiūlymas: ' : 'Darbo pasiūlymas: ').$role.' – '.$r['venue_name'].', '.$city;
    $mail = [
        'kicker' => request_urgent($r) ? 'Skubus darbo pasiūlymas' : 'Darbo pasiūlymas',
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
        'footnote' => job_mail_footnote(),
    ];
    return [$subject, $mail];
}

function job_mail_footnote(?array $w = null): string
{
    return 'Šį laišką gavote, nes jūsų GANG profilyje pažymėtos šios pareigos, miestas, patirtis ir darbo trukmė. '
        .($w ? '[Atsisakyti darbo pasiūlymų laiškų]('.unsubscribe_url((int) $w['id']).') arba pakeisti nustatymus [profilyje]('.url('/profilis').').' : 'Pranešimų atsisakyti galite [profilio nustatymuose]('.url('/profilis').').');
}

// One-click unsubscribe from job-offer emails (Gmail/Yahoo bulk sender rules, RFC 8058).
// The link carries an HMAC of the member id, so it works without signing in and cannot be guessed.
function mail_secret(): string
{
    $secret = setting('mail_secret');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        set_setting('mail_secret', $secret);
    }
    return $secret;
}

function unsubscribe_token(int $userId): string
{
    return substr(hash_hmac('sha256', 'unsubscribe:'.$userId, mail_secret()), 0, 32);
}

function unsubscribe_url(int $userId): string
{
    return url('/atsisakyti/'.$userId.'/'.unsubscribe_token($userId));
}

function job_mail_headers(array $w): array
{
    return [
        'List-Unsubscribe' => '<'.unsubscribe_url((int) $w['id']).'>, <mailto:hello@gang.lt?subject=Atsisakyti%20laisku>',
        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
    ];
}

// Open offers that match a worker's profile (roles, cities, durations, experience): the same
// rules notify_workers() uses.
function matching_jobs(array $u): array
{
    $xp = role_experience((int) $u['id']);
    if ($u['kind'] !== 'worker' || !$xp || from_list($u['cities']) === []) {
        return [];
    }
    $jobs = open_requests(['roles' => array_keys($xp), 'cities' => from_list($u['cities']), 'kinds' => worker_durations($u)], 50);
    return array_values(array_filter($jobs, fn ($r) => (int) $r['min_experience'] <= 1 || ($xp[$r['role']] ?? 0) >= (int) $r['min_experience']));
}

// After a worker saves the profile: one email about open offers that now match and were not
// emailed before (e.g. a city was added). Returns how many offers the email named.
// $before: ids of offers that already matched before the save; only offers the change made
// matching are emailed, so editing the about text sends nothing.
function notify_worker_of_open_jobs(array $u, array $before = []): int
{
    if ($u['kind'] !== 'worker' || (int) $u['blocked'] || !(int) $u['notify']) {
        return 0;
    }
    $seen = array_map('intval', sql('SELECT request_id FROM job_mails WHERE worker_id = ? UNION SELECT request_id FROM invites WHERE worker_id = ?
        UNION SELECT request_id FROM responses WHERE worker_id = ?', [$u['id'], $u['id'], $u['id']])->fetchAll(PDO::FETCH_COLUMN));
    $skip = array_merge($seen, array_map('intval', $before));
    $jobs = array_values(array_filter(matching_jobs($u), fn ($r) => !in_array((int) $r['id'], $skip, true)));
    if (!$jobs) {
        return 0;
    }
    if (count($jobs) === 1) {
        [$subject, $mail] = job_offer_mail($jobs[0]);
    } else {
        $shown = array_slice($jobs, 0, 10);
        $lines = array_map(function ($r) {
            $role = ROLES[$r['role']] ?? $r['role'];
            $city = CITIES[$r['city']] ?? '';
            $pay = $r['pay'] !== '' ? ', '.$r['pay'] : '';
            return '['.$role.' – '.$r['venue_name'].', '.$city.']('.url('/darbas/'.$r['id']).')'
                .' · '.(KINDS[$r['kind']] ?? '').', '.period($r).$pay;
        }, $shown);
        $subject = 'Jums tinkantys darbo pasiūlymai GANG';
        $mail = [
            'kicker' => 'Darbo pasiūlymai',
            'title' => 'Jums tinkantys darbo pasiūlymai',
            'lead' => 'Pagal jūsų atnaujintą profilį GANG rado tinkančių darbo pasiūlymų: **'.count($jobs).'**. Jei kuris nors pasiūlymas jums tinka, pateikite kandidatūrą.',
            'body' => $lines,
            'cta' => ['Visi tinkantys pasiūlymai', url('/mano/pasiulymai')],
            'footnote' => job_mail_footnote(),
        ];
    }
    send_mail($u['email'], $subject, array_merge($mail, ['footnote' => job_mail_footnote($u)]), '', job_mail_headers($u));
    foreach ($jobs as $r) {
        job_mailed((int) $r['id'], (int) $u['id']);
    }
    return count($jobs);
}

// How many workers match the role, city, experience and duration.
function matching_workers_count(string $role, string $city, int $minExperience = 1, string $kind = ''): int
{
    return (int) sql("SELECT COUNT(*) FROM users u
        WHERE kind = 'worker' AND blocked = 0 AND cities LIKE ? AND (? = '' OR durations = '' OR durations LIKE ?)
        AND EXISTS (SELECT 1 FROM worker_roles wr WHERE wr.user_id = u.id AND wr.role = ? AND (wr.experience >= ? OR ? <= 1))", ['%,'.$city.',%', $kind, '%,'.$kind.',%', $role, $minExperience, $minExperience])->fetchColumn();
}

// The worker's GANG work: confirmed, not cancelled contracts, per day from $from. [day => [contract, …]], sorted.
// A permanent job counts from its first day only.
function gang_work_days(int $workerId, string $from, ?string $to = null): array
{
    $rows = sql('SELECT c.id, c.request_id, r.role, r.kind, r.hours, v.name AS venue_name, '.CONTRACT_DATES.' FROM contracts c
        JOIN requests r ON r.id = c.request_id JOIN venues v ON v.id = c.venue_id
        WHERE c.worker_id = ? AND c.cancelled_at IS NULL AND '.CONTRACT_TO.' >= ?', [$workerId, $from])->fetchAll();
    $days = [];
    foreach ($rows as $c) {
        foreach (ranges_days(request_ranges($c)) as $d) {
            if ($d >= $from && ($to === null || $d <= $to)) {
                $days[$d][] = $c;
            }
        }
    }
    ksort($days);
    return $days;
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

// "Trumpam, sezonui, nuolat"
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
// GANG checks every employer (admin -> Nariai -> Patvirtinti) before they can publish searches
// or open worker profiles.
function manager_verified(array $u): bool
{
    return $u['kind'] === 'manager' && !empty($u['verified_at']);
}

// A worker who applied to one of the employer's searches, worked for them or joined their team.
function manager_knows_worker(int $managerId, int $workerId): bool
{
    return (bool) sql('SELECT 1 FROM responses s JOIN requests r ON r.id = s.request_id JOIN venues v ON v.id = r.venue_id WHERE v.manager_id = ? AND s.worker_id = ?
        UNION SELECT 1 FROM contracts c JOIN venues v ON v.id = c.venue_id WHERE v.manager_id = ? AND c.worker_id = ?
        UNION SELECT 1 FROM team_links t WHERE t.manager_id = ? AND t.worker_id = ?', [$managerId, $workerId, $managerId, $workerId, $managerId, $workerId])->fetchColumn();
}

// Worker profiles and photos: the worker, GANG, and employers the worker applied to, worked for
// or joined the team of. Only checked employers can publish searches, so only they get applicants.
function sees_worker_profile(?array $u, int $workerId): bool
{
    return is_admin() || ($u && (int) $u['id'] === $workerId) || ($u && $u['kind'] === 'manager' && manager_knows_worker((int) $u['id'], $workerId));
}

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
            'cta' => ['Mano profilis', url('/mano')],
        ]);
    }
    return true;
}

// Words for the personal link /k/{word}: what the person does, a noun of honour with a wink.
// No gendered adjectives, so the link fits everyone; Lithuanian letters become plain Latin ones.
const INVITE_DOMAINS = [
    'barmenas' => ['baro', 'kokteiliu'], 'barback' => ['baro', 'ledo'], 'padavejas' => ['sales', 'padeklu'], 'runneris' => ['sales', 'padeklu'], 'barista' => ['kavos', 'espreso'],
    'virejas' => ['virtuves', 'keptuves'], 'sefas' => ['virtuves', 'peilio'], 'plovejas' => ['puodu', 'indu'],
    'administratorius' => ['rezervaciju', 'sales'], 'vadovas' => ['pamainos', 'sales'], 'someljee' => ['restorano', 'sales'],
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
// A worker's word comes from the first role and city; with $all, every role and city counts
// (used to check whether a word still fits the profile).
function invite_slug_options(array $u, bool $all = false): array
{
    if ($u['kind'] === 'manager') {
        $name = (string) sql('SELECT name FROM venues WHERE manager_id = ? ORDER BY id LIMIT 1', [$u['id']])->fetchColumn();
        $venue = substr(ascii_slug($name), 0, 40);
        return strlen($venue) >= 2 ? array_map(fn ($tail) => $venue.$tail, INVITE_VENUE_TAILS) : [];
    }
    $roles = array_values(array_filter(explode(',', (string) $u['roles']), fn ($r) => isset(INVITE_DOMAINS[$r])));
    if (!$roles) {
        return [];
    }
    $cities = array_values(array_filter(explode(',', (string) $u['cities']), fn ($c) => isset(INVITE_CITIES_OF[$c])));
    $options = [];
    foreach ($all ? $roles : [$roles[0]] as $role) {
        foreach ($cities ? ($all ? $cities : [$cities[0]]) : [''] as $city) {
            foreach (INVITE_DOMAINS[$role] as $domain) {
                foreach (INVITE_NOUNS as $noun) {
                    $options[] = $city !== '' ? INVITE_CITIES_OF[$city].$domain.$noun : $domain.$noun;
                    if ($city !== '') {
                        $options[] = $domain.$noun.INVITE_CITIES_IN[$city];
                    }
                }
            }
        }
    }
    return array_values(array_unique($options));
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
    // A word used before a rename or a change of city still leads to the same person.
    return sql('SELECT u.* FROM invite_aliases a JOIN users u ON u.id = a.user_id WHERE a.slug = ? AND u.blocked = 0 ORDER BY a.id DESC LIMIT 1', [$code])->fetch();
}

// Does the link word still match the venue's current name, or the worker's current roles and cities?
// (Words may end in a number.) While the profile says too little for a word, the old one stays.
function invite_slug_fits(array $u, string $slug): bool
{
    $options = invite_slug_options($u, true);
    return !$options || in_array(preg_replace('/\d+$/', '', $slug), $options, true);
}

// A new link word after the venue was renamed or the worker changed city or role;
// the earlier word is kept so links already shared keep working.
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
// A new word comes when the venue is renamed or the worker's city or role changes; the earlier words keep working (invite_aliases).
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
            'footnote' => 'Šį asmeninį kvietimą gavote, nes esate šio darbdavio komandos narys GANG platformoje. [Atsisakyti darbo pasiūlymų laiškų]('.unsubscribe_url((int) $worker['id']).').',
        ], '', job_mail_headers($worker));
    }
    return true;
}
