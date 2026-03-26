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
        'Add courts.owner_id',
        static fn(): bool => columnExists($pdo, $dbName, 'courts', 'owner_id'),
        'ALTER TABLE courts ADD COLUMN owner_id INT NULL AFTER price'
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
        'Add reservations.payment',
        static fn(): bool => columnExists($pdo, $dbName, 'reservations', 'payment'),
        'ALTER TABLE reservations ADD COLUMN payment DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_method'
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
