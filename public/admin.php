<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Scoreboard.php';
require __DIR__ . '/../src/AdminService.php';

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
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!rate_limit_allows('admin_login', max(1, min(20, (int) setting('admin_login_limit', '5'))), 600)) {
            $error = '登录尝试过于频繁，请 10 分钟后再试';
            log_access('admin_rate_limited', $error);
        } elseif (!captcha_ok((string) ($_POST['captcha'] ?? ''))) {
            $error = '验证码不正确，请重新输入';
            log_access('admin_captcha_failed', $error);
        } elseif ($admin = AdminService::authenticate($username, $password)) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            log_access('admin_login', '管理员登录：' . $admin['username']);
            redirect_to('admin.php');
        }
        if ($error === '') {
            $error = '用户名或口令不正确';
            log_access('admin_login_failed', $error);
        }
    } elseif (current_admin() === null) {
        $error = '请先登录';
    } elseif (!csrf_ok()) {
        $error = '页面已过期，请重新提交';
    } else {
        try {
            if ($action === 'delete') {
                Scoreboard::delete((int) ($_POST['id'] ?? 0));
                $notice = '已删除该考生';
                log_access('candidate_deleted', $notice);
            } elseif ($action === 'import') {
                $count = Scoreboard::importCsv((string) ($_POST['csv'] ?? ''));
                $notice = '已导入 ' . $count . ' 名考生';
                log_access('scores_imported', $notice);
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
                $fieldValues = is_array($_POST['field'] ?? null) ? $_POST['field'] : [];
                Scoreboard::save((int) ($_POST['id'] ?? 0), $ticket, $name, $subjects, $fieldValues);
                log_access('candidate_saved', '成绩已保存：' . $ticket, $ticket, $name);
                redirect_to('admin.php?saved=1');
            } elseif ($action === 'settings') {
                $siteTitle = trim((string) ($_POST['site_title'] ?? ''));
                $siteFooter = trim((string) ($_POST['site_footer'] ?? ''));
                $queryHint = trim((string) ($_POST['query_hint'] ?? ''));
                $captchaLength = (int) ($_POST['captcha_length'] ?? 5);
                $captchaCharset = (string) ($_POST['captcha_charset'] ?? 'alnum');
                $captchaExpiry = (int) ($_POST['captcha_expiry_seconds'] ?? 300);
                $captchaNoise = (int) ($_POST['captcha_noise_level'] ?? 2);
                $publicLimit = (int) ($_POST['public_query_limit'] ?? 15);
                $loginLimit = (int) ($_POST['admin_login_limit'] ?? 5);
                if ($siteTitle === '' || mb_strlen($siteTitle) > 40
                    || $siteFooter === '' || mb_strlen($siteFooter) > 150
                    || $queryHint === '' || mb_strlen($queryHint) > 200
                    || $captchaLength < 4 || $captchaLength > 6
                    || !in_array($captchaCharset, ['alnum', 'digits'], true)
                    || $captchaExpiry < 60 || $captchaExpiry > 900
                    || $captchaNoise < 1 || $captchaNoise > 4
                    || $publicLimit < 1 || $publicLimit > 100
                    || $loginLimit < 1 || $loginLimit > 20) {
                    throw new InvalidArgumentException('请填写站点信息，并确保长度在允许范围内');
                }
                save_settings([
                    'site_title' => $siteTitle,
                    'site_footer' => $siteFooter,
                    'query_hint' => $queryHint,
                    'captcha_length' => (string) $captchaLength,
                    'captcha_charset' => $captchaCharset,
                    'captcha_expiry_seconds' => (string) $captchaExpiry,
                    'captcha_noise_level' => (string) $captchaNoise,
                    'public_query_limit' => (string) $publicLimit,
                    'admin_login_limit' => (string) $loginLimit,
                ]);
                $notice = '站点信息已保存';
                log_access('settings_saved', $notice);
            } elseif ($action === 'field_save') {
                Scoreboard::saveQueryField(
                    (int) ($_POST['id'] ?? 0),
                    trim((string) ($_POST['field_key'] ?? '')),
                    trim((string) ($_POST['label'] ?? '')),
                    isset($_POST['is_required']),
                    isset($_POST['is_enabled'])
                );
                $notice = '查询字段已保存';
                log_access('query_field_saved', $notice);
            } elseif ($action === 'field_delete') {
                Scoreboard::deleteQueryField((int) ($_POST['id'] ?? 0));
                $notice = '查询字段已删除';
                log_access('query_field_deleted', $notice);
            } elseif ($action === 'admin_create') {
                $current = require_admin();
                if ((int) $current['is_super'] !== 1) {
                    throw new InvalidArgumentException('仅超级管理员可以新增管理员');
                }
                AdminService::create(
                    trim((string) ($_POST['username'] ?? '')),
                    (string) ($_POST['new_password'] ?? ''),
                    isset($_POST['is_super'])
                );
                $notice = '管理员已新增';
                log_access('admin_created', $notice);
            } elseif ($action === 'admin_password') {
                $current = require_admin();
                $targetId = (int) ($_POST['id'] ?? 0);
                if ((int) $current['is_super'] !== 1 && $targetId !== (int) $current['id']) {
                    throw new InvalidArgumentException('只能修改自己的口令');
                }
                AdminService::changePassword($targetId, (string) ($_POST['new_password'] ?? ''));
                $notice = '管理员口令已更新';
                log_access('admin_password_changed', $notice);
            } elseif ($action === 'admin_delete') {
                $current = require_admin();
                if ((int) $current['is_super'] !== 1) {
                    throw new InvalidArgumentException('仅超级管理员可以删除管理员');
                }
                AdminService::delete((int) ($_POST['id'] ?? 0), (int) $current['id']);
                $notice = '管理员已删除';
                log_access('admin_deleted', $notice);
            }
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $notice = '成绩已保存';
}

$currentAdmin = current_admin();
$loggedIn = $currentAdmin !== null;
$section = (string) ($_GET['section'] ?? 'scores');
$allowedSections = ['scores', 'settings', 'fields', 'admins', 'logs'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'scores';
}
$editing = null;
if ($loggedIn && $section === 'scores' && isset($_GET['edit'])) {
    $editing = Scoreboard::find((int) $_GET['edit']);
    if ($editing === null) {
        $error = '未找到该考生';
    }
}
$creating = $loggedIn && isset($_GET['new']);
$token = csrf_token();
$customFields = Scoreboard::queryFields(false);
$captchaLength = captcha_length();
$captchaWidth = captcha_image_width();
$captchaInputWidth = max(80, 305 - $captchaWidth);
$captchaPattern = setting('captcha_charset', 'alnum') === 'digits'
    ? '[23456789]{' . $captchaLength . '}'
    : '[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{' . $captchaLength . '}';

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
                        <div class="ckleft">用户名：</div>
                        <div class="ckright"><input id="username" name="username" class="cipnut" type="text" required autocomplete="username" value="admin"></div>
                    </div>
                    <div class="ckbd">
                        <div class="ckleft">管理口令：</div>
                        <div class="ckright"><input id="password" name="password" class="cipnut" type="password" required autocomplete="current-password"></div>
                    </div>
                    <div class="ckbd">
                        <div class="ckleft">验证码：</div>
                        <div class="ckright">
                            <input id="captcha" name="captcha" class="code" type="text" maxlength="<?= $captchaLength ?>" pattern="<?= e($captchaPattern) ?>" required placeholder="请输入图形验证码" autocomplete="off" style="width:<?= $captchaInputWidth ?>px;text-transform:uppercase">
                            <img class="img-verifycode" id="captcha-image" src="captcha.php" alt="点击刷新验证码" title="点击刷新验证码" style="width:<?= $captchaWidth ?>px">
                        </div>
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
                    <?php foreach ($customFields as $field): ?>
                        <?php $fieldValue = (string) (($editing['field_values'][$field['field_key']] ?? '') ?: ($_POST['field'][$field['field_key']] ?? '')); ?>
                        <div class="ckbd">
                            <div class="ckleft"><?= e($field['label']) ?>：</div>
                            <div class="ckright"><input name="field[<?= e($field['field_key']) ?>]" class="cipnut" type="text" maxlength="100"<?= (int) $field['is_required'] === 1 ? ' required' : '' ?> value="<?= e($fieldValue) ?>"></div>
                        </div>
                    <?php endforeach; ?>
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
                    <a href="admin.php">成绩数据</a>
                    <a href="admin.php?section=settings">站点信息</a>
                    <a href="admin.php?section=fields">查询字段</a>
                    <?php if ((int) $currentAdmin['is_super'] === 1): ?><a href="admin.php?section=admins">管理员</a><?php endif; ?>
                    <a href="admin.php?section=logs">访问日志</a>
                    <a class="right" href="admin.php?logout=1">退出</a>
                </div>
                <?php if ($section === 'scores'): ?>
                <div class="admin-bar clear">
                    <a href="admin.php?new=1">新增考生</a>
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
                <?php elseif ($section === 'settings'): ?>
                    <form class="form" method="post">
                        <input type="hidden" name="action" value="settings">
                        <input type="hidden" name="csrf" value="<?= e($token) ?>">
                        <?php if ($notice !== ''): ?><div class="result-wz"><?= e($notice) ?></div><?php endif; ?>
                        <div class="ckbd">
                            <div class="ckleft">站点标题：</div>
                            <div class="ckright"><input class="cipnut" name="site_title" maxlength="40" required value="<?= e(setting('site_title')) ?>"></div>
                        </div>
                        <div class="ckbd tall">
                            <div class="ckleft">页脚文案：</div>
                            <div class="ckright"><textarea class="cipnut" name="site_footer" maxlength="150" required><?= e(setting('site_footer')) ?></textarea></div>
                        </div>
                        <div class="ckbd tall">
                            <div class="ckleft">查询提示：</div>
                            <div class="ckright"><textarea class="cipnut" name="query_hint" maxlength="200" required><?= e(setting('query_hint')) ?></textarea></div>
                        </div>
                        <div class="ckbd">
                            <div class="ckleft">验证码字符数：</div>
                            <div class="ckright"><input class="cipnut" name="captcha_length" type="number" min="4" max="6" required value="<?= e(setting('captcha_length', '5')) ?>"></div>
                        </div>
                        <div class="ckbd">
                            <div class="ckleft">验证码字符集：</div>
                            <div class="ckright">
                                <select class="cselect" name="captcha_charset">
                                    <option value="alnum"<?= setting('captcha_charset', 'alnum') === 'alnum' ? ' selected' : '' ?>>字母与数字</option>
                                    <option value="digits"<?= setting('captcha_charset') === 'digits' ? ' selected' : '' ?>>数字</option>
                                </select>
                            </div>
                        </div>
                        <div class="ckbd">
                            <div class="ckleft">验证码有效期：</div>
                            <div class="ckright"><input class="cipnut" name="captcha_expiry_seconds" type="number" min="60" max="900" required value="<?= e(setting('captcha_expiry_seconds', '300')) ?>" placeholder="60 到 900 秒"></div>
                        </div>
                        <div class="ckbd">
                            <div class="ckleft">图形干扰强度：</div>
                            <div class="ckright"><input class="cipnut" name="captcha_noise_level" type="number" min="1" max="4" required value="<?= e(setting('captcha_noise_level', '2')) ?>" placeholder="1 到 4"></div>
                        </div>
                        <div class="ckbd">
                            <div class="ckleft">查询限流：</div>
                            <div class="ckright"><input class="cipnut" name="public_query_limit" type="number" min="1" max="100" required value="<?= e(setting('public_query_limit', '15')) ?>" placeholder="每 10 分钟 / IP"></div>
                        </div>
                        <div class="ckbd">
                            <div class="ckleft">登录限流：</div>
                            <div class="ckright"><input class="cipnut" name="admin_login_limit" type="number" min="1" max="20" required value="<?= e(setting('admin_login_limit', '5')) ?>" placeholder="每 10 分钟 / IP"></div>
                        </div>
                        <div class="ckbd mt40"><input class="inquire" type="submit" value="保存站点信息"></div>
                    </form>
                <?php elseif ($section === 'fields'): ?>
                    <div class="searesult">
                        <?php if ($notice !== ''): ?><div class="result-wz"><?= e($notice) ?></div><?php endif; ?>
                        <table>
                            <thead><tr><th>字段键</th><th>显示名称</th><th>启用</th><th>必填</th><th>操作</th></tr></thead>
                            <tbody>
                            <?php foreach ($customFields as $field): ?>
                                <tr>
                                    <td><?= e($field['field_key']) ?></td><td><?= e($field['label']) ?></td>
                                    <td><?= (int) $field['is_enabled'] === 1 ? '是' : '否' ?></td>
                                    <td><?= (int) $field['is_required'] === 1 ? '是' : '否' ?></td>
                                    <td>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="action" value="field_delete"><input type="hidden" name="csrf" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= (int) $field['id'] ?>">
                                            <button class="linkish" type="submit" onclick="return confirm('删除该字段及已填数据？')">删除</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <form class="form" method="post">
                        <input type="hidden" name="action" value="field_save"><input type="hidden" name="csrf" value="<?= e($token) ?>">
                        <div class="ckbd"><div class="ckleft">字段键：</div><div class="ckright"><input class="cipnut" name="field_key" maxlength="31" required placeholder="例如 id_suffix"></div></div>
                        <div class="ckbd"><div class="ckleft">显示名称：</div><div class="ckright"><input class="cipnut" name="label" maxlength="20" required placeholder="例如 证件后四位"></div></div>
                        <div class="ckbd"><div class="ckleft">查询规则：</div><div class="ckright"><label><input type="checkbox" name="is_enabled" checked> 启用</label>　<label><input type="checkbox" name="is_required"> 必填</label></div></div>
                        <div class="ckbd mt40"><input class="inquire" type="submit" value="新增查询字段"></div>
                    </form>
                <?php elseif ($section === 'admins'): ?>
                    <div class="searesult">
                        <?php if ($notice !== ''): ?><div class="result-wz"><?= e($notice) ?></div><?php endif; ?>
                        <table><thead><tr><th>用户名</th><th>角色</th><th>创建时间</th><th>最后登录</th><th>操作</th></tr></thead><tbody>
                        <?php foreach (AdminService::all() as $admin): ?>
                            <tr><td><?= e($admin['username']) ?></td><td><?= (int) $admin['is_super'] === 1 ? '超级管理员' : '管理员' ?></td><td><?= e($admin['created_at']) ?></td><td><?= e($admin['last_login_at'] ?? '-') ?></td><td>
                                <form method="post" style="display:inline"><input type="hidden" name="action" value="admin_delete"><input type="hidden" name="csrf" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= (int) $admin['id'] ?>"><button class="linkish" type="submit" onclick="return confirm('删除该管理员？')">删除</button></form>
                            </td></tr>
                        <?php endforeach; ?></tbody></table>
                    </div>
                    <form class="form" method="post">
                        <input type="hidden" name="action" value="admin_create"><input type="hidden" name="csrf" value="<?= e($token) ?>">
                        <div class="ckbd"><div class="ckleft">管理员用户名：</div><div class="ckright"><input class="cipnut" name="username" required></div></div>
                        <div class="ckbd"><div class="ckleft">初始口令：</div><div class="ckright"><input class="cipnut" name="new_password" type="password" minlength="10" required></div></div>
                        <div class="ckbd"><div class="ckleft">权限：</div><div class="ckright"><label><input type="checkbox" name="is_super"> 超级管理员</label></div></div>
                        <div class="ckbd mt40"><input class="inquire" type="submit" value="新增管理员"></div>
                    </form>
                <?php elseif ($section === 'logs'): ?>
                    <div class="searesult">
                        <table><thead><tr><th>时间</th><th>IP</th><th>结果</th><th>查询信息</th><th>详情</th></tr></thead><tbody>
                        <?php foreach (AdminService::accessLogs() as $log): ?>
                            <tr><td><?= e($log['created_at']) ?></td><td><?= e($log['ip_address']) ?></td><td><?= e($log['outcome']) ?></td><td><?= e(trim(($log['ticket_no'] ?? '') . ' ' . ($log['candidate_name'] ?? ''))) ?></td><td><?= e($log['detail'] ?? '') ?></td></tr>
                        <?php endforeach; ?></tbody></table>
                    </div>
                <?php endif; ?>
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
<script>
    var captchaImage = document.getElementById('captcha-image');
    if (captchaImage) {
        captchaImage.addEventListener('click', function () {
            this.src = 'captcha.php?t=' + Date.now();
            document.getElementById('captcha').value = '';
        });
    }
</script>
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
