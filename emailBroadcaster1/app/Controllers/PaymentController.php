<?php

class PaymentController
{
    public function __construct(private MessageRepository $repo)
    {
    }

    public function store(?array $payload = null): void
    {
        Auth::check();
        $payload = $payload ?? read_json_body();

        $studentName = first_value($payload, ['studentName', 'nama', 'name']);
        $recipientEmail = first_value($payload, ['recipientEmail', 'email']);

        $missing = require_fields([
            'studentName' => $studentName,
            'recipientEmail' => $recipientEmail,
        ], ['studentName', 'recipientEmail']);

        if ($missing) {
            json_response(['ok' => false, 'error' => 'Field wajib: ' . implode(', ', $missing)], 400);
        }

        if (!array_key_exists('paymentAmount', $payload) && !array_key_exists('jumlahBayar', $payload)) {
            json_response(['ok' => false, 'error' => 'Field wajib: paymentAmount'], 400);
        }

        $data = $this->mapPayload($payload, (string) $studentName, (string) $recipientEmail);
        $row = $this->repo->create($data);

        json_response([
            'ok' => true,
            'method' => 'payment_confirmation',
            'queued' => 1,
            'data' => message_to_array($row),
        ], 201);
    }

    private function mapPayload(array $payload, string $studentName, string $recipientEmail): array
    {
        $items = normalize_items($payload['items'] ?? $payload['tagihan'] ?? []);
        $paymentAmount = (float) ($payload['paymentAmount'] ?? $payload['jumlahBayar'] ?? 0);

        if (array_key_exists('billAmount', $payload) && $payload['billAmount'] !== null && $payload['billAmount'] !== '') {
            $billAmount = (float) $payload['billAmount'];
        } elseif (array_key_exists('totalTagihan', $payload) && $payload['totalTagihan'] !== null && $payload['totalTagihan'] !== '') {
            $billAmount = (float) $payload['totalTagihan'];
        } else {
            $sum = sum_amounts($items);
            $billAmount = $sum > 0 ? $sum : $paymentAmount;
        }

        $outstanding = null;
        if (array_key_exists('outstandingAmount', $payload) && $payload['outstandingAmount'] !== null && $payload['outstandingAmount'] !== '') {
            $outstanding = (float) $payload['outstandingAmount'];
        } elseif (array_key_exists('sisaCicilan', $payload) && $payload['sisaCicilan'] !== null && $payload['sisaCicilan'] !== '') {
            $outstanding = (float) $payload['sisaCicilan'];
        }

        $orderNumber = first_value($payload, ['orderNumber', 'invoiceNumber']);
        $org = first_value($payload, ['organizationName', 'yayasan', 'organisasi'], Config::organizationName());
        $subject = first_value($payload, ['subject']);
        if (!$subject) {
            $subject = 'Confirmation of Receipt at ' . $org;
            if ($orderNumber) {
                $subject .= ' #' . $orderNumber;
            }
        }

        $paidAt = first_value($payload, ['paidAt']);
        if ($paidAt) {
            try {
                $paidAt = (new DateTimeImmutable((string) $paidAt))->format('Y-m-d H:i:s');
            } catch (Throwable $e) {
                $paidAt = now_iso();
            }
        } else {
            $paidAt = now_iso();
        }

        return [
            'type' => 'PAYMENT_CONFIRMATION',
            'status' => 'PENDING',
            'recipient_email' => $recipientEmail,
            'recipient_name' => first_value($payload, ['recipientName', 'namaWali'], $studentName),
            'student_name' => $studentName,
            'student_nis' => first_value($payload, ['studentNis', 'nis']),
            'student_class' => first_value($payload, ['studentClass', 'kelas']),
            'subject' => $subject,
            'va_number' => first_value($payload, ['vaNumber', 'va']),
            'payment_link' => null,
            'payment_guide' => null,
            'meta_json' => json_encode([
                'organizationName' => $org,
            ], JSON_UNESCAPED_UNICODE),
            'items_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'total_amount' => $billAmount,
            'bill_amount' => $billAmount,
            'payment_amount' => $paymentAmount,
            'outstanding_amount' => $outstanding,
            'order_number' => $orderNumber,
            'reference_number' => first_value($payload, ['referenceNumber', 'refNumber']),
            'payment_method' => first_value($payload, ['paymentMethod', 'metodePembayaran', 'bank']),
            'paid_at' => $paidAt,
        ];
    }
}
