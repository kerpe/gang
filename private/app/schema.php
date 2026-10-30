<?php

declare(strict_types=1);

// Creates the tables on first use. The marker file keeps later requests from re-checking;
// bump SCHEMA_VERSION and add CREATE/ALTER statements below when the schema changes.
const SCHEMA_VERSION = 3;

function migrate(PDO $db): void
{
    $marker = private_root().'/data/schema-'.SCHEMA_VERSION;
    if (is_file($marker)) {
        return;
    }
    $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $tables = [
        // kind: worker | manager. blocked users cannot log in.
        'users' => "id $id,
            kind VARCHAR(10) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL DEFAULT '',
            phone VARCHAR(40) NOT NULL DEFAULT '',
            roles VARCHAR(255) NOT NULL DEFAULT '',
            cities VARCHAR(255) NOT NULL DEFAULT '',
            about TEXT NULL,
            photo VARCHAR(64) NULL,
            available_until DATE NULL,
            notify TINYINT NOT NULL DEFAULT 1,
            blocked TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_login_at DATETIME NULL",
        'login_codes' => "id $id,
            email VARCHAR(190) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL",
        'venues' => "id $id,
            manager_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            city VARCHAR(20) NOT NULL,
            address VARCHAR(190) NOT NULL DEFAULT '',
            about TEXT NULL,
            created_at DATETIME NOT NULL",
        // status: open | filled | cancelled | expired
        'requests' => "id $id,
            venue_id INT NOT NULL,
            role VARCHAR(30) NOT NULL,
            kind VARCHAR(10) NOT NULL,
            people INT NOT NULL DEFAULT 1,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            hours VARCHAR(120) NOT NULL DEFAULT '',
            pay VARCHAR(80) NOT NULL DEFAULT '',
            description TEXT NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'open',
            notified INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            filled_at DATETIME NULL",
        // status: sent | accepted | declined | withdrawn
        'responses' => "id $id,
            request_id INT NOT NULL,
            worker_id INT NOT NULL,
            note TEXT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'sent',
            created_at DATETIME NOT NULL",
        // A GANG contract: the manager accepted a worker for a request.
        'contracts' => "id $id,
            request_id INT NOT NULL,
            worker_id INT NOT NULL,
            venue_id INT NOT NULL,
            created_at DATETIME NOT NULL,
            review_mail_at DATETIME NULL",
        // author: worker (rates the venue) | manager (rates the worker)
        'reviews' => "id $id,
            contract_id INT NOT NULL,
            author VARCHAR(10) NOT NULL,
            rating INT NOT NULL,
            text TEXT NULL,
            created_at DATETIME NOT NULL",
        // Days a worker is open to offers, as inclusive date ranges.
        'availability' => "id $id,
            user_id INT NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL",
        // Pre-launch sign-ups from the "Netrukus" page. kind: worker | manager.
        'waitlist' => "id $id,
            email VARCHAR(190) NOT NULL UNIQUE,
            kind VARCHAR(10) NOT NULL,
            city VARCHAR(20) NOT NULL DEFAULT '',
            ip_hash CHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            invited_at DATETIME NULL",
    ];
    foreach ($tables as $name => $cols) {
        $db->exec("CREATE TABLE IF NOT EXISTS $name ($cols)$tail");
    }
    // Version 2: profile photo; availability moved from users.available_until to ranges.
    try {
        $db->exec('ALTER TABLE users ADD photo VARCHAR(64) NULL');
    } catch (PDOException) {
        // Already there.
    }
    if (!$db->query('SELECT 1 FROM availability LIMIT 1')->fetchColumn()) {
        $db->prepare('INSERT INTO availability (user_id, start_date, end_date) SELECT id, ?, available_until FROM users WHERE available_until >= ?')
            ->execute([date('Y-m-d'), date('Y-m-d')]);
    }
    $indexes = [
        'availability_user' => 'availability (user_id)',
        'responses_request' => 'responses (request_id)',
        'contracts_worker' => 'contracts (worker_id)',
        'reviews_contract' => 'reviews (contract_id)',
        'requests_status' => 'requests (status)',
        'login_codes_email' => 'login_codes (email)',
    ];
    foreach ($indexes as $name => $on) {
        try {
            $db->exec("CREATE INDEX $name ON $on");
        } catch (PDOException) {
            // Already exists.
        }
    }
    @mkdir(dirname($marker), 0700, true);
    file_put_contents($marker, date('c'));
}
