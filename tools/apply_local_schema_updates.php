<?php
declare(strict_types=1);

require __DIR__ . '/../public/includes/db.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function databaseName(PDO $pdo): string
{
    $name = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($name) || $name === '') {
        throw new RuntimeException('No database selected for schema migration.');
    }

    return $name;
}

function tableExists(PDO $pdo, string $dbName, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->execute([$dbName, $table]);

    return (bool) $stmt->fetchColumn();
}

function columnExists(PDO $pdo, string $dbName, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute([$dbName, $table, $column]);

    return (bool) $stmt->fetchColumn();
}

function indexExists(PDO $pdo, string $dbName, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    $stmt->execute([$dbName, $table, $index]);

    return (bool) $stmt->fetchColumn();
}

function foreignKeyExists(PDO $pdo, string $dbName, string $table, string $foreignKey): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1'
    );
    $stmt->execute([$dbName, $table, $foreignKey, 'FOREIGN KEY']);

    return (bool) $stmt->fetchColumn();
}

function applyChange(string $description, callable $condition, callable $action): void
{
    if ($condition()) {
        echo "[skip] {$description}" . PHP_EOL;
        return;
    }

    $action();
    echo "[apply] {$description}" . PHP_EOL;
}

function applyStatement(PDO $pdo, string $description, callable $condition, string $sql): void
{
    applyChange(
        $description,
        $condition,
        static function () use ($pdo, $sql): void {
            $pdo->exec($sql);
        }
    );
}

