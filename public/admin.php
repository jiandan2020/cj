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
<div class="page">
    <main class="stage">
        <section class="card">
            <header class="card-head">
                <p>成绩管理</p>
            </header>
            <div class="card-body">
                <?php if (!$loggedIn): ?>
                    <form class="form" method="post">
                        <input type="hidden" name="action" value="login">
                        <div class="field">
                            <label for="password">管理口令：</label>
                            <input id="password" name="password" type="password" required autocomplete="current-password">
                        </div>
                        <div class="actions">
                            <button class="submit" type="submit">进入</button>
                        </div>
                    </form>
                <?php elseif ($creating || $editing): ?>
                    <?php
                    $formId = $editing['id'] ?? 0;
                    $formTicket = $editing['ticket_no'] ?? '';
                    $formName = $editing['name'] ?? '';
                    $formScores = $editing['scores'] ?? [['subject' => '', 'score' => '']];
                    if ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                        $formId = (int) ($_POST['id'] ?? 0);
                        $formTicket = trim((string) ($_POST['ticket'] ?? ''));
                        $formName = trim((string) ($_POST['name'] ?? ''));
                        $formScores = [];
                        $postedSubjects = is_array($_POST['subject'] ?? null) ? $_POST['subject'] : [];
                        $postedScores = is_array($_POST['score'] ?? null) ? $_POST['score'] : [];
                        $count = max(count($postedSubjects), count($postedScores));
                        for ($i = 0; $i < $count; $i++) {
                            $formScores[] = [
                                'subject' => (string) ($postedSubjects[$i] ?? ''),
                                'score' => (string) ($postedScores[$i] ?? ''),
                            ];
                        }
                    }
                    ?>
                    <form class="form editor" method="post">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="csrf" value="<?= e($token) ?>">
                        <input type="hidden" name="id" value="<?= (int) $formId ?>">
                        <div class="field">
                            <label for="ticket">准考证号：</label>
                            <input id="ticket" name="ticket" type="text" maxlength="32" required value="<?= e((string) $formTicket) ?>">
                        </div>
                        <div class="field">
                            <label for="name">姓名：</label>
                            <input id="name" name="name" type="text" maxlength="30" required value="<?= e((string) $formName) ?>">
                        </div>
                        <div class="field">
                            <div class="subject-label">科目分数：</div>
                        </div>
                        <div id="subject-list">
                            <?php foreach ($formScores as $row): ?>
                                <div class="subject-row">
                                    <input name="subject[]" type="text" maxlength="20" placeholder="科目" value="<?= e((string) $row['subject']) ?>">
                                    <input name="score[]" type="text" inputmode="decimal" placeholder="分数" value="<?= e(is_numeric((string) $row['score']) ? format_score((float) $row['score']) : (string) $row['score']) ?>">
                                    <button type="button" class="remove-row" aria-label="删除科目">×</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="subject-row">
                            <button type="button" id="add-row">添加科目</button>
                        </div>
                        <div class="actions">
                            <button class="submit" type="submit">保存</button>
                        </div>
                    </form>
                    <div class="admin-tools">
                        <a href="admin.php">返回列表</a>
                    </div>
                <?php else: ?>
                    <div class="admin-tools">
                        <a href="admin.php?new=1">新增考生</a>
                        <a href="admin.php?logout=1">退出</a>
                    </div>
                    <?php if ($notice !== ''): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
                    <div class="admin-table">
                        <table class="scores">
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
                                            <button class="text-button" type="submit">删除</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <form class="import-box" method="post">
                        <input type="hidden" name="action" value="import">
                        <input type="hidden" name="csrf" value="<?= e($token) ?>">
                        <label for="csv">批量导入，每行：准考证号,姓名,科目,分数</label>
                        <textarea id="csv" name="csv" placeholder="26010009,赵启明,语文,110&#10;26010009,赵启明,数学,125"></textarea>
                        <button class="submit" type="submit">导入</button>
                    </form>
                <?php endif; ?>
            </div>
            <footer class="card-foot">同一准考证号与姓名唯一对应。保存后，前台查询会立即按最新分数重算科目排名和总分排名。</footer>
        </section>
    </main>
    <footer class="footbar">
        <p>成绩维护入口。前台查询地址与本页分开。</p>
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
<?php if ($creating || $editing): ?>
    <script>
        document.getElementById('add-row').addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'subject-row';
            row.innerHTML = '<input name="subject[]" type="text" maxlength="20" placeholder="科目">'
                + '<input name="score[]" type="text" inputmode="decimal" placeholder="分数">'
                + '<button type="button" class="remove-row" aria-label="删除科目">×</button>';
            document.getElementById('subject-list').appendChild(row);
        });
        document.getElementById('subject-list').addEventListener('click', function (event) {
            if (event.target.classList.contains('remove-row')) {
                event.target.parentElement.remove();
            }
        });
    </script>
<?php endif; ?>
</body>
</html>
