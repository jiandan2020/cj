<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Scoreboard.php';

start_app_session();

$notice = '';
$error = '';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    redirect_to('admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'login') {
        $password = (string) ($_POST['password'] ?? '');
        if (admin_password_ok($password)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            redirect_to('admin.php');
        }
        $error = '口令不正确';
    } elseif (empty($_SESSION['admin'])) {
        $error = '请先登录';
    } elseif (!csrf_ok()) {
        $error = '页面已过期，请重新提交';
    } else {
        try {
            if ($action === 'delete') {
                Scoreboard::delete((int) ($_POST['id'] ?? 0));
                $notice = '已删除该考生';
            } elseif ($action === 'import') {
                $count = Scoreboard::importCsv((string) ($_POST['csv'] ?? ''));
                $notice = '已导入 ' . $count . ' 名考生';
            } elseif ($action === 'save') {
                $ticket = trim((string) ($_POST['ticket'] ?? ''));
                $name = trim((string) ($_POST['name'] ?? ''));
                Scoreboard::assertTicket($ticket);
                Scoreboard::assertName($name);
                $names = $_POST['subject'] ?? [];
                $scores = $_POST['score'] ?? [];
                if (!is_array($names) || !is_array($scores)) {
                    throw new InvalidArgumentException('科目数据无效');
                }
                $subjects = [];
                $seen = [];
                $count = max(count($names), count($scores));
                for ($i = 0; $i < $count; $i++) {
                    $subject = trim((string) ($names[$i] ?? ''));
                    $scoreText = trim((string) ($scores[$i] ?? ''));
                    if ($subject === '' && $scoreText === '') {
                        continue;
                    }
                    Scoreboard::assertSubject($subject);
                    if (isset($seen[$subject])) {
                        throw new InvalidArgumentException('科目「' . $subject . '」重复');
                    }
                    $seen[$subject] = true;
                    $subjects[] = ['subject' => $subject, 'score' => Scoreboard::assertScore($scoreText)];
                }
                if ($subjects === []) {
                    throw new InvalidArgumentException('请至少填写一个科目和分数');
                }
                Scoreboard::save((int) ($_POST['id'] ?? 0), $ticket, $name, $subjects);
                redirect_to('admin.php?saved=1');
            }
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $notice = '成绩已保存';
}

$loggedIn = !empty($_SESSION['admin']);
$editing = null;
if ($loggedIn && isset($_GET['edit'])) {
    $editing = Scoreboard::find((int) $_GET['edit']);
    if ($editing === null) {
        $error = '未找到该考生';
    }
}
$creating = $loggedIn && isset($_GET['new']);
$token = csrf_token();

$formId = $editing['id'] ?? 0;
$formTicket = $editing['ticket_no'] ?? '';
$formName = $editing['name'] ?? '';
$formScores = $editing['scores'] ?? [['subject' => '', 'score' => '']];
if (($creating || $editing) && $error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $formId = (int) ($_POST['id'] ?? 0);
    $formTicket = trim((string) ($_POST['ticket'] ?? ''));
    $formName = trim((string) ($_POST['name'] ?? ''));
    $formScores = [];
    $postedSubjects = is_array($_POST['subject'] ?? null) ? $_POST['subject'] : [];
    $postedScores = is_array($_POST['score'] ?? null) ? $_POST['score'] : [];
    $count = max(count($postedSubjects), count($postedScores), 1);
    for ($i = 0; $i < $count; $i++) {
        $formScores[] = [
            'subject' => (string) ($postedSubjects[$i] ?? ''),
            'score' => (string) ($postedScores[$i] ?? ''),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>成绩管理</title>
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
                <p>成绩管理</p>
            </div>
            <?php if (!$loggedIn): ?>
                <form class="form" method="post">
                    <input type="hidden" name="action" value="login">
                    <div class="ckbd">
                        <div class="ckleft">管理口令：</div>
                        <div class="ckright"><input id="password" name="password" class="cipnut" type="password" required autocomplete="current-password"></div>
                    </div>
                    <div class="ckbd mt40">
                        <input class="inquire" type="submit" value="进入">
                    </div>
                </form>
            <?php elseif ($creating || $editing): ?>
                <form class="form" method="post">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="csrf" value="<?= e($token) ?>">
                    <input type="hidden" name="id" value="<?= (int) $formId ?>">
                    <div class="ckbd">
                        <div class="ckleft">准考证号：</div>
                        <div class="ckright"><input id="ticket" name="ticket" class="cipnut" type="text" maxlength="32" required value="<?= e((string) $formTicket) ?>"></div>
                    </div>
                    <div class="ckbd">
                        <div class="ckleft">姓名：</div>
                        <div class="ckright"><input id="name" name="name" class="cipnut" type="text" maxlength="30" required value="<?= e((string) $formName) ?>"></div>
                    </div>
                    <div id="subject-list">
                        <?php foreach ($formScores as $row): ?>
                            <div class="subject-block">
                                <div class="ckbd">
                                    <div class="ckleft">科目：</div>
                                    <div class="ckright"><input name="subject[]" class="cipnut" type="text" maxlength="20" placeholder="科目" value="<?= e((string) $row['subject']) ?>"></div>
                                </div>
                                <div class="ckbd">
                                    <div class="ckleft">分数：</div>
                                    <div class="ckright"><input name="score[]" class="cipnut" type="text" inputmode="decimal" placeholder="分数" value="<?= e(is_numeric((string) $row['score']) ? format_score((float) $row['score']) : (string) $row['score']) ?>"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="ckbd">
                        <a href="#" id="add-row" class="add-subject">添加科目</a>
                        <a href="#" id="remove-row" class="add-subject">删除最后一科</a>
                    </div>
                    <div class="ckbd mt40">
                        <input class="inquire" type="submit" value="保存">
                    </div>
                </form>
                <div class="admin-bar"><a href="admin.php">返回列表</a></div>
            <?php else: ?>
                <div class="admin-bar clear">
                    <a href="admin.php?new=1">新增考生</a>
                    <a class="right" href="admin.php?logout=1">退出</a>
                </div>
                <div class="searesult">
                    <?php if ($notice !== ''): ?><div class="result-wz"><?= e($notice) ?></div><?php endif; ?>
                    <table>
                        <thead>
                        <tr>
                            <th>准考证号</th>
                            <th>姓名</th>
                            <th>科目数</th>
                            <th>总分</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (Scoreboard::listCandidates() as $candidate): ?>
                            <tr>
                                <td><?= e((string) $candidate['ticket_no']) ?></td>
                                <td><?= e((string) $candidate['name']) ?></td>
                                <td><?= (int) $candidate['subject_count'] ?></td>
                                <td><?= e(format_score((float) $candidate['total'])) ?></td>
                                <td>
                                    <a href="admin.php?edit=<?= (int) $candidate['id'] ?>">编辑</a>
                                    <form method="post" style="display:inline" onsubmit="return confirm('删除这名考生及其成绩？');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf" value="<?= e($token) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $candidate['id'] ?>">
                                        <button class="linkish" type="submit">删除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <form class="form import-form" method="post">
                        <input type="hidden" name="action" value="import">
                        <input type="hidden" name="csrf" value="<?= e($token) ?>">
                        <div class="ckbd tall">
                            <div class="ckleft">批量导入：</div>
                            <div class="ckright">
                                <textarea id="csv" name="csv" class="cipnut" placeholder="准考证号,姓名,科目,分数"></textarea>
                            </div>
                        </div>
                        <div class="ckbd mt40">
                            <input class="inquire" type="submit" value="导入">
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            <div class="ckfoot">同一准考证号与姓名唯一对应。保存后，前台查询会按最新分数重算科目排名和总分排名。</div>
        </div>
        <div style="height:50px;"></div>
    </div>
    <div class="footer_bottom">
        <div class="footer_bottom_box">
            <span>成绩维护入口。前台查询地址与本页分开。</span>
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
<?php if ($creating || $editing): ?>
    <template id="subject-template">
        <div class="subject-block">
            <div class="ckbd">
                <div class="ckleft">科目：</div>
                <div class="ckright"><input name="subject[]" class="cipnut" type="text" maxlength="20" placeholder="科目"></div>
            </div>
            <div class="ckbd">
                <div class="ckleft">分数：</div>
                <div class="ckright"><input name="score[]" class="cipnut" type="text" inputmode="decimal" placeholder="分数"></div>
            </div>
        </div>
    </template>
    <script>
        document.getElementById('add-row').addEventListener('click', function (event) {
            event.preventDefault();
            var block = document.getElementById('subject-template').content.cloneNode(true);
            document.getElementById('subject-list').appendChild(block);
        });
        document.getElementById('remove-row').addEventListener('click', function (event) {
            event.preventDefault();
            var blocks = document.querySelectorAll('#subject-list .subject-block');
            if (blocks.length > 1) {
                blocks[blocks.length - 1].remove();
            }
        });
    </script>
<?php endif; ?>
</body>
</html>
