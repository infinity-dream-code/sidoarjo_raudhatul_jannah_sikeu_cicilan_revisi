<?php

function env_bool(?string $value, bool $fallback = false): bool
{
    if ($value === null || $value === '') {
        return $fallback;
    }
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function is_list_array(array $arr): bool
{
    if (function_exists('array_is_list')) {
        return array_is_list($arr);
    }
    if ($arr === []) {
        return true;
    }
    return array_keys($arr) === range(0, count($arr) - 1);
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_response(['ok' => false, 'error' => 'Body harus JSON valid'], 400);
    }
    return $decoded;
}

function generate_id(): string
{
    return bin2hex(random_bytes(16));
}

function now_iso(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function format_rupiah($amount): string
{
    return 'Rp' . number_format((float) $amount, 0, ',', '.');
}

function format_datetime($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    try {
        $dt = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string) $value);
        return $dt->format('d M Y H:i:s');
    } catch (Throwable $e) {
        return (string) $value;
    }
}

function escape_html($text): string
{
    return htmlspecialchars((string) ($text ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parse_items($itemsJson): array
{
    if (is_array($itemsJson)) {
        return $itemsJson;
    }
    if (!is_string($itemsJson) || $itemsJson === '') {
        return [];
    }
    $decoded = json_decode($itemsJson, true);
    return is_array($decoded) ? $decoded : [];
}

function normalize_items(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $out[] = [
            'name' => trim((string) ($item['name'] ?? $item['nama'] ?? $item['deskripsi'] ?? 'Item')),
            'amount' => (float) ($item['amount'] ?? $item['nominal'] ?? $item['harga'] ?? 0),
        ];
    }
    return $out;
}

function sum_amounts(array $items): float
{
    $total = 0.0;
    foreach ($items as $item) {
        $total += (float) ($item['amount'] ?? 0);
    }
    return $total;
}

function first_value(array $payload, array $keys, $default = null)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
            return $payload[$key];
        }
    }
    return $default;
}

function require_fields(array $payload, array $fields): array
{
    $missing = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
            $missing[] = $field;
        }
    }
    return $missing;
}

function parse_meta($metaJson): array
{
    if (is_array($metaJson)) {
        return $metaJson;
    }
    if (!is_string($metaJson) || $metaJson === '') {
        return [];
    }
    $decoded = json_decode($metaJson, true);
    return is_array($decoded) ? $decoded : [];
}

function message_to_array(array $row): array
{
    $meta = parse_meta($row['meta_json'] ?? '{}');

    return [
        'id' => $row['id'],
        'type' => $row['type'],
        'status' => $row['status'],
        'recipientEmail' => $row['recipient_email'],
        'recipientName' => $row['recipient_name'],
        'studentName' => $row['student_name'],
        'studentNis' => $row['student_nis'],
        'studentClass' => $row['student_class'],
        'subject' => $row['subject'],
        'vaNumber' => $row['va_number'],
        'paymentLink' => $row['payment_link'],
        'paymentGuide' => $row['payment_guide'],
        'metaJson' => $row['meta_json'] ?? '{}',
        'meta' => $meta,
        'itemsJson' => $row['items_json'],
        'totalAmount' => $row['total_amount'] !== null ? (float) $row['total_amount'] : null,
        'outstandingAmount' => $row['outstanding_amount'] !== null ? (float) $row['outstanding_amount'] : null,
        'billAmount' => $row['bill_amount'] !== null ? (float) $row['bill_amount'] : null,
        'paymentAmount' => $row['payment_amount'] !== null ? (float) $row['payment_amount'] : null,
        'orderNumber' => $row['order_number'],
        'referenceNumber' => $row['reference_number'],
        'paymentMethod' => $row['payment_method'],
        'paidAt' => $row['paid_at'],
        'errorMessage' => $row['error_message'],
        'attempts' => (int) $row['attempts'],
        'sentAt' => $row['sent_at'],
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'],
    ];
}
