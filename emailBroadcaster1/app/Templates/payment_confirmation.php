<?php

function render_payment_confirmation_email(array $message): array
{
    $items = parse_items($message['itemsJson'] ?? '[]');
    $itemLabel = count($items) > 0
        ? implode(', ', array_map(static fn($i) => (string) ($i['name'] ?? ''), $items))
        : ((string) ($message['orderNumber'] ?? 'Pembayaran'));

    $pairs = [
        ['Order Number', $message['orderNumber'] ?? null],
        ['Date/Time', format_datetime($message['paidAt'] ?? $message['createdAt'] ?? null)],
        ['Item(s)', $itemLabel],
        ['Bill Amount', format_rupiah($message['billAmount'] ?? $message['totalAmount'] ?? 0)],
        ['Payment Amount', format_rupiah($message['paymentAmount'] ?? $message['totalAmount'] ?? 0)],
    ];

    if (isset($message['outstandingAmount']) && $message['outstandingAmount'] !== null && $message['outstandingAmount'] !== '') {
        $pairs[] = ['Outstanding Amount', format_rupiah($message['outstandingAmount'])];
    }

    $pairs[] = ['Reference Number', $message['referenceNumber'] ?? null];
    $pairs[] = ['Payment Method', $message['paymentMethod'] ?? null];

    if (!empty($message['studentClass'])) {
        $pairs[] = ['Kelas', $message['studentClass']];
    }
    if (!empty($message['studentNis'])) {
        $pairs[] = ['NIS', $message['studentNis']];
    }
    if (!empty($message['vaNumber'])) {
        $pairs[] = ['Nomor VA', $message['vaNumber']];
    }

    $rows = '';
    foreach ($pairs as [$label, $value]) {
        $rows .= '<tr>
        <td style="padding:10px 0;border-bottom:1px solid #f3f4f6;color:#6b7280;width:42%;">' . escape_html($label) . '</td>
        <td style="padding:10px 0;border-bottom:1px solid #f3f4f6;font-weight:600;">' . escape_html($value ?? '-') . '</td>
      </tr>';
    }

    $name = $message['studentName'] ?? $message['recipientName'] ?? 'Wali Murid';
    $meta = $message['meta'] ?? parse_meta($message['metaJson'] ?? '{}');
    $org = (string) ($meta['organizationName'] ?? Config::organizationName());
    $brandOrange = (string) Config::get('MAIL_BRAND_ORANGE', '#f59d42');

    $html = '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);">
        <tr>
          <td style="background:' . escape_html($brandOrange) . ';color:#fff;padding:28px 24px;text-align:center;">
            <div style="font-size:22px;font-weight:800;">Thank You! Your payment has been received</div>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;">
            <p style="margin:0 0 8px;font-size:16px;">Dear <strong>' . escape_html($name) . '</strong>,</p>
            <p style="margin:0 0 20px;line-height:1.5;color:#374151;">
              Great news, Your payment is successful! Below are the summary of Your purchase :
            </p>
            <table role="presentation" width="100%" style="border-collapse:collapse;font-size:14px;">
              ' . $rows . '
            </table>
            <p style="margin:24px 0 0;font-size:13px;color:#6b7280;line-height:1.6;">
              Please keep this email as your reference. If you have any questions about Your order please contact ' . escape_html($org) . ' finance office.
            </p>
            <p style="margin:16px 0 0;font-weight:700;">Thank You.</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';

    $text = "Thank You! Your payment has been received\n\n";
    $text .= 'Dear ' . $name . ",\n\n";
    $text .= "Great news, Your payment is successful! Below are the summary of Your purchase :\n\n";
    $text .= 'Order Number: ' . ($message['orderNumber'] ?? '-') . "\n";
    $text .= 'Date/Time: ' . format_datetime($message['paidAt'] ?? $message['createdAt'] ?? null) . "\n";
    $text .= 'Item(s): ' . $itemLabel . "\n";
    $text .= 'Bill Amount: ' . format_rupiah($message['billAmount'] ?? $message['totalAmount'] ?? 0) . "\n";
    $text .= 'Payment Amount: ' . format_rupiah($message['paymentAmount'] ?? $message['totalAmount'] ?? 0) . "\n";
    if (isset($message['outstandingAmount']) && $message['outstandingAmount'] !== null && $message['outstandingAmount'] !== '') {
        $text .= 'Outstanding Amount: ' . format_rupiah($message['outstandingAmount']) . "\n";
    }
    $text .= 'Reference Number: ' . ($message['referenceNumber'] ?? '-') . "\n";
    $text .= 'Payment Method: ' . ($message['paymentMethod'] ?? '-') . "\n\n";
    $text .= "Thank You.";

    return ['html' => $html, 'text' => $text];
}
