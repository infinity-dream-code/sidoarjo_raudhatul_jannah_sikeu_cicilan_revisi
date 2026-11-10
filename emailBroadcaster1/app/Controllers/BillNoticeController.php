<?php

class BillNoticeController
{
    public function __construct(private MessageRepository $repo)
    {
    }

    public function store(?array $body = null): void
    {
        Auth::check();
        $body = $body ?? read_json_body();

        if (is_list_array($body)) {
            $rawList = $body;
        } elseif (isset($body['messages']) && is_array($body['messages'])) {
            $rawList = $body['messages'];
        } else {
            $rawList = [$body];
        }

        $created = [];
        $errors = [];

        foreach ($rawList as $i => $payload) {
            if (!is_array($payload)) {
                $errors[] = ['index' => $i, 'error' => 'Payload tidak valid'];
                continue;
            }

            try {
                $data = $this->mapPayload($payload);
                $row = $this->repo->create($data);
                $created[] = message_to_array($row);
            } catch (Throwable $e) {
                $errors[] = ['index' => $i, 'error' => $e->getMessage()];
            }
        }

        json_response([
            'ok' => true,
            'method' => 'bill_notice',
            'queued' => count($created),
            'failed' => count($errors),
            'data' => $created,
            'errors' => $errors,
        ], 201);
    }

    private function mapPayload(array $payload): array
    {
        $studentName = first_value($payload, ['studentName', 'nama', 'name']);
        $recipientEmail = first_value($payload, ['recipientEmail', 'email']);
        $missing = require_fields([
            'studentName' => $studentName,
            'recipientEmail' => $recipientEmail,
        ], ['studentName', 'recipientEmail']);
        if ($missing) {
            throw new InvalidArgumentException('Field wajib: ' . implode(', ', $missing));
        }

        $method = strtolower(trim((string) first_value($payload, ['method', 'jenis', 'noticeType'], 'bill_update')));
        $type = $this->resolveType($method);

        $org = first_value($payload, ['organizationName', 'yayasan', 'organisasi'], Config::organizationName());
        $subject = first_value($payload, ['subject']);
        if (!$subject) {
            $subject = $type === 'EXP_UPDATE'
                ? ('Perubahan tanggal expired tagihan ananda ' . $studentName)
                : ('Perubahan data tagihan ananda ' . $studentName);
        }

        $meta = [
            'organizationName' => $org,
            'billType' => first_value($payload, ['billType', 'tipeTagihan']),
            'expireDate' => first_value($payload, ['expireDate', 'tanggalBerakhir', 'newExpireDate']),
            'oldExpireDate' => first_value($payload, ['oldExpireDate', 'tanggalExpiredLama']),
            'newExpireDate' => first_value($payload, ['newExpireDate', 'tanggalExpiredBaru', 'expireDate']),
            'billName' => first_value($payload, ['billName', 'namaTagihan', 'newBillName']),
            'oldBillName' => first_value($payload, ['oldBillName', 'namaTagihanLama']),
            'newBillName' => first_value($payload, ['newBillName', 'namaTagihanBaru', 'billName']),
            'oldAmount' => $payload['oldAmount'] ?? $payload['nominalLama'] ?? null,
            'newAmount' => $payload['newAmount'] ?? $payload['nominalBaru'] ?? $payload['totalAmount'] ?? null,
        ];

        $items = normalize_items($payload['items'] ?? []);
        if ($items === []) {
            $items = [[
                'name' => (string) ($meta['newBillName'] ?? $meta['billName'] ?? 'Tagihan'),
                'amount' => (float) ($meta['newAmount'] ?? 0),
            ]];
        }

        return [
            'type' => $type,
            'status' => 'PENDING',
            'recipient_email' => $recipientEmail,
            'recipient_name' => first_value($payload, ['recipientName', 'namaWali'], $studentName),
            'student_name' => $studentName,
            'student_nis' => first_value($payload, ['studentNis', 'nis']),
            'student_class' => first_value($payload, ['studentClass', 'kelas']),
            'subject' => $subject,
            'va_number' => first_value($payload, ['vaNumber', 'va', 'nomorVa']),
            'payment_link' => first_value($payload, ['paymentLink', 'linkPembayaran']),
            'payment_guide' => null,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'items_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'total_amount' => (float) ($meta['newAmount'] ?? sum_amounts($items)),
            'outstanding_amount' => $payload['outstandingAmount'] ?? null,
            'order_number' => first_value($payload, ['orderNumber', 'invoiceNumber', 'billCd', 'aa']),
        ];
    }

    private function resolveType(string $method): string
    {
        if (in_array($method, [
            'exp_update',
            'expire_update',
            'expired_update',
            'ganti_expired',
            'tanggal_expired',
            'exp',
        ], true)) {
            return 'EXP_UPDATE';
        }

        return 'BILL_UPDATE';
    }
}
