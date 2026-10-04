<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require APP_ROOT . '/config.php';
    }
    return $config;
}

function app_db_path(): string
{
    $fromEnv = getenv('SCORE_DB');
    if (is_string($fromEnv) && $fromEnv !== '') {
        return $fromEnv;
    }
    return APP_ROOT . '/data/scores.sqlite';
}

function app_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = app_db_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建数据库目录');
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    if (getenv('SCORE_SKIP_SEED') !== '1') {
        seed_if_empty($pdo);
    }
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS candidates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_no TEXT NOT NULL COLLATE NOCASE UNIQUE,
            name TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS scores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            candidate_id INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
            subject TEXT NOT NULL,
            score REAL NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            UNIQUE(candidate_id, subject)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS meta (
            k TEXT PRIMARY KEY,
            v TEXT NOT NULL
        )'
    );
}

function seed_if_empty(PDO $pdo): void
{
    $seeded = $pdo->query('SELECT v FROM meta WHERE k = \'seeded\'')->fetchColumn();
    if ($seeded === '1') {
        return;
    }
    $count = (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn();
    if ($count > 0) {
        $pdo->prepare('INSERT INTO meta (k, v) VALUES (\'seeded\', \'1\') ON CONFLICT(k) DO UPDATE SET v = \'1\'')->execute();
        return;
    }

    $people = [
        ['26010001', '陈予安', ['语文' => 128, '数学' => 135, '英语' => 126, '物理' => 91]],
        ['26010002', '林知夏', ['语文' => 121, '数学' => 118, '英语' => 132, '物理' => 88]],
        ['26010003', '周衡', ['语文' => 115, '数学' => 141, '英语' => 109, '物理' => 95]],
        ['26010004', '许清和', ['语文' => 128, '数学' => 102, '英语' => 118, '物理' => 76]],
        ['26010005', '沈嘉树', ['语文' => 109, '数学' => 127, '英语' => 121, '物理' => 84]],
        ['26010006', '何晚宁', ['语文' => 136, '数学' => 119, '英语' => 127, '物理' => 90]],
        ['26010007', '吴叙', ['语文' => 98, '数学' => 135, '英语' => 104, '物理' => 81]],
        ['26010008', '郑明川', ['语文' => 117, '数学' => 110, '英语' => 115, '物理' => 88]],
    ];

    $insertCandidate = $pdo->prepare('INSERT INTO candidates (ticket_no, name) VALUES (?, ?)');
    $insertScore = $pdo->prepare(
        'INSERT INTO scores (candidate_id, subject, score, sort_order) VALUES (?, ?, ?, ?)'
    );

    $pdo->beginTransaction();
    foreach ($people as $person) {
        $insertCandidate->execute([$person[0], $person[1]]);
        $candidateId = (int) $pdo->lastInsertId();
        $order = 0;
        foreach ($person[2] as $subject => $score) {
            $insertScore->execute([$candidateId, $subject, $score, $order]);
            $order++;
        }
    }
    $pdo->prepare('INSERT INTO meta (k, v) VALUES (\'seeded\', \'1\')')->execute();
    $pdo->commit();
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_score(float $score): string
{
    $rounded = round($score, 1);
    if (abs($rounded - round($rounded)) < 0.001) {
        return (string) (int) round($rounded);
    }
    return number_format($rounded, 1, '.', '');
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('score_query');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_app_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    start_app_session();
    $sent = $_POST['csrf'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    return is_string($sent) && is_string($known) && $known !== '' && hash_equals($known, $sent);
}

function admin_password_ok(string $password): bool
{
    $env = getenv('SCORE_ADMIN_PASSWORD');
    if (is_string($env) && $env !== '') {
        return hash_equals($env, $password);
    }
    $hash = app_config()['admin_password_hash'] ?? '';
    return is_string($hash) && $hash !== '' && password_verify($password, $hash);
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}
