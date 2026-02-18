<?php
function load_db_config(): array {
    $fileConfig = [];
    $configFile = __DIR__ . '/../config.local.php';
    if (file_exists($configFile)) {
        $fileConfig = require $configFile;
    }

    return [
        'host' => getenv('DB_HOST') ?: ($fileConfig['host'] ?? '127.0.0.1'),
        'port' => getenv('DB_PORT') ?: ($fileConfig['port'] ?? '3306'),
        'name' => getenv('DB_NAME') ?: ($fileConfig['name'] ?? 'mfm_workshop'),
        'user' => getenv('DB_USER') ?: ($fileConfig['user'] ?? 'root'),
        'pass' => getenv('DB_PASSWORD') ?: ($fileConfig['pass'] ?? ''),
    ];
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg = load_db_config();
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        throw new RuntimeException('Database connection failed. Run installer setup and verify DB credentials.');
    }
}

function test_db_connection(array $cfg, bool $withDb = true): bool {
    $dsn = $withDb
        ? "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4"
        : "mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4";
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    return $pdo instanceof PDO;
}