try {
    $dbName = databaseName($pdo);

    applyStatement(
        $pdo,
        'Create messages table',
        static fn(): bool => tableExists($pdo, $dbName, 'messages'),
        <<<SQL
        CREATE TABLE messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            message TEXT NOT NULL,
            timestamp TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_messages_sender (sender_id),
            KEY idx_messages_receiver (receiver_id),
            KEY idx_messages_timestamp (timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        SQL
    );

    applyStatement(
        $pdo,
        'Create reservation_slots table',
        static fn(): bool => tableExists($pdo, $dbName, 'reservation_slots'),
        <<<SQL
        CREATE TABLE reservation_slots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reservation_id INT NOT NULL,
            time TIME NOT NULL,
            section_number INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_reservation_slots_reservation_id (reservation_id),
            UNIQUE KEY uq_reservation_slots_unique (reservation_id, time, section_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        SQL
    );

    applyStatement(
        $pdo,
        'Create admin_activity_logs table',
        static fn(): bool => tableExists($pdo, $dbName, 'admin_activity_logs'),
        <<<SQL
        CREATE TABLE admin_activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actor_user_id INT NULL,
            actor_role VARCHAR(20) NOT NULL DEFAULT 'admin',
            actor_name VARCHAR(255) NOT NULL,
            action_type VARCHAR(60) NOT NULL,
            subject_type VARCHAR(60) NOT NULL,
            subject_id INT NULL,
            description VARCHAR(255) NOT NULL,
            metadata_json LONGTEXT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_admin_activity_actor_user_id (actor_user_id),
            KEY idx_admin_activity_action_type (action_type),
            KEY idx_admin_activity_subject_type (subject_type),
            KEY idx_admin_activity_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        SQL
    );

    applyStatement(
        $pdo,
        'Create notifications table',
        static fn(): bool => tableExists($pdo, $dbName, 'notifications'),
        <<<SQL
        CREATE TABLE notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            target_role VARCHAR(20) NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'general',
            title VARCHAR(160) NOT NULL,
            message TEXT NOT NULL,
            link_url VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            KEY idx_notifications_user_id (user_id),
            KEY idx_notifications_target_role (target_role),
            KEY idx_notifications_is_read (is_read),
            KEY idx_notifications_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        SQL
    );

    applyStatement(
        $pdo,
        'Add courts.member_price',
        static fn(): bool => columnExists($pdo, $dbName, 'courts', 'member_price'),
        'ALTER TABLE courts ADD COLUMN member_price DECIMAL(10,2) NULL AFTER price'
    );

    applyStatement(
        $pdo,
        'Add users.membership_status',
        static fn(): bool => columnExists($pdo, $dbName, 'users', 'membership_status'),
        "ALTER TABLE users ADD COLUMN membership_status VARCHAR(20) NOT NULL DEFAULT 'inactive' AFTER role"
    );

    applyStatement(
        $pdo,
        'Add users.membership_plan',
        static fn(): bool => columnExists($pdo, $dbName, 'users', 'membership_plan'),
        'ALTER TABLE users ADD COLUMN membership_plan VARCHAR(100) NULL AFTER membership_status'
    );

    applyStatement(
        $pdo,
        'Add users.member_since',
        static fn(): bool => columnExists($pdo, $dbName, 'users', 'member_since'),
        'ALTER TABLE users ADD COLUMN member_since DATE NULL AFTER membership_plan'
    );

    applyStatement(
        $pdo,
        'Add users.membership_expires_at',
        static fn(): bool => columnExists($pdo, $dbName, 'users', 'membership_expires_at'),
        'ALTER TABLE users ADD COLUMN membership_expires_at DATE NULL AFTER member_since'
    );

    applyStatement(
        $pdo,
        'Add users.membership_benefits',
        static fn(): bool => columnExists($pdo, $dbName, 'users', 'membership_benefits'),
        'ALTER TABLE users ADD COLUMN membership_benefits TEXT NULL AFTER membership_expires_at'
    );

    applyStatement(
        $pdo,
        'Add courts.owner_id',
        static fn(): bool => columnExists($pdo, $dbName, 'courts', 'owner_id'),
        'ALTER TABLE courts ADD COLUMN owner_id INT NULL AFTER member_price'
    );

    applyStatement(
        $pdo,
        'Add courts.open_time',
        static fn(): bool => columnExists($pdo, $dbName, 'courts', 'open_time'),
        'ALTER TABLE courts ADD COLUMN open_time TIME NULL AFTER location'
    );

    applyStatement(
        $pdo,
        'Add courts.close_time',
        static fn(): bool => columnExists($pdo, $dbName, 'courts', 'close_time'),
        'ALTER TABLE courts ADD COLUMN close_time TIME NULL AFTER open_time'
    );

    applyStatement(
        $pdo,
        'Add reservations.guest_name',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'guest_name'),
        'ALTER TABLE reservations ADD COLUMN guest_name VARCHAR(255) NULL AFTER user_id'
    );

    applyStatement(
        $pdo,
        'Add reservations.guest_email',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'guest_email'),
        'ALTER TABLE reservations ADD COLUMN guest_email VARCHAR(255) NULL AFTER guest_name'
    );

    applyStatement(
        $pdo,
        'Add reservations.court_id',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'court_id'),
        'ALTER TABLE reservations ADD COLUMN court_id INT NULL AFTER court'
    );

    applyStatement(
        $pdo,
        'Add reservations.section_number',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'section_number'),
        'ALTER TABLE reservations ADD COLUMN section_number INT NOT NULL DEFAULT 0 AFTER court_id'
    );

    applyStatement(
        $pdo,
        'Add reservations.is_admin_set',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'is_admin_set'),
        'ALTER TABLE reservations ADD COLUMN is_admin_set TINYINT(1) NOT NULL DEFAULT 0 AFTER section_number'
    );

    applyStatement(
        $pdo,
        'Add reservations.hourly_rate',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'hourly_rate'),
        'ALTER TABLE reservations ADD COLUMN hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_method'
    );

    applyStatement(
        $pdo,
        'Add reservations.subtotal',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'subtotal'),
        'ALTER TABLE reservations ADD COLUMN subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER hourly_rate'
    );

    applyStatement(
        $pdo,
        'Add reservations.discount_type',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'discount_type'),
        "ALTER TABLE reservations ADD COLUMN discount_type VARCHAR(30) NOT NULL DEFAULT 'none' AFTER subtotal"
    );

    applyStatement(
        $pdo,
        'Add reservations.discount_label',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'discount_label'),
        'ALTER TABLE reservations ADD COLUMN discount_label VARCHAR(100) NULL AFTER discount_type'
    );

    applyStatement(
        $pdo,
        'Add reservations.discount_amount',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'discount_amount'),
        'ALTER TABLE reservations ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_label'
    );

    applyStatement(
        $pdo,
        'Add reservations.processing_fee',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'processing_fee'),
        'ALTER TABLE reservations ADD COLUMN processing_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_amount'
    );

    applyStatement(
        $pdo,
        'Add reservations.payment',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'payment'),
        'ALTER TABLE reservations ADD COLUMN payment DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER processing_fee'
    );

    applyStatement(
        $pdo,
        'Add reservations.payment_proof_path',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'payment_proof_path'),
        'ALTER TABLE reservations ADD COLUMN payment_proof_path VARCHAR(255) NULL AFTER payment'
    );

    applyStatement(
        $pdo,
        'Add reservations.booking_source',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'booking_source'),
        "ALTER TABLE reservations ADD COLUMN booking_source VARCHAR(20) NOT NULL DEFAULT 'advance' AFTER payment_proof_path"
    );

    applyStatement(
        $pdo,
        'Add reservations.game_status',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'game_status'),
        "ALTER TABLE reservations ADD COLUMN game_status VARCHAR(20) NOT NULL DEFAULT 'reserved' AFTER booking_source"
    );

    applyStatement(
        $pdo,
        'Add reservations.checked_in_at',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'checked_in_at'),
        'ALTER TABLE reservations ADD COLUMN checked_in_at DATETIME NULL AFTER game_status'
    );

    applyStatement(
        $pdo,
        'Add reservations.in_progress_at',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'in_progress_at'),
        'ALTER TABLE reservations ADD COLUMN in_progress_at DATETIME NULL AFTER checked_in_at'
    );

    applyStatement(
        $pdo,
        'Add reservations.completed_at',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'completed_at'),
        'ALTER TABLE reservations ADD COLUMN completed_at DATETIME NULL AFTER in_progress_at'
    );

    applyStatement(
        $pdo,
        'Allow NULL reservations.time for multi-slot bookings',
        static fn(): bool => false,
        'ALTER TABLE reservations MODIFY COLUMN time TIME NULL DEFAULT NULL'
    );

    applyStatement(
        $pdo,
        'Default reservations.user_id to 0',
        static fn(): bool => false,
        'ALTER TABLE reservations MODIFY COLUMN user_id INT(11) NOT NULL DEFAULT 0'
    );

    applyStatement(
        $pdo,
        'Set default reservations.payment_status',
        static fn(): bool => false,
        "ALTER TABLE reservations MODIFY COLUMN payment_status VARCHAR(10) NULL DEFAULT 'pending'"
    );

    applyStatement(
        $pdo,
        'Add reservation_guests.payment',
        static fn(): bool => columnExists($pdo, $dbName, 'reservation_guests', 'payment'),
        'ALTER TABLE reservation_guests ADD COLUMN payment DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER guest_contact'
    );

    applyStatement(
        $pdo,
        'Add index idx_courts_owner_id',
        static fn(): bool => indexExists($pdo, $dbName, 'courts', 'idx_courts_owner_id'),
        'ALTER TABLE courts ADD INDEX idx_courts_owner_id (owner_id)'
    );

    applyStatement(
        $pdo,
        'Add index idx_reservations_court_id',
        static fn(): bool => indexExists($pdo, $dbName, 'reservations', 'idx_reservations_court_id'),
        'ALTER TABLE reservations ADD INDEX idx_reservations_court_id (court_id)'
    );

    applyStatement(
        $pdo,
        'Add index idx_users_membership_status',
        static fn(): bool => indexExists($pdo, $dbName, 'users', 'idx_users_membership_status'),
        'ALTER TABLE users ADD INDEX idx_users_membership_status (membership_status)'
    );

    applyStatement(
        $pdo,
        'Add index idx_reservations_game_status',
        static fn(): bool => indexExists($pdo, $dbName, 'reservations', 'idx_reservations_game_status'),
        'ALTER TABLE reservations ADD INDEX idx_reservations_game_status (game_status)'
    );

    $adminIdStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
    $adminId = $adminIdStmt->fetchColumn();
    if ($adminId !== false) {
        $stmt = $pdo->prepare('UPDATE courts SET owner_id = ? WHERE owner_id IS NULL');
        $stmt->execute([(int) $adminId]);
        echo '[apply] Backfilled courts.owner_id from the first admin user' . PHP_EOL;
    } else {
        echo '[skip] Backfill courts.owner_id from the first admin user' . PHP_EOL;
    }

    $pdo->exec("UPDATE courts SET open_time = '08:00:00' WHERE open_time IS NULL");
    echo '[apply] Backfilled missing courts.open_time values' . PHP_EOL;

    $pdo->exec("UPDATE courts SET close_time = '22:00:00' WHERE close_time IS NULL");
    echo '[apply] Backfilled missing courts.close_time values' . PHP_EOL;

    $pdo->exec("UPDATE users SET membership_status = 'inactive' WHERE membership_status IS NULL OR membership_status = ''");
    echo '[apply] Backfilled users.membership_status values' . PHP_EOL;

    $pdo->exec(
        "UPDATE users
         SET membership_status = 'active'
         WHERE role = 'subscriber'
           AND membership_status = 'inactive'
           AND membership_plan IS NULL
           AND member_since IS NULL
           AND membership_expires_at IS NULL
           AND membership_benefits IS NULL"
    );
    echo '[apply] Backfilled legacy subscriber memberships' . PHP_EOL;

    $pdo->exec(
        "UPDATE users
         SET membership_plan = 'Legacy Member'
         WHERE role = 'subscriber'
           AND membership_status = 'active'
           AND (membership_plan IS NULL OR membership_plan = '')"
    );
    echo '[apply] Backfilled legacy subscriber membership plans' . PHP_EOL;

    $pdo->exec(
        "UPDATE users
         SET member_since = CURDATE()
         WHERE role = 'subscriber'
           AND membership_status = 'active'
           AND member_since IS NULL"
    );
    echo '[apply] Backfilled legacy subscriber member-since dates' . PHP_EOL;

    $pdo->exec("UPDATE users SET role = 'admin' WHERE role = 'owner'");
    echo '[apply] Normalized owner roles to admin' . PHP_EOL;

    $pdo->exec("UPDATE users SET role = 'user' WHERE role = 'subscriber'");
    echo '[apply] Normalized subscriber roles to user' . PHP_EOL;

    $pdo->exec(
        'UPDATE reservations r
         LEFT JOIN courts c ON c.name = r.court
         SET r.court_id = c.id
         WHERE r.court_id IS NULL'
    );
    echo '[apply] Backfilled reservations.court_id from courts.name matches' . PHP_EOL;

    $pdo->exec("UPDATE reservations SET section_number = 0 WHERE section_number IS NULL");
    echo '[apply] Backfilled reservations.section_number values' . PHP_EOL;

    $pdo->exec("UPDATE reservations SET is_admin_set = 0 WHERE is_admin_set IS NULL");
    echo '[apply] Backfilled reservations.is_admin_set values' . PHP_EOL;

    $pdo->exec("UPDATE reservations SET payment = 0.00 WHERE payment IS NULL");
    echo '[apply] Backfilled reservations.payment values' . PHP_EOL;

    $pdo->exec(
        'UPDATE reservations r
         LEFT JOIN courts c ON c.id = r.court_id
         SET r.hourly_rate = COALESCE(NULLIF(r.hourly_rate, 0.00), c.price, r.payment, 0.00)
         WHERE r.hourly_rate = 0.00'
    );
    echo '[apply] Backfilled reservations.hourly_rate values' . PHP_EOL;

    $pdo->exec(
        'UPDATE reservations
         SET subtotal = CASE
             WHEN subtotal <> 0.00 THEN subtotal
             WHEN payment > 0.00 THEN payment
             ELSE 0.00
         END'
    );
    echo '[apply] Backfilled reservations.subtotal values' . PHP_EOL;

    $pdo->exec("UPDATE reservations SET discount_type = 'none' WHERE discount_type IS NULL OR discount_type = ''");
    echo '[apply] Backfilled reservations.discount_type values' . PHP_EOL;

    $pdo->exec("UPDATE reservations SET discount_amount = 0.00 WHERE discount_amount IS NULL");
    echo '[apply] Backfilled reservations.discount_amount values' . PHP_EOL;

    $pdo->exec("UPDATE reservations SET processing_fee = 0.00 WHERE processing_fee IS NULL");
    echo '[apply] Backfilled reservations.processing_fee values' . PHP_EOL;

    $pdo->exec("UPDATE reservations SET booking_source = 'advance' WHERE booking_source IS NULL OR booking_source = ''");
    echo '[apply] Backfilled reservations.booking_source values' . PHP_EOL;

    $pdo->exec("UPDATE reservations SET game_status = 'reserved' WHERE game_status IS NULL OR game_status = ''");
    echo '[apply] Backfilled reservations.game_status values' . PHP_EOL;

    $pdo->exec("UPDATE reservations SET payment_status = 'pending' WHERE payment_status IS NULL OR payment_status = ''");
    echo '[apply] Backfilled reservations.payment_status values' . PHP_EOL;

    $pdo->exec(
        'UPDATE reservation_guests rg
         JOIN reservations r ON r.id = rg.reservation_id
         SET rg.payment = r.payment
         WHERE rg.payment = 0.00'
    );
    echo '[apply] Backfilled reservation_guests.payment values' . PHP_EOL;

    $pdo->exec(
        'INSERT INTO reservation_slots (reservation_id, time, section_number)
         SELECT r.id, r.time, COALESCE(r.section_number, 0)
         FROM reservations r
         LEFT JOIN reservation_slots rs
           ON rs.reservation_id = r.id
          AND rs.time = r.time
          AND rs.section_number = COALESCE(r.section_number, 0)
         WHERE r.time IS NOT NULL
           AND rs.id IS NULL'
    );
    echo '[apply] Backfilled reservation_slots from legacy reservation times' . PHP_EOL;

    applyStatement(
        $pdo,
        'Add FK fk_courts_owner_id',
        static fn(): bool => foreignKeyExists($pdo, $dbName, 'courts', 'fk_courts_owner_id'),
        'ALTER TABLE courts ADD CONSTRAINT fk_courts_owner_id FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL'
    );

    applyStatement(
        $pdo,
        'Add FK fk_reservations_court_id',
        static fn(): bool => foreignKeyExists($pdo, $dbName, 'reservations', 'fk_reservations_court_id'),
        'ALTER TABLE reservations ADD CONSTRAINT fk_reservations_court_id FOREIGN KEY (court_id) REFERENCES courts(id) ON DELETE SET NULL'
    );

    applyStatement(
        $pdo,
        'Add FK fk_reservation_slots_reservation_id',
        static fn(): bool => foreignKeyExists($pdo, $dbName, 'reservation_slots', 'fk_reservation_slots_reservation_id'),
        'ALTER TABLE reservation_slots ADD CONSTRAINT fk_reservation_slots_reservation_id FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE'
    );

    applyStatement(
        $pdo,
        'Add FK fk_messages_sender_id',
        static fn(): bool => foreignKeyExists($pdo, $dbName, 'messages', 'fk_messages_sender_id'),
        'ALTER TABLE messages ADD CONSTRAINT fk_messages_sender_id FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE'
    );

    applyStatement(
        $pdo,
        'Add FK fk_messages_receiver_id',
        static fn(): bool => foreignKeyExists($pdo, $dbName, 'messages', 'fk_messages_receiver_id'),
        'ALTER TABLE messages ADD CONSTRAINT fk_messages_receiver_id FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE'
    );

    echo 'Local schema updates are complete.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Schema migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
