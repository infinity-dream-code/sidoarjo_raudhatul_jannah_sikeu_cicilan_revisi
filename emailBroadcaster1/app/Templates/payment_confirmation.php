<?php

function render_payment_confirmation_email(array $message): array
{
    $items = parse_items($message['itemsJson'] ?? '[]');
    $description = count($items) > 0
        ? implode(', ', array_map(static fn($i) => (string) ($i['name'] ?? ''), $items))
        : ((string) ($message['orderNumber'] ?? 'Pembayaran'));

    $meta = $message['meta'] ?? parse_meta($message['metaJson'] ?? '{}');
    $name = $message['studentName'] ?? $message['recipientName'] ?? 'Wali Murid';
    $email = (string) ($message['recipientEmail'] ?? '');
    $va = trim((string) ($message['vaNumber'] ?? ''));
    $org = (string) ($meta['organizationName'] ?? Config::organizationName());

    $paidAtRaw = $message['paidAt'] ?? $message['createdAt'] ?? null;
    $paidAtLabel = format_payment_datetime($paidAtRaw);
    $jakartaDate = format_jakarta_date($paidAtRaw);
    $expireDate = (string) ($meta['expireDate'] ?? $message['expireDate'] ?? '');

    // Total Tagihan = billAmount (penuh). Jangan pakai totalAmount sebagai fallback bill.
    $billRaw = $message['billAmount'] ?? null;
    if ($billRaw === null || $billRaw === '') {
        $billRaw = 0;
    }
    // Nominal Pembayaran = cicilan/bayar ini saja (paymentAmount). Jangan fallback ke total tagihan.
    $payRaw = $message['paymentAmount'] ?? null;
    if ($payRaw === null || $payRaw === '') {
        $payRaw = $message['totalAmount'] ?? 0;
    }
    $billAmount = format_idr($billRaw);
    $paidAmount = format_idr($payRaw);

    // Total yang telah dibayar = akumulasi BILLPAID (meta.totalPaid), bukan total tagihan.
    $totalPaidRaw = $meta['totalPaid'] ?? $message['totalPaid'] ?? null;
    if ($totalPaidRaw === null || $totalPaidRaw === '') {
        $outRaw = $message['outstandingAmount'] ?? null;
        if ($outRaw !== null && $outRaw !== '' && (float) $billRaw > 0) {
            $totalPaidRaw = max(0, (float) $billRaw - (float) $outRaw);
        } else {
            $totalPaidRaw = $payRaw;
        }
    }
    $totalPaid = format_idr($totalPaidRaw);
    $leftAmount = format_idr($message['outstandingAmount'] ?? max(0, (float) $billRaw - (float) $totalPaidRaw));

    $journal = trim((string) ($message['referenceNumber'] ?? ''));
    if ($journal === '') {
        $journal = '-';
    }

    $isDu = !empty($meta['isDaftarUlang']);
    if (!$isDu) {
        $isDu = (bool) preg_match('/daftar\s*ulang|\bDU\b/i', $description);
    }

    // Tanpa Kode Tagihan. DU punya Total telah dibayar / Kekurangan / Jatuh Tempo.
    $pairs = [
        ['Nomor VA', 'VA Number', $va !== '' ? $va : '-'],
        ['Nama', 'Name', $name],
        ['Surel', 'Email', $email !== '' ? $email : '-'],
        ['Total Tagihan', 'Billed Amount', $billAmount],
        ['Nominal Pembayaran', 'Paid Amount', $paidAmount],
    ];

    if ($isDu) {
        $pairs[] = ['Total yang telah dibayar', 'Total Paid Amount', $totalPaid];
        $pairs[] = ['Kekurangan', 'Left Amount', $leftAmount];
    }

    $pairs[] = ['Tanggal Pembayaran', 'Payment Date', $paidAtLabel];
    $pairs[] = ['Deskripsi', 'Description', $description !== '' ? $description : '-'];
    $pairs[] = ['Nomor Jurnal', 'Journal Number', $journal];

    if ($isDu && $expireDate !== '') {
        $pairs[] = ['Tanggal Jatuh Tempo', 'Due Date', $expireDate];
    }

    $rows = '';
    foreach ($pairs as [$idLabel, $enLabel, $value]) {
        $rows .= '<tr>
        <td style="padding:12px 0;border-bottom:1px solid #e5e7eb;vertical-align:top;width:42%;">
          <div style="font-size:14px;color:#111827;font-weight:600;">' . escape_html($idLabel) . '</div>
          <div style="font-size:12px;color:#6b7280;font-style:italic;margin-top:2px;">' . escape_html($enLabel) . '</div>
        </td>
        <td style="padding:12px 0;border-bottom:1px solid #e5e7eb;vertical-align:top;font-size:14px;font-weight:600;color:#111827;">'
            . escape_html($value) .
        '</td>
      </tr>';
    }

    $root = dirname(__DIR__, 2);
    $logoPath = Config::mailPaymentLogoPath($root);
    $logoCid = Config::mailPaymentLogoCid();
    $inline = [];
    $logoHtml = '';
    if ($logoPath) {
        $inline[] = [
            'path' => $logoPath,
            'cid' => $logoCid,
            'name' => basename($logoPath),
        ];
        $logoHtml = '<img src="cid:' . escape_html($logoCid) . '" alt="Bank Muamalat" style="max-height:52px;max-width:220px;display:block;">';
    } else {
        $logoUrl = Config::mailPaymentLogoUrl();
        if ($logoUrl !== '') {
            $logoHtml = '<img src="' . escape_html($logoUrl) . '" alt="Bank Muamalat" style="max-height:52px;max-width:220px;display:block;">';
        } else {
            $logoHtml = '<div style="font-size:16px;font-weight:800;color:#4c1d95;">Bank Muamalat</div>';
        }
    }

    $html = '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" style="max-width:640px;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);">
        <tr>
          <td style="padding:20px 24px 8px;">
            ' . $logoHtml . '
            <div style="margin-top:16px;font-size:13px;color:#374151;">Jakarta, ' . escape_html($jakartaDate) . '</div>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 24px 24px;">
            <p style="margin:0 0 4px;font-size:15px;">Kepada Yth. ' . escape_html($name) . ',</p>
            <p style="margin:0 0 16px;font-size:14px;font-style:italic;color:#4b5563;">Dear ' . escape_html($name) . ',</p>
            <p style="margin:0 0 4px;line-height:1.55;font-size:14px;">
              Pembayaran telah dilakukan pada tagihan dengan rincian sebagai berikut:
            </p>
            <p style="margin:0 0 18px;line-height:1.55;font-size:13px;font-style:italic;color:#4b5563;">
              Payment has been made with detailed info:
            </p>
            <table role="presentation" width="100%" style="border-collapse:collapse;">
              ' . $rows . '
            </table>
            <p style="margin:24px 0 0;font-size:12px;color:#6b7280;line-height:1.6;">
              Simpan email ini sebagai bukti pembayaran. Jika ada pertanyaan, hubungi bagian keuangan ' . escape_html($org) . '.
            </p>
            <p style="margin:12px 0 0;font-size:12px;font-style:italic;color:#6b7280;line-height:1.6;">
              Please keep this email as your payment reference. Contact ' . escape_html($org) . ' finance office if you have any questions.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';

    $text = 'Jakarta, ' . $jakartaDate . "\n\n";
    $text .= 'Kepada Yth. ' . $name . "\n";
    $text .= 'Dear ' . $name . "\n\n";
    $text .= "Pembayaran telah dilakukan pada tagihan dengan rincian sebagai berikut:\n";
    $text .= "Payment has been made with detailed info:\n\n";
    foreach ($pairs as [$idLabel, $enLabel, $value]) {
        $text .= $idLabel . ' / ' . $enLabel . ': ' . $value . "\n";
    }

    return ['html' => $html, 'text' => $text, 'inline' => $inline];
}

function format_idr($amount): string
{
    return 'IDR ' . number_format((float) $amount, 0, ',', '.');
}

function format_payment_datetime($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    try {
        $dt = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string) $value);
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        return $dt->format('j') . ' ' . $months[(int) $dt->format('n') - 1] . ' ' . $dt->format('Y H:i:s');
    } catch (Throwable $e) {
        return (string) $value;
    }
}

function format_jakarta_date($value): string
{
    try {
        $dt = ($value === null || $value === '')
            ? new DateTimeImmutable('now')
            : ($value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)
                : new DateTimeImmutable((string) $value));
        $months = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];

        return $dt->format('j') . ' ' . $months[(int) $dt->format('n') - 1] . ' ' . $dt->format('Y');
    } catch (Throwable $e) {
        return date('j F Y');
    }
}
