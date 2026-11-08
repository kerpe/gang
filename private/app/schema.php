<?php

declare(strict_types=1);

// Creates the tables on first use. The marker file keeps later requests from re-checking;
// bump SCHEMA_VERSION and add CREATE/ALTER statements below when the schema changes.
const SCHEMA_VERSION = 21;

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
            experience TINYINT NOT NULL DEFAULT 0,
            available_until DATE NULL,
            notify TINYINT NOT NULL DEFAULT 1,
            blocked TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_login_at DATETIME NULL,
            launch_mail_at DATETIME NULL,
            durations VARCHAR(100) NOT NULL DEFAULT '',
            terms_version VARCHAR(20) NOT NULL DEFAULT '',
            terms_accepted_at DATETIME NULL",
        // Every acceptance of the terms and privacy policy (see legal.php): proof of consent.
        'consents' => "id $id,
            user_id INT NOT NULL,
            version VARCHAR(20) NOT NULL,
            accepted_at DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT ''",
        'login_codes' => "id $id,
            email VARCHAR(190) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            ip_hash CHAR(64) NOT NULL DEFAULT ''",
        'venues' => "id $id,
            manager_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            city VARCHAR(20) NOT NULL,
            address VARCHAR(190) NOT NULL DEFAULT '',
            about TEXT NULL,
            created_at DATETIME NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT '',
            concept VARCHAR(120) NOT NULL DEFAULT '',
            hours VARCHAR(190) NOT NULL DEFAULT '',
            team_size VARCHAR(10) NOT NULL DEFAULT '',
            perks VARCHAR(255) NOT NULL DEFAULT '',
            website VARCHAR(255) NOT NULL DEFAULT '',
            instagram VARCHAR(255) NOT NULL DEFAULT '',
            facebook VARCHAR(255) NOT NULL DEFAULT '',
            tiktok VARCHAR(255) NOT NULL DEFAULT '',
            logo VARCHAR(64) NULL",
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
            min_experience TINYINT NOT NULL DEFAULT 1,
            status VARCHAR(10) NOT NULL DEFAULT 'open',
            notified INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            filled_at DATETIME NULL",
        // status: sent | accepted | declined | withdrawn | cancelled (the contract was cancelled later)
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
        // Experience per role a worker can take on (users.roles lists the same roles).
        'worker_roles' => "id $id,
            user_id INT NOT NULL,
            role VARCHAR(30) NOT NULL,
            experience TINYINT NOT NULL DEFAULT 0",
        // Version 7: a manager's personal invitation to a worker they already worked with ("Mano komanda").
        'invites' => "id $id,
            request_id INT NOT NULL,
            worker_id INT NOT NULL,
            created_at DATETIME NOT NULL",
        // Version 9: arrival confirmation the day before a work period starts (one row per contract and day).
        'shift_checks' => "id $id,
            contract_id INT NOT NULL,
            shift_date DATE NOT NULL,
            token CHAR(32) NOT NULL,
            reminded_at DATETIME NOT NULL,
            confirmed_at DATETIME NULL,
            declined_at DATETIME NULL,
            alerted_at DATETIME NULL",
        // Version 12: workers who joined a manager's team through the manager's invitation link.
        // Version 14: earlier link words (/k/{word}) that keep working after a venue is renamed.
        'invite_aliases' => "id $id,
            user_id INT NOT NULL,
            slug VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL",
        'team_links' => "id $id,
            manager_id INT NOT NULL,
            worker_id INT NOT NULL,
            created_at DATETIME NOT NULL",
        // Version 12: team invitations a manager sent by email (duplicates and daily limit).
        'team_mails' => "id $id,
            manager_id INT NOT NULL,
            email VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL",
        // Version 16: job offers already emailed to a worker, so a profile save does not repeat them.
        'job_mails' => "id $id,
            request_id INT NOT NULL,
            worker_id INT NOT NULL,
            created_at DATETIME NOT NULL",
        // Version 18: messages between the employer and the worker of a contract (messages.php).
        'messages' => "id $id,
            contract_id INT NOT NULL,
            sender_id INT NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL",
        // Version 21: messages a worker adds to an application while the employer has not decided yet.
        'response_notes' => "id $id,
            response_id INT NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL",
        // Version 18: per contract and member – the last message seen, when the chat was open, the last email about it.
        'chat_reads' => "id $id,
            contract_id INT NOT NULL,
            user_id INT NOT NULL,
            last_id INT NOT NULL DEFAULT 0,
            seen_at DATETIME NULL,
            mailed_at DATETIME NULL",
        // Version 19: bulk email waiting to be sent (mail.php process_mail_queue()).
        'mail_queue' => "id $id,
            to_email VARCHAR(190) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            headers TEXT NOT NULL,
            priority INT NOT NULL DEFAULT 10,
            attempts INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL",
        // Version 19: site settings changed from /admin (admin_password = password_hash of the admin password).
        'settings' => "name VARCHAR(50) NOT NULL PRIMARY KEY,
            value TEXT NOT NULL",
        // Pre-launch sign-ups. kind: manager (employers wait for launch) | worker (from before
        // workers could build profiles; they are invited to sign up now).
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
    // Version 4: worker experience and the experience a request requires.
    $columns = [
        'users ADD photo VARCHAR(64) NULL',
        'users ADD experience TINYINT NOT NULL DEFAULT 0',
        'requests ADD min_experience TINYINT NOT NULL DEFAULT 1',
        // Version 6: several date ranges in one request ("from:to,from:to"), empty for one range.
        "requests ADD dates VARCHAR(500) NOT NULL DEFAULT ''",
        // Version 8: workers who signed up before launch get one "GANG jau veikia" email; NULL = not sent yet.
        'users ADD launch_mail_at DATETIME NULL',
        // Version 10: job durations a worker is interested in (KINDS keys); empty = all of them.
        "users ADD durations VARCHAR(100) NOT NULL DEFAULT ''",
        // Version 11: venue profile – type, concept, opening hours, team size, staff perks, links and logo.
        "venues ADD type VARCHAR(30) NOT NULL DEFAULT ''",
        "venues ADD concept VARCHAR(120) NOT NULL DEFAULT ''",
        "venues ADD hours VARCHAR(190) NOT NULL DEFAULT ''",
        "venues ADD team_size VARCHAR(10) NOT NULL DEFAULT ''",
        "venues ADD perks VARCHAR(255) NOT NULL DEFAULT ''",
        "venues ADD website VARCHAR(255) NOT NULL DEFAULT ''",
        "venues ADD instagram VARCHAR(255) NOT NULL DEFAULT ''",
        "venues ADD facebook VARCHAR(255) NOT NULL DEFAULT ''",
        "venues ADD tiktok VARCHAR(255) NOT NULL DEFAULT ''",
        'venues ADD logo VARCHAR(64) NULL',
        // Version 12: cancelled contracts (by whom, why, late = less than a day before the work) and no-shows.
        'contracts ADD cancelled_at DATETIME NULL',
        "contracts ADD cancelled_by VARCHAR(10) NOT NULL DEFAULT ''",
        "contracts ADD cancel_reason VARCHAR(20) NOT NULL DEFAULT ''",
        "contracts ADD cancel_note VARCHAR(500) NOT NULL DEFAULT ''",
        'contracts ADD late TINYINT NOT NULL DEFAULT 0',
        'contracts ADD no_show_at DATETIME NULL',
        // Version 12: team first (other workers see the request from public_at), events (several roles, one event_id).
        'requests ADD public_at DATETIME NULL',
        'requests ADD event_id INT NULL',
        "requests ADD event_title VARCHAR(120) NOT NULL DEFAULT ''",
        // Version 12: three criteria per review; rating stays the rounded average.
        'reviews ADD c1 TINYINT NULL',
        'reviews ADD c2 TINYINT NULL',
        'reviews ADD c3 TINYINT NULL',
        // Version 12: personal invitation link (/k/{code}) and who invited the person.
        'users ADD invite_code VARCHAR(16) NULL',
        'users ADD referred_by INT NULL',
        // Version 13: the readable link word (/k/palangosbarolegenda); invite_code keeps old links working.
        'users ADD invite_slug VARCHAR(80) NULL',
        // Version 15: the terms version the member last accepted (consents keeps every acceptance).
        "users ADD terms_version VARCHAR(20) NOT NULL DEFAULT ''",
        'users ADD terms_accepted_at DATETIME NULL',
        // Version 17: a worker can apply for only some of the job's days ("from:to,…"; empty = all days),
        // and a contract can cover only some of them (NULL start_date = the request's dates).
        "responses ADD dates VARCHAR(500) NOT NULL DEFAULT ''",
        'contracts ADD start_date DATE NULL',
        'contracts ADD end_date DATE NULL',
        "contracts ADD dates VARCHAR(500) NOT NULL DEFAULT ''",
        // Version 19: GANG checks every employer before they publish searches or open worker profiles.
        'users ADD verified_at DATETIME NULL',
        // Version 19: raised by "Atsijungti visuose įrenginiuose"; sessions keep the value they started with.
        'users ADD session_epoch INT NOT NULL DEFAULT 0',
        // Version 20: keyed hash of the visitor's IP, so one network cannot mail codes to many addresses.
        "login_codes ADD ip_hash CHAR(64) NOT NULL DEFAULT ''",
        // Version 21: the employer changed the job's dates or pay after this application; set until the
        // candidate confirms the new terms ("Tinka") – until then the employer cannot approve them.
        'responses ADD recheck_at DATETIME NULL',
    ];
    foreach ($columns as $column) {
        try {
            $db->exec("ALTER TABLE $column");
        } catch (PDOException) {
            // Already there.
        }
    }
    if (!$db->query('SELECT 1 FROM availability LIMIT 1')->fetchColumn()) {
        $db->prepare('INSERT INTO availability (user_id, start_date, end_date) SELECT id, ?, available_until FROM users WHERE available_until >= ?')
            ->execute([date('Y-m-d'), date('Y-m-d')]);
    }
    // Version 5: experience moved from one value per worker to one per role.
    if (!$db->query('SELECT 1 FROM worker_roles LIMIT 1')->fetchColumn()) {
        $insert = $db->prepare('INSERT INTO worker_roles (user_id, role, experience) VALUES (?, ?, ?)');
        foreach ($db->query("SELECT id, roles, experience FROM users WHERE kind = 'worker'")->fetchAll() as $w) {
            foreach (array_filter(explode(',', (string) $w['roles'])) as $role) {
                $insert->execute([$w['id'], $role, $w['experience']]);
            }
        }
    }
    // Version 19: employers who signed up before the check existed count as checked (once).
    if (!$db->query("SELECT 1 FROM settings WHERE name = 'managers_verified'")->fetchColumn()) {
        $db->exec("UPDATE users SET verified_at = created_at WHERE kind = 'manager' AND verified_at IS NULL");
        $db->exec("INSERT INTO settings (name, value) VALUES ('managers_verified', '1')");
    }
    // Version 6: a request requires at most one year of experience.
    $db->exec('UPDATE requests SET min_experience = 3 WHERE min_experience > 3');
    $indexes = [
        'worker_roles_user' => 'worker_roles (user_id)',
        'worker_roles_role' => 'worker_roles (role, experience)',
        'availability_user' => 'availability (user_id)',
        'responses_request' => 'responses (request_id)',
        'contracts_worker' => 'contracts (worker_id)',
        'invites_request' => 'invites (request_id, worker_id)',
        'invites_worker' => 'invites (worker_id)',
        'shift_checks_contract' => 'shift_checks (contract_id, shift_date)',
        'shift_checks_token' => 'shift_checks (token)',
        'reviews_contract' => 'reviews (contract_id)',
        'requests_status' => 'requests (status)',
        'login_codes_email' => 'login_codes (email)',
        'login_codes_ip' => 'login_codes (ip_hash, created_at)',
        'consents_user' => 'consents (user_id)',
        'team_links_manager' => 'team_links (manager_id)',
        'team_mails_manager' => 'team_mails (manager_id, created_at)',
        'requests_event' => 'requests (event_id)',
        'users_invite_code' => 'users (invite_code)',
        'users_invite_slug' => 'users (invite_slug)',
        'invite_aliases_slug' => 'invite_aliases (slug)',
        'job_mails_worker' => 'job_mails (worker_id, request_id)',
        'messages_contract' => 'messages (contract_id, id)',
        'messages_sender' => 'messages (sender_id, created_at)',
        'response_notes_response' => 'response_notes (response_id, id)',
        'chat_reads_contract' => 'chat_reads (contract_id, user_id)',
        'mail_queue_pending' => 'mail_queue (sent_at, priority, id)',
    ];
    foreach ($indexes as $name => $on) {
        try {
            $db->exec("CREATE INDEX $name ON $on");
        } catch (PDOException) {
            // Already exists.
        }
    }
    // Version 19: one application per worker and request, one review per contract and side.
    $unique = [
        'responses_once' => 'responses (request_id, worker_id)',
        'reviews_once' => 'reviews (contract_id, author)',
    ];
    foreach ($unique as $name => $on) {
        try {
            $db->exec("CREATE UNIQUE INDEX $name ON $on");
        } catch (PDOException) {
            // Already exists (or old duplicates; then the code checks still apply).
        }
    }
    @mkdir(dirname($marker), 0700, true);
    file_put_contents($marker, date('c'));
}
