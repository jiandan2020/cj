<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Scoreboard.php';

$error = '';
$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket = trim((string) ($_POST['ticket'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    try {
        Scoreboard::assertTicket($ticket);
        Scoreboard::assertName($name);
        $report = Scoreboard::lookup($ticket, $name);
        if ($report === null) {
            $error = '未查询到对应成绩，请核对准考证号与姓名';
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }
}

$title = '成绩查询';
$ticketValue = $report['ticket_no'] ?? trim((string) ($_POST['ticket'] ?? ''));
$nameValue = $report['name'] ?? trim((string) ($_POST['name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="page">
    <main class="stage">
        <section class="card">
            <header class="card-head">
                <p><?= e($title) ?></p>
            </header>
            <div class="card-body">
                <?php if ($report === null): ?>
                    <form class="form" method="post" action="">
                        <div class="field">
                            <label for="ticket">准考证号：</label>
                            <input id="ticket" name="ticket" type="text" maxlength="32" required value="<?= e($ticketValue) ?>" placeholder="请输入准考证号" autocomplete="off">
                        </div>
                        <div class="field">
                            <label for="name">姓名：</label>
                            <input id="name" name="name" type="text" maxlength="30" required value="<?= e($nameValue) ?>" placeholder="请输入姓名" autocomplete="name">
                        </div>
                        <div class="actions">
                            <button class="submit" type="submit">查询</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="summary">
                        <div>准考证号：<b><?= e($report['ticket_no']) ?></b></div>
                        <div>姓名：<b><?= e($report['name']) ?></b></div>
                        <div>总分：<b><?= e(format_score((float) $report['total'])) ?></b></div>
                        <div>总分排名：<b>第 <?= (int) $report['total_rank'] ?> 名 / 共 <?= (int) $report['total_count'] ?> 人</b></div>
                    </div>
                    <div class="table-wrap">
                        <table class="scores">
                            <thead>
                            <tr>
                                <th>科目</th>
                                <th>分数</th>
                                <th>科目排名</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($report['subjects'] as $subject): ?>
                                <tr>
                                    <td><?= e($subject['subject']) ?></td>
                                    <td><?= e(format_score((float) $subject['score'])) ?></td>
                                    <td>第 <?= (int) $subject['rank'] ?> 名 / 共 <?= (int) $subject['count'] ?> 人</td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total">
                                <td>总分</td>
                                <td><?= e(format_score((float) $report['total'])) ?></td>
                                <td>第 <?= (int) $report['total_rank'] ?> 名 / 共 <?= (int) $report['total_count'] ?> 人</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="again">
                        <a href="./">返回查询</a>
                    </div>
                <?php endif; ?>
            </div>
            <footer class="card-foot">分数相同则并列，下一名次按人数顺延。总分按已录入科目合计，排名只在当前成绩库内计算。</footer>
        </section>
    </main>
    <footer class="footbar">
        <p>独立成绩查询。请使用准考证号和姓名查询本人成绩。</p>
    </footer>
</div>
<?php if ($error !== ''): ?>
    <div class="mask"></div>
    <div class="dialog" role="alertdialog" aria-modal="true">
        <p><?= e($error) ?></p>
        <button type="button" id="close-dialog">确定</button>
    </div>
    <script>
        document.getElementById('close-dialog').addEventListener('click', function () {
            document.querySelector('.mask').remove();
            document.querySelector('.dialog').remove();
        });
    </script>
<?php endif; ?>
</body>
</html>
