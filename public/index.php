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
<?php if ($error !== ''): ?>
    <div class="mask" style="display:block"></div>
    <div class="wintips" style="display:block" role="alertdialog" aria-modal="true">
        <div class="tipswz"><?= e($error) ?></div>
        <div class="gbtips" id="close-dialog">确定</div>
    </div>
<?php endif; ?>
<div class="body">
    <div class="header">
        <div style="height:28px"></div>
    </div>
    <div class="c-center">
        <div style="height:35px;"></div>
        <div class="cen-form">
            <div class="ckhead">
                <p><?= e($title) ?></p>
            </div>
            <?php if ($report === null): ?>
                <form class="form" method="post" action="">
                    <div class="ckbd">
                        <div class="ckleft">准考证号：</div>
                        <div class="ckright"><input id="ticket" name="ticket" class="cipnut" type="text" maxlength="32" required value="<?= e($ticketValue) ?>" placeholder="请输入准考证号" autocomplete="off"></div>
                    </div>
                    <div class="ckbd">
                        <div class="ckleft">姓名：</div>
                        <div class="ckright"><input id="name" name="name" class="cipnut" type="text" maxlength="30" required value="<?= e($nameValue) ?>" placeholder="请输入姓名" autocomplete="name"></div>
                    </div>
                    <div class="ckbd mt40">
                        <input class="inquire" type="submit" value="查询">
                    </div>
                </form>
            <?php else: ?>
                <div class="searesult">
                    <div class="ksxin clear">
                        <div>准考证号：<span><?= e($report['ticket_no']) ?></span></div>
                        <div>姓名：<span class="kname"><?= e($report['name']) ?></span></div>
                        <div>总分：<span><?= e(format_score((float) $report['total'])) ?></span></div>
                        <div class="k3ksh">总分排名：<span>第 <?= (int) $report['total_rank'] ?> 名 / 共 <?= (int) $report['total_count'] ?> 人</span></div>
                    </div>
                    <table>
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
                        <tr>
                            <td>总分</td>
                            <td><?= e(format_score((float) $report['total'])) ?></td>
                            <td>第 <?= (int) $report['total_rank'] ?> 名 / 共 <?= (int) $report['total_count'] ?> 人</td>
                        </tr>
                        </tbody>
                    </table>
                    <div class="cxnr show clear">
                        <a class="agcx" href="./">返回查询</a>
                    </div>
                </div>
            <?php endif; ?>
            <div class="ckfoot">分数相同则并列，下一名次按人数顺延。总分按已录入科目合计，排名只在当前成绩库内计算。</div>
        </div>
        <div style="height:50px;"></div>
    </div>
    <div class="footer_bottom">
        <div class="footer_bottom_box">
            <span>独立成绩查询。请使用准考证号和姓名查询本人成绩。</span>
        </div>
    </div>
</div>
<?php if ($error !== ''): ?>
    <script>
        document.getElementById('close-dialog').addEventListener('click', function () {
            document.querySelector('.mask').remove();
            document.querySelector('.wintips').remove();
        });
    </script>
<?php endif; ?>
</body>
</html>
