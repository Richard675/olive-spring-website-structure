<?php
/** SQLite database with automatic, versioned migrations. */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $file = storage_path('database.sqlite');
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    @chmod($file, 0640);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $migrations = [
        1 => <<<SQL
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL,
                last_login_at TEXT
            );
            CREATE TABLE inquiries (
                id INTEGER PRIMARY KEY,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                email TEXT NOT NULL,
                phone TEXT NOT NULL,
                county TEXT NOT NULL DEFAULT '',
                service TEXT NOT NULL DEFAULT '',
                message TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'new',
                notes TEXT NOT NULL DEFAULT '',
                email_sent INTEGER NOT NULL DEFAULT 0,
                ip_hash TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX inquiries_status ON inquiries(status, created_at);
            CREATE TABLE applications (
                id INTEGER PRIMARY KEY,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                email TEXT NOT NULL,
                phone TEXT NOT NULL,
                position TEXT NOT NULL,
                county TEXT NOT NULL DEFAULT '',
                availability TEXT NOT NULL DEFAULT '',
                experience TEXT NOT NULL DEFAULT '',
                message TEXT NOT NULL DEFAULT '',
                resume_file TEXT,
                resume_name TEXT,
                resume_mime TEXT,
                resume_size INTEGER,
                status TEXT NOT NULL DEFAULT 'new',
                notes TEXT NOT NULL DEFAULT '',
                email_sent INTEGER NOT NULL DEFAULT 0,
                ip_hash TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX applications_status ON applications(status, created_at);
            CREATE TABLE activity (
                id INTEGER PRIMARY KEY,
                created_at TEXT NOT NULL,
                user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                entity TEXT NOT NULL,
                entity_id INTEGER NOT NULL,
                action TEXT NOT NULL,
                detail TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX activity_entity ON activity(entity, entity_id);
            CREATE TABLE rate_limits (
                bucket TEXT NOT NULL,
                hit_at INTEGER NOT NULL
            );
            CREATE INDEX rate_limits_bucket ON rate_limits(bucket, hit_at);
            SQL,
    ];

    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    foreach ($migrations as $target => $sql) {
        if ($version >= $target) continue;
        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $pdo->exec('PRAGMA user_version = ' . $target);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

/** Run a prepared statement and return it. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function audit(string $entity, int $entityId, string $action, string $detail = ''): void
{
    q('INSERT INTO activity (created_at, user_id, entity, entity_id, action, detail) VALUES (?, ?, ?, ?, ?, ?)',
      [now(), current_user()['id'] ?? null, $entity, $entityId, $action, $detail]);
}
