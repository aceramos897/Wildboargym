<?php
/**
 * Database access layer — PDO with PREPARED STATEMENTS only.
 * Prepared statements + bound parameters prevent SQL injection.
 * Driver: 'mysql' (Hostinger/MariaDB production) or 'sqlite' (local dev).
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    $d = cfg('db');
    $opts = [
        PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES     => false,
        PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,
    ];
    if ($d['driver'] === 'sqlite') {
        $dir = dirname($d['file']);
        if ($dir !== '' && !is_dir($dir)) { mkdir($dir, 0777, true); }
        $dsn = 'sqlite:' . $d['file'];
    } else {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                       $d['host'], $d['port'], $d['name']);
    }
    try {
        $pdo = new PDO($dsn, (string)$d['user'], (string)$d['pass'], $opts);
    } catch (Throwable $ex) {
        throw new RuntimeException('Database connection failed: ' . $ex->getMessage());
    }
    return $pdo;
}

/** Prepare + execute; returns the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function fetch_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function fetch_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_now(): string  { return date('Y-m-d H:i:s'); }
function db_today(): string { return date('Y-m-d'); }