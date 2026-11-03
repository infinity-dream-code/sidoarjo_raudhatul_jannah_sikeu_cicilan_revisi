<?php

class BillingController
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

            $studentName = first_value($payload, ['studentName', 'nama', 'name']);
            $recipientEmail = first_value($payload, ['recipientEmail', 'email']);

            $missing = require_fields([
                'studentName' => $studentName,
                'recipientEmail' => $recipientEmail,
            ], ['studentName', 'recipientEmail']);

            if ($missing) {
                $errors[] = ['index' => $i, 'error' => 'Field wajib: ' . implode(', ', $missing)];
                continue;
            }

            $data = $this->mapPayload($payload, (string) $studentName, (string) $recipientEmail);
            $row = $this->repo->create($data);
            $created[] = message_to_array($row);
        }

        json_response([
            'ok' => true,
            'method' => 'billing',
            'queued' => count($created),
            'failed' => count($errors),
            'data' => $created,
            'errors' => $errors,
        ], 201);
    }

    private function mapPayload(array $payload, string $studentName, string $recipientEmail): array
    {
        $items = normalize_items($payload['items'] ?? $payload['tagihan'] ?? []);
        $totalAmount = array_key_exists('totalAmount', $payload) && $payload['totalAmount'] !== null
            ? (float) $payload['totalAmount']
            : sum_amounts($items);

        $studentClass = first_value($payload, ['studentClass', 'kelas']);
        $studentNis = first_value($payload, ['studentNis', 'nis']);
        $vaNumber = first_value($payload, ['vaNumber', 'va', 'nomorVa']);
        $paymentLink = first_value(
            $payload,
            ['paymentLink', 'linkPembayaran'],
            Config::get('DEFAULT_PAYMENT_LINK', 'https://sdit-gemanurani.smartpayment.co.id')
        );
        $orderNumber = first_value($payload, ['orderNumber', 'invoiceNumber', 'noInvoice']);

        $outstanding = null;
        if (array_key_exists('outstandingAmount', $payload) && $payload['outstandingAmount'] !== null && $payload['outstandingAmount'] !== '') {
            $outstanding = (float) $payload['outstandingAmount'];
        } elseif (array_key_exists('sisaCicilan', $payload) && $payload['sisaCicilan'] !== null && $payload['sisaCicilan'] !== '') {
            $outstanding = (float) $payload['sisaCicilan'];
        }

        $subject = first_value($payload, ['subject']);
        if (!$subject) {
            $subject = 'Tagihan ' . $studentName;
            if ($studentClass) {
                $subject .= ' - ' . $studentClass;
            }
            if ($orderNumber) {
                $subject .= ' #' . $orderNumber;
            }
        }

        $adminFee = null;
        if (array_key_exists('adminFee', $payload) && $payload['adminFee'] !== null && $payload['adminFee'] !== '') {
            $adminFee = (float) $payload['adminFee'];
        } elseif (array_key_exists('biayaAdmin', $payload) && $payload['biayaAdmin'] !== null && $payload['biayaAdmin'] !== '') {
            $adminFee = (float) $payload['biayaAdmin'];
        }

        $meta = [
            'organizationName' => first_value($payload, ['organizationName', 'yayasan', 'organisasi'], Config::organizationName()),
            'billType' => first_value($payload, ['billType', 'tipeTagihan', 'jenisTagihan'], 'CLOSE'),
            'invoiceDate' => first_value($payload, ['invoiceDate', 'tanggalDibuat', 'createdDate']),
            'activeDate' => first_value($payload, ['activeDate', 'tanggalAktif']),
            'expireDate' => first_value($payload, ['expireDate', 'tanggalBerakhir', 'kadaluarsa']),
            'adminFee' => $adminFee,
            'adminFeeLabel' => first_value($payload, ['adminFeeLabel', 'labelAdmin'], 'Admin VA'),
        ];

        return [
            'type' => 'BILLING',
            'status' => 'PENDING',
            'recipient_email' => $recipientEmail,
            'recipient_name' => first_value($payload, ['recipientName', 'namaWali'], $studentName),
            'student_name' => $studentName,
            'student_nis' => $studentNis,
            'student_class' => $studentClass,
            'subject' => $subject,
            'va_number' => $vaNumber,
            'payment_link' => $paymentLink,
            'payment_guide' => first_value($payload, ['paymentGuide', 'tataCara'], Config::defaultPaymentGuide()),
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'items_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'total_amount' => $totalAmount,
            'outstanding_amount' => $outstanding,
            'order_number' => $orderNumber,
        ];
    }
}
