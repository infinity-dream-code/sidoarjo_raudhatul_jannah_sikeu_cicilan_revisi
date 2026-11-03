<?php

class MessageRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(array $data): array
    {
        $id = generate_id();
        $now = now_iso();

        $sql = 'INSERT INTO email_messages (
            id, type, status, recipient_email, recipient_name, student_name, student_nis, student_class,
            subject, va_number, payment_link, payment_guide, meta_json, items_json, total_amount, outstanding_amount,
            bill_amount, payment_amount, order_number, reference_number, payment_method, paid_at,
            error_message, attempts, sent_at, created_at, updated_at
        ) VALUES (
            :id, :type, :status, :recipient_email, :recipient_name, :student_name, :student_nis, :student_class,
            :subject, :va_number, :payment_link, :payment_guide, :meta_json, :items_json, :total_amount, :outstanding_amount,
            :bill_amount, :payment_amount, :order_number, :reference_number, :payment_method, :paid_at,
            NULL, 0, NULL, :created_at, :updated_at
        )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':type' => $data['type'],
            ':status' => $data['status'] ?? 'PENDING',
            ':recipient_email' => $data['recipient_email'],
            ':recipient_name' => $data['recipient_name'] ?? null,
            ':student_name' => $data['student_name'],
            ':student_nis' => $data['student_nis'] ?? null,
            ':student_class' => $data['student_class'] ?? null,
            ':subject' => $data['subject'],
            ':va_number' => $data['va_number'] ?? null,
            ':payment_link' => $data['payment_link'] ?? null,
            ':payment_guide' => $data['payment_guide'] ?? null,
            ':meta_json' => $data['meta_json'] ?? '{}',
            ':items_json' => $data['items_json'] ?? '[]',
            ':total_amount' => $data['total_amount'] ?? 0,
            ':outstanding_amount' => $data['outstanding_amount'] ?? null,
            ':bill_amount' => $data['bill_amount'] ?? null,
            ':payment_amount' => $data['payment_amount'] ?? null,
            ':order_number' => $data['order_number'] ?? null,
            ':reference_number' => $data['reference_number'] ?? null,
            ':payment_method' => $data['payment_method'] ?? null,
            ':paid_at' => $data['paid_at'] ?? null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->findById($id);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_messages WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function list(array $filters = [], int $limit = 50, int $page = 1): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = strtoupper((string) $filters['status']);
        }
        if (!empty($filters['type'])) {
            $where[] = 'type = :type';
            $params[':type'] = strtoupper((string) $filters['type']);
        }
        if (!empty($filters['q'])) {
            $where[] = '(recipient_email LIKE :q OR student_name LIKE :q OR student_nis LIKE :q OR order_number LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $limit = max(1, min(200, $limit));
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM email_messages {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT * FROM email_messages {$whereSql} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $summaryStmt = $this->pdo->query(
            "SELECT status, COUNT(*) AS cnt FROM email_messages GROUP BY status"
        );
        $summary = ['PENDING' => 0, 'SENDING' => 0, 'SENT' => 0, 'FAILED' => 0];
        foreach ($summaryStmt->fetchAll() as $s) {
            $summary[$s['status']] = (int) $s['cnt'];
        }

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    public function findPending(int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->query(
            "SELECT * FROM email_messages WHERE status = 'PENDING' ORDER BY created_at ASC LIMIT {$limit}"
        );
        return $stmt->fetchAll();
    }

    public function countPending(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM email_messages WHERE status = 'PENDING'");
        return (int) $stmt->fetchColumn();
    }

    public function markSending(string $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE email_messages
             SET status = 'SENDING', attempts = attempts + 1, updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([':id' => $id, ':updated_at' => now_iso()]);
    }

    public function markSent(string $id): void
    {
        $now = now_iso();
        $stmt = $this->pdo->prepare(
            "UPDATE email_messages
             SET status = 'SENT', sent_at = :sent_at, error_message = NULL, updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([':id' => $id, ':sent_at' => $now, ':updated_at' => $now]);
    }

    public function markFailed(string $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE email_messages
             SET status = 'FAILED', error_message = :error_message, updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([
            ':id' => $id,
            ':error_message' => mb_substr($error, 0, 1000),
            ':updated_at' => now_iso(),
        ]);
    }

    public function retry(string $id): ?array
    {
        $row = $this->findById($id);
        if (!$row) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE email_messages
             SET status = 'PENDING', error_message = NULL, updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([':id' => $id, ':updated_at' => now_iso()]);
        return $this->findById($id);
    }
}
