<?php

// Hourly job (hPanel -> Advanced -> Cron Jobs): php /home/<user>/domains/gang.lt/private/cron.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
foreach (['bootstrap', 'data'] as $f) {
    require __DIR__."/app/$f.php";
}

// Close requests whose time has passed.
[$where, $params] = open_where('requests');
sql("UPDATE requests SET status = 'expired' WHERE status = 'open' AND NOT ($where)", $params);

// Ask both sides for a review once the work is over (permanent jobs: after 30 days).
$due = sql("SELECT c.id, r.role, v.name AS venue_name, w.email AS worker_email, w.name AS worker_name, m.email AS manager_email, m.name AS manager_name
    FROM contracts c JOIN requests r ON r.id = c.request_id JOIN venues v ON v.id = c.venue_id JOIN users w ON w.id = c.worker_id JOIN users m ON m.id = v.manager_id
    WHERE c.review_mail_at IS NULL AND ((r.kind <> 'nuolat' AND COALESCE(r.end_date, r.start_date) < ?) OR (r.kind = 'nuolat' AND c.created_at < ?))
    LIMIT 200", [today(), date('Y-m-d H:i:s', strtotime('-30 days'))])->fetchAll();
foreach ($due as $c) {
    $link = url('/vertinti/'.$c['id']);
    send_mail($c['worker_email'], 'Kaip sekėsi '.$c['venue_name'].'?', "Sveiki, ".first_name($c['worker_name']).",\n\nįvertinkite, kaip sekėsi dirbti {$c['venue_name']}. Tai užtruks pusę minutės ir padės kitiems darbuotojams:\n$link");
    send_mail($c['manager_email'], 'Kaip sekėsi su '.first_name($c['worker_name']).'?', "Sveiki, ".first_name($c['manager_name']).",\n\nįvertinkite ".first_name($c['worker_name'])." (".(ROLES[$c['role']] ?? '')."). Tai padės kitiems vadovams rasti patikimų žmonių:\n$link");
    sql('UPDATE contracts SET review_mail_at = ? WHERE id = ?', [now(), $c['id']]);
}

sql('DELETE FROM login_codes WHERE expires_at < ?', [date('Y-m-d H:i:s', strtotime('-1 day'))]);
