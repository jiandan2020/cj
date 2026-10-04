<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$db = sys_get_temp_dir() . '/score-query-admin-test-' . getmypid() . '.sqlite';
@unlink($db);
putenv('SCORE_DB=' . $db);

require $root . '/src/bootstrap.php';
require $root . '/src/Scoreboard.php';
require $root . '/src/AdminService.php';

function assert_feature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$pdo = app_pdo();
foreach (['settings', 'admins', 'query_fields', 'candidate_field_values', 'access_logs'] as $table) {
    assert_feature(
        (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '$table'")->fetchColumn() === 1,
        "missing $table table"
    );
}

assert_feature(AdminService::authenticate('admin', 'score-admin') !== null, 'default admin login failed');
AdminService::create('operator', 'operator-pass-2026', false);
assert_feature(AdminService::authenticate('operator', 'operator-pass-2026') !== null, 'new admin login failed');

Scoreboard::saveQueryField(0, 'id_suffix', '证件后四位', true, true);
$field = Scoreboard::queryFields(true)[0] ?? null;
assert_feature($field !== null && $field['field_key'] === 'id_suffix', 'custom field missing');

$candidate = Scoreboard::find(1);
Scoreboard::save(
    1,
    (string) $candidate['ticket_no'],
    (string) $candidate['name'],
    $candidate['scores'],
    ['id_suffix' => '1234']
);

assert_feature(
    Scoreboard::lookup('26010001', '陈予安', ['id_suffix' => '1234']) !== null,
    'custom field lookup failed'
);
assert_feature(
    Scoreboard::lookup('26010001', '陈予安', ['id_suffix' => '9999']) === null,
    'custom field lookup matched incorrect value'
);

save_settings(['site_title' => '测试站点']);
assert_feature(setting('site_title') === '测试站点', 'site settings were not persisted');

refresh_captcha();
$captcha = captcha_code();
assert_feature(
    (bool) preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{5}$/', $captcha),
    'captcha is not a five character random code'
);
assert_feature(captcha_ok($captcha), 'captcha validation failed');
assert_feature(!captcha_ok('not-a-code'), 'invalid captcha was accepted');

refresh_captcha();
ob_start();
require $root . '/public/captcha.php';
$captchaSvg = (string) ob_get_clean();
assert_feature(str_starts_with($captchaSvg, "\x89PNG\r\n\x1a\n"), 'captcha is not a PNG image');
assert_feature(!str_contains($captchaSvg, '<text'), 'captcha exposes text nodes');

log_access('success', 'test entry', '26010001', '陈予安');
assert_feature(count(AdminService::accessLogs()) === 1, 'access log missing');

assert_feature(rate_limit_allows('test_limit', 2, 600), 'first rate limit request rejected');
assert_feature(rate_limit_allows('test_limit', 2, 600), 'second rate limit request rejected');
assert_feature(!rate_limit_allows('test_limit', 2, 600), 'rate limit was bypassed');

echo "ok\n";
@unlink($db);
