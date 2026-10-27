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

function is_available(array $worker): bool
{
    return $worker['available_until'] !== null && $worker['available_until'] >= today();
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
    $subject = ($r['kind'] === 'skubu' ? 'Skubiai reikia: ' : 'Darbas: ').$role.' – '.$r['venue_name'].', '.$city;
    $body = "{$r['venue_name']} ($city) ieško: $role\n"
        .'Kada: '.period($r).($r['hours'] !== '' ? ", {$r['hours']}" : '')."\n"
        .($r['pay'] !== '' ? "Atlygis: {$r['pay']}\n" : '')
        ."\n{$r['description']}\n\n"
        ."Jei tinka, atsiliepkite čia:\n".url('/darbas/'.$r['id'])
        ."\n\nŠiuos laiškus gaunate, nes jūsų GANG profilyje pažymėtos šios pareigos ir miestas. Išjungti galite profilyje: ".url('/profilis');
    foreach ($workers as $w) {
        send_mail($w['email'], $subject, "Sveiki, ".first_name($w['name']).",\n\n".$body);
    }
    sql('UPDATE requests SET notified = ? WHERE id = ?', [count($workers), $r['id']]);
    return count($workers);
}

function matching_workers_count(string $role, string $city): array
{
    $row = sql("SELECT COUNT(*) AS n, SUM(CASE WHEN available_until >= ? THEN 1 ELSE 0 END) AS free FROM users
        WHERE kind = 'worker' AND blocked = 0 AND roles LIKE ? AND cities LIKE ?", [today(), '%,'.$role.',%', '%,'.$city.',%'])->fetch();
    return [(int) $row['n'], (int) $row['free']];
}
