<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        global $CONFIG;
        $c = $CONFIG['db'];
        $pdo = db_connect($c['host'], $c['name'], $c['user'], $c['pass'], (int)($c['port'] ?? 3306));
    }
    return $pdo;
}

function db_connect(string $host, string $name, string $user, string $pass, int $port = 3306): PDO
{
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function q_val(string $sql, array $params = []): mixed
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

function q_insert(string $sql, array $params = []): int
{
    q($sql, $params);
    return (int)db()->lastInsertId();
}

/** "?, ?, ?" για IN() */
function in_ph(array $ids): string
{
    return implode(',', array_fill(0, max(1, count($ids)), '?'));
}

/* ---------- Ρυθμίσεις εφαρμογής (αλλάζουν από τον admin, πίνακας tt_settings) ---------- */

function settings_all(bool $reload = false): array
{
    static $s = null;
    if ($s === null || $reload) {
        try {
            $s = array_column(q_all('SELECT name, value FROM tt_settings'), 'value', 'name');
        } catch (Throwable $e) {
            $s = []; // ο πίνακας δεν υπάρχει ακόμη (πριν την αναβάθμιση)
        }
    }
    return $s;
}

function setting(string $name, ?string $default = null): ?string
{
    return settings_all()[$name] ?? $default;
}

function set_setting(string $name, ?string $value): void
{
    if ($value === null) q('DELETE FROM tt_settings WHERE name = ?', [$name]);
    else q('INSERT INTO tt_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
    settings_all(true);
}
