<?php

declare(strict_types=1);

final class AdminService
{
    public static function authenticate(string $username, string $password): ?array
    {
        $stmt = app_pdo()->prepare(
            'SELECT id, username, password_hash, is_super FROM admins WHERE username = ?'
        );
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($password, (string) $admin['password_hash'])) {
            return null;
        }

        app_pdo()->prepare('UPDATE admins SET last_login_at = datetime(\'now\', \'localtime\') WHERE id = ?')
            ->execute([(int) $admin['id']]);
        unset($admin['password_hash']);
        return $admin;
    }

    public static function all(): array
    {
        return app_pdo()->query(
            'SELECT id, username, is_super, created_at, last_login_at FROM admins ORDER BY is_super DESC, username'
        )->fetchAll();
    }

    public static function create(string $username, string $password, bool $isSuper): void
    {
        self::assertUsername($username);
        self::assertPassword($password);
        try {
            app_pdo()->prepare(
                'INSERT INTO admins (username, password_hash, is_super) VALUES (?, ?, ?)'
            )->execute([$username, password_hash($password, PASSWORD_DEFAULT), (int) $isSuper]);
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'UNIQUE')) {
                throw new InvalidArgumentException('管理员用户名已存在');
            }
            throw $exception;
        }
    }

    public static function changePassword(int $id, string $password): void
    {
        self::assertPassword($password);
        app_pdo()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public static function delete(int $id, int $currentId): void
    {
        if ($id === $currentId) {
            throw new InvalidArgumentException('不能删除当前登录的管理员');
        }
        $stmt = app_pdo()->prepare('SELECT is_super FROM admins WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            throw new InvalidArgumentException('管理员不存在');
        }
        if ((int) $target['is_super'] === 1
            && (int) app_pdo()->query('SELECT COUNT(*) FROM admins WHERE is_super = 1')->fetchColumn() <= 1) {
            throw new InvalidArgumentException('至少保留一个超级管理员');
        }
        app_pdo()->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
    }

    public static function accessLogs(int $limit = 100): array
    {
        $stmt = app_pdo()->prepare(
            'SELECT created_at, ip_address, method, path, ticket_no, candidate_name, outcome, detail
             FROM access_logs ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private static function assertUsername(string $username): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            throw new InvalidArgumentException('用户名需为 3 到 32 位字母、数字、点、下划线或连字符');
        }
    }

    private static function assertPassword(string $password): void
    {
        if (mb_strlen($password) < 10) {
            throw new InvalidArgumentException('管理员口令至少需要 10 个字符');
        }
    }
}
