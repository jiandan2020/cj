<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$db = sys_get_temp_dir() . '/score-query-test-' . getmypid() . '.sqlite';
@unlink($db);
putenv('SCORE_DB=' . $db);

require $root . '/src/bootstrap.php';
require $root . '/src/Scoreboard.php';

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $label . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$report = Scoreboard::lookup('26010001', '陈予安');
if ($report === null) {
    fwrite(STDERR, "seed lookup failed\n");
    exit(1);
}

assert_same(480.0, $report['total'], 'total');
assert_same(1, $report['total_rank'], 'total rank');
assert_same(8, $report['total_count'], 'total count');

$bySubject = [];
foreach ($report['subjects'] as $subject) {
    $bySubject[$subject['subject']] = $subject;
}
assert_same(2, $bySubject['语文']['rank'], 'chinese tie rank');
assert_same(2, $bySubject['数学']['rank'], 'math tie rank');
assert_same(3, $bySubject['英语']['rank'], 'english rank');
assert_same(2, $bySubject['物理']['rank'], 'physics rank');

$tied = Scoreboard::lookup('26010004', '许清和');
$tiedChinese = null;
foreach ($tied['subjects'] as $subject) {
    if ($subject['subject'] === '语文') {
        $tiedChinese = $subject;
    }
}
assert_same(2, $tiedChinese['rank'], 'same chinese score shares rank');
assert_same(424.0, $tied['total'], 'xu total');
assert_same(7, $tied['total_rank'], 'xu total rank');

if (Scoreboard::lookup('26010001', '林知夏') !== null) {
    fwrite(STDERR, "name mismatch should not match\n");
    exit(1);
}

Scoreboard::save(0, '26019999', '测试考生', [
    ['subject' => '语文', 'score' => 150],
    ['subject' => '数学', 'score' => 150],
]);
$fresh = Scoreboard::lookup('26019999', '测试考生');
assert_same(1, $fresh['subjects'][0]['rank'], 'new top chinese rank');
assert_same(300.0, $fresh['total'], 'new total');
assert_same(9, $fresh['total_count'], 'cohort grew');

$imported = Scoreboard::importCsv("准考证号,姓名,科目,分数\n26018888,导入甲,语文,90\n26018888,导入甲,数学,90.5\n");
assert_same(1, $imported, 'import count');
$importedReport = Scoreboard::lookup('26018888', '导入甲');
assert_same(180.5, $importedReport['total'], 'imported total');

try {
    Scoreboard::assertScore('1000');
    fwrite(STDERR, "invalid score was accepted\n");
    exit(1);
} catch (InvalidArgumentException) {
}

echo "ok\n";
@unlink($db);
