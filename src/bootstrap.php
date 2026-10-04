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
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            k TEXT PRIMARY KEY,
            v TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL COLLATE NOCASE UNIQUE,
            password_hash TEXT NOT NULL,
            is_super INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\')),
            last_login_at TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS query_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            field_key TEXT NOT NULL COLLATE NOCASE UNIQUE,
            label TEXT NOT NULL,
            is_required INTEGER NOT NULL DEFAULT 0,
            is_enabled INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS candidate_field_values (
            candidate_id INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
            field_id INTEGER NOT NULL REFERENCES query_fields(id) ON DELETE CASCADE,
            value TEXT NOT NULL,
            PRIMARY KEY(candidate_id, field_id)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS access_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\')),
            ip_address TEXT NOT NULL,
            method TEXT NOT NULL,
            path TEXT NOT NULL,
            ticket_no TEXT,
            candidate_name TEXT,
            outcome TEXT NOT NULL,
            detail TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rate_limits (
            scope TEXT NOT NULL,
            ip_address TEXT NOT NULL,
            window_started INTEGER NOT NULL,
            hits INTEGER NOT NULL,
            PRIMARY KEY(scope, ip_address)
        )'
    );
    seed_application_data($pdo);
}

function seed_application_data(PDO $pdo): void
{
    $settings = [
        'site_title' => '成绩查询',
        'site_footer' => '独立成绩查询。请使用准考证号和姓名查询本人成绩。',
        'query_hint' => '分数相同则并列，下一名次按人数顺延。总分按已录入科目合计，排名只在当前成绩库内计算。',
        'captcha_length' => '5',
        'captcha_charset' => 'alnum',
        'captcha_expiry_seconds' => '300',
        'captcha_noise_level' => '2',
        'public_query_limit' => '15',
        'admin_login_limit' => '5',
    ];
    $set = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO NOTHING');
    foreach ($settings as $key => $value) {
        $set->execute([$key, $value]);
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) {
        $hash = (string) (app_config()['admin_password_hash'] ?? '');
        if ($hash !== '') {
            $pdo->prepare(
                'INSERT INTO admins (username, password_hash, is_super) VALUES (?, ?, 1)'
            )->execute(['admin', $hash]);
        }
    }
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

function setting(string $key, string $default = ''): string
{
    $stmt = app_pdo()->prepare('SELECT v FROM settings WHERE k = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? $value : $default;
}

function save_settings(array $values): void
{
    $stmt = app_pdo()->prepare(
        'INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v'
    );
    foreach ($values as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function captcha_code(): string
{
    start_app_session();
    if (empty($_SESSION['captcha_code'])) {
        refresh_captcha();
    }
    return (string) $_SESSION['captcha_code'];
}

function refresh_captcha(): void
{
    start_app_session();
    $alphabet = setting('captcha_charset', 'alnum') === 'digits'
        ? '23456789'
        : '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $length = captcha_length();
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $_SESSION['captcha_code'] = $code;
    $_SESSION['captcha_created_at'] = time();
}

function captcha_length(): int
{
    return max(4, min(6, (int) setting('captcha_length', '5')));
}

function captcha_image_width(): int
{
    return max(145, captcha_length() * 31 + 15);
}

function captcha_ok(string $answer): bool
{
    start_app_session();
    $known = (string) ($_SESSION['captcha_code'] ?? '');
    $createdAt = (int) ($_SESSION['captcha_created_at'] ?? 0);
    $valid = $known !== ''
        && $createdAt > 0
        && (time() - $createdAt) <= max(60, min(900, (int) setting('captcha_expiry_seconds', '300')))
        && hash_equals($known, strtoupper(trim($answer)));
    refresh_captcha();
    return $valid;
}

function rate_limit_allows(string $scope, int $maximumHits, int $windowSeconds): bool
{
    $pdo = app_pdo();
    $ip = client_ip();
    $now = time();
    $stmt = $pdo->prepare('SELECT window_started, hits FROM rate_limits WHERE scope = ? AND ip_address = ?');
    $stmt->execute([$scope, $ip]);
    $row = $stmt->fetch();
    if (!$row || $now - (int) $row['window_started'] >= $windowSeconds) {
        $pdo->prepare(
            'INSERT INTO rate_limits (scope, ip_address, window_started, hits) VALUES (?, ?, ?, 1)
             ON CONFLICT(scope, ip_address) DO UPDATE SET window_started = excluded.window_started, hits = 1'
        )->execute([$scope, $ip, $now]);
        return true;
    }
    if ((int) $row['hits'] >= $maximumHits) {
        return false;
    }
    $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE scope = ? AND ip_address = ?')
        ->execute([$scope, $ip]);
    return true;
}

function current_admin(): ?array
{
    start_app_session();
    $id = $_SESSION['admin_id'] ?? 0;
    if (!is_int($id) && !ctype_digit((string) $id)) {
        return null;
    }
    $stmt = app_pdo()->prepare('SELECT id, username, is_super FROM admins WHERE id = ?');
    $stmt->execute([(int) $id]);
    $admin = $stmt->fetch();
    return $admin ?: null;
}

function require_admin(): array
{
    $admin = current_admin();
    if ($admin === null) {
        redirect_to('admin.php');
    }
    return $admin;
}

function log_access(string $outcome, string $detail = '', string $ticket = '', string $name = ''): void
{
    $stmt = app_pdo()->prepare(
        'INSERT INTO access_logs (ip_address, method, path, ticket_no, candidate_name, outcome, detail)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        client_ip(),
        $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        $ticket,
        $name,
        $outcome,
        mb_substr($detail, 0, 255),
    ]);
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}
