<?php

declare(strict_types=1);

final class Scoreboard
{
    public static function lookup(string $ticket, string $name, array $fieldValues = []): ?array
    {
        $pdo = app_pdo();
        $conditions = ['c.ticket_no = ?', 'c.name = ?'];
        $params = [$ticket, $name];
        foreach (self::queryFields(true) as $field) {
            $value = trim((string) ($fieldValues[$field['field_key']] ?? ''));
            if ($value === '') {
                if ((int) $field['is_required'] === 1) {
                    return null;
                }
                continue;
            }
            $conditions[] = 'EXISTS (
                SELECT 1 FROM candidate_field_values cfv
                WHERE cfv.candidate_id = c.id AND cfv.field_id = ? AND cfv.value = ?
            )';
            $params[] = (int) $field['id'];
            $params[] = $value;
        }
        $stmt = $pdo->prepare(
            'SELECT c.id, c.ticket_no, c.name FROM candidates c WHERE ' . implode(' AND ', $conditions)
        );
        $stmt->execute($params);
        $candidate = $stmt->fetch();
        if (!$candidate) {
            return null;
        }
        return self::report((int) $candidate['id'], (string) $candidate['ticket_no'], (string) $candidate['name']);
    }

    public static function report(int $candidateId, string $ticket, string $name): array
    {
        $pdo = app_pdo();
        $subjects = $pdo->prepare(
            'SELECT s.subject, s.score,
                1 + (
                    SELECT COUNT(*) FROM scores higher
                    WHERE higher.subject = s.subject AND higher.score > s.score
                ) AS rank,
                (
                    SELECT COUNT(*) FROM scores peers WHERE peers.subject = s.subject
                ) AS cohort
             FROM scores s
             WHERE s.candidate_id = ?
             ORDER BY s.sort_order, s.id'
        );
        $subjects->execute([$candidateId]);
        $rows = [];
        $total = 0.0;
        foreach ($subjects->fetchAll() as $row) {
            $score = (float) $row['score'];
            $total += $score;
            $rows[] = [
                'subject' => (string) $row['subject'],
                'score' => $score,
                'rank' => (int) $row['rank'],
                'count' => (int) $row['cohort'],
            ];
        }

        $totalStmt = $pdo->prepare(
            'WITH totals AS (
                SELECT candidate_id, SUM(score) AS total
                FROM scores
                GROUP BY candidate_id
            )
            SELECT
                1 + (SELECT COUNT(*) FROM totals other WHERE other.total > mine.total) AS rank,
                (SELECT COUNT(*) FROM totals) AS cohort
            FROM totals mine
            WHERE mine.candidate_id = ?'
        );
        $totalStmt->execute([$candidateId]);
        $totalRow = $totalStmt->fetch() ?: ['rank' => 1, 'cohort' => 1];

        return [
            'ticket_no' => $ticket,
            'name' => $name,
            'subjects' => $rows,
            'total' => $total,
            'total_rank' => (int) $totalRow['rank'],
            'total_count' => (int) $totalRow['cohort'],
        ];
    }

    public static function listCandidates(): array
    {
        $sql = 'SELECT c.id, c.ticket_no, c.name,
                    COALESCE(SUM(s.score), 0) AS total,
                    COUNT(s.id) AS subject_count
                FROM candidates c
                LEFT JOIN scores s ON s.candidate_id = c.id
                GROUP BY c.id
                ORDER BY c.ticket_no';
        return app_pdo()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = app_pdo()->prepare('SELECT id, ticket_no, name FROM candidates WHERE id = ?');
        $stmt->execute([$id]);
        $candidate = $stmt->fetch();
        if (!$candidate) {
            return null;
        }
        $scores = app_pdo()->prepare(
            'SELECT subject, score FROM scores WHERE candidate_id = ? ORDER BY sort_order, id'
        );
        $scores->execute([$id]);
        $candidate['scores'] = $scores->fetchAll();
        $values = app_pdo()->prepare(
            'SELECT qf.field_key, cfv.value
             FROM candidate_field_values cfv
             JOIN query_fields qf ON qf.id = cfv.field_id
             WHERE cfv.candidate_id = ?'
        );
        $values->execute([$id]);
        $candidate['field_values'] = [];
        foreach ($values->fetchAll() as $value) {
            $candidate['field_values'][(string) $value['field_key']] = (string) $value['value'];
        }
        return $candidate;
    }

    public static function save(
        int $id,
        string $ticket,
        string $name,
        array $subjects,
        array $fieldValues = []
    ): void
    {
        $pdo = app_pdo();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE candidates SET ticket_no = ?, name = ? WHERE id = ?');
                $stmt->execute([$ticket, $name, $id]);
                if ($stmt->rowCount() === 0 && !self::find($id)) {
                    throw new RuntimeException('记录不存在');
                }
                $candidateId = $id;
                $pdo->prepare('DELETE FROM scores WHERE candidate_id = ?')->execute([$candidateId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO candidates (ticket_no, name) VALUES (?, ?)');
                $stmt->execute([$ticket, $name]);
                $candidateId = (int) $pdo->lastInsertId();
            }

            $insert = $pdo->prepare(
                'INSERT INTO scores (candidate_id, subject, score, sort_order) VALUES (?, ?, ?, ?)'
            );
            $order = 0;
            foreach ($subjects as $item) {
                $insert->execute([$candidateId, $item['subject'], $item['score'], $order]);
                $order++;
            }
            $pdo->prepare('DELETE FROM candidate_field_values WHERE candidate_id = ?')->execute([$candidateId]);
            $insertValue = $pdo->prepare(
                'INSERT INTO candidate_field_values (candidate_id, field_id, value) VALUES (?, ?, ?)'
            );
            foreach (self::queryFields(false) as $field) {
                $value = trim((string) ($fieldValues[$field['field_key']] ?? ''));
                if ($value !== '') {
                    $insertValue->execute([$candidateId, (int) $field['id'], $value]);
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof PDOException && str_contains($error->getMessage(), 'UNIQUE')) {
                throw new InvalidArgumentException('准考证号已存在，或同一考生科目重复');
            }
            throw $error;
        }
    }

    public static function delete(int $id): void
    {
        $stmt = app_pdo()->prepare('DELETE FROM candidates WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function importCsv(string $csv): int
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('无法读取导入内容');
        }
        fwrite($handle, $csv);
        rewind($handle);

        $grouped = [];
        $line = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            if (count($row) < 4) {
                throw new InvalidArgumentException('第 ' . $line . ' 行列数不足，需要准考证号、姓名、科目、分数');
            }
            $ticket = trim((string) $row[0]);
            $name = trim((string) $row[1]);
            $subject = trim((string) $row[2]);
            $scoreText = trim((string) $row[3]);
            if ($line === 1 && $ticket === '准考证号') {
                continue;
            }
            self::assertTicket($ticket);
            self::assertName($name);
            self::assertSubject($subject);
            $score = self::assertScore($scoreText);
            if (!isset($grouped[$ticket])) {
                $grouped[$ticket] = ['name' => $name, 'subjects' => []];
            }
            if ($grouped[$ticket]['name'] !== $name) {
                throw new InvalidArgumentException('准考证号 ' . $ticket . ' 对应了不同姓名');
            }
            foreach ($grouped[$ticket]['subjects'] as $existing) {
                if ($existing['subject'] === $subject) {
                    throw new InvalidArgumentException('准考证号 ' . $ticket . ' 的科目「' . $subject . '」重复');
                }
            }
            $grouped[$ticket]['subjects'][] = ['subject' => $subject, 'score' => $score];
        }
        fclose($handle);

        if ($grouped === []) {
            throw new InvalidArgumentException('没有可导入的成绩');
        }

        $pdo = app_pdo();
        $find = $pdo->prepare('SELECT id, name FROM candidates WHERE ticket_no = ?');
        $count = 0;
        foreach ($grouped as $ticket => $person) {
            $ticket = (string) $ticket;
            $find->execute([$ticket]);
            $existing = $find->fetch();
            if ($existing && (string) $existing['name'] !== $person['name']) {
                throw new InvalidArgumentException('准考证号 ' . $ticket . ' 已属于其他姓名，未导入');
            }
            self::save($existing ? (int) $existing['id'] : 0, $ticket, $person['name'], $person['subjects']);
            $count++;
        }
        return $count;
    }

    public static function queryFields(bool $enabledOnly = false): array
    {
        $sql = 'SELECT id, field_key, label, is_required, is_enabled, sort_order
                FROM query_fields';
        if ($enabledOnly) {
            $sql .= ' WHERE is_enabled = 1';
        }
        $sql .= ' ORDER BY sort_order, id';
        return app_pdo()->query($sql)->fetchAll();
    }

    public static function saveQueryField(
        int $id,
        string $fieldKey,
        string $label,
        bool $required,
        bool $enabled
    ): void {
        self::assertFieldKey($fieldKey);
        self::assertFieldLabel($label);
        $pdo = app_pdo();
        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE query_fields SET field_key = ?, label = ?, is_required = ?, is_enabled = ? WHERE id = ?'
            );
            $stmt->execute([$fieldKey, $label, (int) $required, (int) $enabled, $id]);
        } else {
            $order = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM query_fields')->fetchColumn();
            $stmt = $pdo->prepare(
                'INSERT INTO query_fields (field_key, label, is_required, is_enabled, sort_order)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$fieldKey, $label, (int) $required, (int) $enabled, $order]);
        }
    }

    public static function deleteQueryField(int $id): void
    {
        app_pdo()->prepare('DELETE FROM query_fields WHERE id = ?')->execute([$id]);
    }

    public static function assertTicket(string $ticket): void
    {
        if (!preg_match('/^[A-Za-z0-9]{4,32}$/', $ticket)) {
            throw new InvalidArgumentException('准考证号应为 4 到 32 位字母或数字');
        }
    }

    public static function assertName(string $name): void
    {
        $length = mb_strlen($name);
        if ($length < 1 || $length > 30) {
            throw new InvalidArgumentException('姓名需为 1 到 30 个字符');
        }
    }

    public static function assertSubject(string $subject): void
    {
        $length = mb_strlen($subject);
        if ($length < 1 || $length > 20) {
            throw new InvalidArgumentException('科目名称需为 1 到 20 个字符');
        }
    }

    public static function assertScore(string $scoreText): float
    {
        if (!preg_match('/^\d{1,3}(\.\d)?$/', $scoreText)) {
            throw new InvalidArgumentException('分数需为 0 到 999.9，最多一位小数');
        }
        $score = (float) $scoreText;
        if ($score < 0 || $score > 999.9) {
            throw new InvalidArgumentException('分数需为 0 到 999.9，最多一位小数');
        }
        return $score;
    }

    public static function assertFieldKey(string $fieldKey): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $fieldKey)) {
            throw new InvalidArgumentException('字段键只能是 2 到 31 位小写字母、数字或下划线，且以字母开头');
        }
    }

    public static function assertFieldLabel(string $label): void
    {
        $length = mb_strlen($label);
        if ($length < 1 || $length > 20) {
            throw new InvalidArgumentException('字段名称需为 1 到 20 个字符');
        }
    }
}
