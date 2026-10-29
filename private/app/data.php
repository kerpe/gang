<?php

declare(strict_types=1);

// Requests still worth showing: urgent, short and seasonal ones until their last day,
// permanent ones for request_days after posting.
function open_where(string $alias = 'r'): array
{
    $sql = "$alias.status = 'open' AND (($alias.kind <> 'nuolat' AND COALESCE($alias.end_date, $alias.start_date) >= ?) OR ($alias.kind = 'nuolat' AND $alias.created_at > ?))";
    return [$sql, [today(), date('Y-m-d H:i:s', strtotime('-'.(int) config()['request_days'].' days'))]];
}

const REQUEST_SELECT = 'SELECT r.*, v.name AS venue_name, v.city, v.address, v.manager_id FROM requests r JOIN venues v ON v.id = r.venue_id';

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
    if (!empty($filters['cities'])) {
        $in = implode(',', array_fill(0, count($filters['cities']), '?'));
        $where .= " AND v.city IN ($in)";
        array_push($params, ...$filters['cities']);
    }
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

function completed_count(int $workerId): int
{
    return (int) sql('SELECT COUNT(*) FROM contracts WHERE worker_id = ?', [$workerId])->fetchColumn();
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
    return date('Y-m-d', strtotime('+'.AVAILABILITY_MONTHS.' months'));
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
function notify_workers(array $r): int
{
    $workers = sql("SELECT * FROM users WHERE kind = 'worker' AND blocked = 0 AND notify = 1 AND roles LIKE ? AND cities LIKE ? LIMIT 500",
        ['%,'.$r['role'].',%', '%,'.$r['city'].',%'])->fetchAll();
    $role = ROLES[$r['role']] ?? $r['role'];
    $city = CITIES[$r['city']] ?? '';
    $subject = ($r['kind'] === 'skubu' ? 'Skubus darbo pasiūlymas: ' : 'Darbo pasiūlymas: ').$role.' – '.$r['venue_name'].', '.$city;
    $body = "{$r['venue_name']} ($city) ieško darbuotojo: $role\n"
        .'Laikas: '.period($r).($r['hours'] !== '' ? ", {$r['hours']}" : '')."\n"
        .($r['pay'] !== '' ? "Atlygis: {$r['pay']}\n" : '')
        ."\n{$r['description']}\n\n"
        ."Jei pasiūlymas jums tinka, kandidatūrą galite pateikti čia:\n".url('/darbas/'.$r['id'])
        ."\n\nŠį laišką gavote, nes jūsų GANG profilyje pažymėtos šios pareigos ir miestas. Pranešimų atsisakyti galite profilio nustatymuose: ".url('/profilis');
    foreach ($workers as $w) {
        send_mail($w['email'], $subject, "Laba diena,\n\n".$body);
    }
    sql('UPDATE requests SET notified = ? WHERE id = ?', [count($workers), $r['id']]);
    return count($workers);
}

// Matching workers, and how many of them marked $date as free.
function matching_workers_count(string $role, string $city, string $date): array
{
    $row = sql("SELECT COUNT(*) AS n, SUM(CASE WHEN EXISTS (SELECT 1 FROM availability a WHERE a.user_id = u.id AND a.start_date <= ? AND a.end_date >= ?) THEN 1 ELSE 0 END) AS free FROM users u
        WHERE kind = 'worker' AND blocked = 0 AND roles LIKE ? AND cities LIKE ?", [$date, $date, '%,'.$role.',%', '%,'.$city.',%'])->fetch();
    return [(int) $row['n'], (int) $row['free']];
}
