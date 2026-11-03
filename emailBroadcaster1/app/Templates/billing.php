<?php

function render_billing_email(array $message): array
{
    $items = parse_items($message['itemsJson'] ?? '[]');
    $meta = $message['meta'] ?? parse_meta($message['metaJson'] ?? '{}');

    $org = (string) ($meta['organizationName'] ?? Config::organizationName());
    $billType = (string) ($meta['billType'] ?? 'CLOSE');
    $invoiceDate = (string) ($meta['invoiceDate'] ?? '');
    $activeDate = (string) ($meta['activeDate'] ?? '');
    $expireDate = (string) ($meta['expireDate'] ?? '');
    $adminFee = $meta['adminFee'] ?? null;
    $adminFeeLabel = (string) ($meta['adminFeeLabel'] ?? 'Admin VA');

    $rows = '';
    $textItems = [];
    foreach ($items as $item) {
        $rows .= '<tr>
        <td style="padding:10px 12px;border-bottom:1px solid #eee;">' . escape_html($item['name'] ?? '') . '</td>
        <td style="padding:10px 12px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap;">' . format_rupiah($item['amount'] ?? 0) . '</td>
      </tr>';
        $textItems[] = '- ' . ($item['name'] ?? '') . ': ' . format_rupiah($item['amount'] ?? 0);
    }

    $outstandingBlock = '';
    if (isset($message['outstandingAmount']) && $message['outstandingAmount'] !== null && $message['outstandingAmount'] !== '') {
        $outstandingBlock = '<tr>
          <td style="padding:10px 12px;font-weight:700;">Outstanding / Sisa Cicilan</td>
          <td style="padding:10px 12px;text-align:right;font-weight:700;color:#c2410c;">' . format_rupiah($message['outstandingAmount']) . '</td>
        </tr>';
    }

    $totalLabel = format_rupiah($message['totalAmount'] ?? 0);
    if ($adminFee !== null && $adminFee !== '') {
        $totalLabel .= ' + ' . format_rupiah($adminFee) . ' (' . $adminFeeLabel . ')';
    }

    $brandBg = (string) Config::get('MAIL_BRAND_COLOR', '#0a0a0a');
    $brandAccent = (string) Config::get('MAIL_BRAND_ACCENT', '#4fbef7');
    $brandOrange = (string) Config::get('MAIL_BRAND_ORANGE', '#f59d42');

    $paymentButton = '';
    if (!empty($message['paymentLink'])) {
        $paymentButton = '<p style="margin:28px 0 8px;text-align:center;">
        <a href="' . escape_html($message['paymentLink']) . '" style="display:inline-block;background:' . escape_html($brandOrange) . ';color:#fff;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:700;">Bayar Disini</a>
      </p>
      <p style="margin:0 0 8px;text-align:center;font-size:12px;color:#6b7280;">
        atau buka: <a href="' . escape_html($message['paymentLink']) . '" style="color:' . escape_html($brandAccent) . ';">' . escape_html($message['paymentLink']) . '</a>
      </p>';
    }

    $guide = '';
    if (!empty($message['paymentGuide'])) {
        foreach (preg_split("/\r\n|\n|\r/", (string) $message['paymentGuide']) as $line) {
            $guide .= '<div>' . escape_html($line) . '</div>';
        }
    }

    $guideBlock = $guide !== ''
        ? '<div style="margin-top:20px;padding:14px 16px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;font-size:13px;line-height:1.6;color:#9a3412;">
                    <div style="font-weight:700;margin-bottom:6px;">PANDUAN/TATA CARA PEMBAYARAN</div>
                    ' . $guide . '
                  </div>'
        : '';

    $detailRows = [
        ['No. Invoice', $message['orderNumber'] ?? null],
        ['Tanggal Dibuat', $invoiceDate !== '' ? $invoiceDate : null],
        ['No. Pembayaran (VA)', $message['vaNumber'] ?? null],
        ['Nama', $message['studentName'] ?? null],
        ['Kelas', $message['studentClass'] ?? null],
        ['NIS', $message['studentNis'] ?? null],
        ['Email', $message['recipientEmail'] ?? null],
        ['Tipe/Jenis Tagihan', $billType],
        ['Tanggal Aktif', $activeDate !== '' ? $activeDate : null],
        ['Tanggal Berakhir', $expireDate !== '' ? $expireDate : null],
    ];

    $detailHtml = '';
    foreach ($detailRows as [$label, $value]) {
        if ($value === null || $value === '') {
            continue;
        }
        $detailHtml .= '<tr>
          <td style="padding:6px 0;width:170px;color:#6b7280;vertical-align:top;">' . escape_html($label) . '</td>
          <td style="padding:6px 0;">: ' . escape_html($value) . '</td>
        </tr>';
    }

    $html = '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);">
        <tr>
          <td style="background:' . escape_html($brandBg) . ';color:#fff;padding:18px 20px;border-bottom:3px solid ' . escape_html($brandAccent) . ';">
            <div style="font-size:18px;font-weight:700;color:#ffffff;">Notifikasi Tagihan</div>
            <div style="margin-top:4px;font-size:13px;color:' . escape_html($brandAccent) . ';">Assalamu\'alaikum Warrahmatullahi Wabarakatuh</div>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;">
            <p style="margin:0 0 16px;line-height:1.5;">
              Berikut tagihan yang harus dibayarkan kepada <strong>' . escape_html($org) . '</strong> dengan rincian sebagai berikut:
            </p>

            <table role="presentation" width="100%" style="border-collapse:collapse;margin-bottom:18px;font-size:14px;">
              ' . $detailHtml . '
            </table>

            <div style="font-size:14px;font-weight:700;margin:0 0 8px;">Rincian Pembayaran</div>
            <table role="presentation" width="100%" style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;font-size:14px;">
              <thead>
                <tr style="background:#f9fafb;">
                  <th align="left" style="padding:10px 12px;">Jenis Tagihan</th>
                  <th align="right" style="padding:10px 12px;">Nominal</th>
                </tr>
              </thead>
              <tbody>
                ' . ($rows !== '' ? $rows : '<tr><td colspan="2" style="padding:12px;color:#6b7280;">Tidak ada item</td></tr>') . '
                <tr>
                  <td style="padding:10px 12px;font-weight:700;background:#f9fafb;">Total Nominal</td>
                  <td style="padding:10px 12px;text-align:right;font-weight:700;background:#f9fafb;">' . escape_html($totalLabel) . '</td>
                </tr>
                ' . $outstandingBlock . '
              </tbody>
            </table>

            ' . $paymentButton . '
            ' . $guideBlock . '
          </td>
        </tr>
        <tr>
          <td style="padding:14px 24px;background:#f9fafb;color:#6b7280;font-size:12px;">
            Email ini dikirim otomatis oleh ' . escape_html($org) . '.
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';

    $text = "Assalamu'alaikum Warrahmatullahi Wabarakatuh\n\n";
    $text .= 'Berikut tagihan yang harus dibayarkan kepada ' . $org . " dengan rincian sebagai berikut:\n\n";
    if (!empty($message['orderNumber'])) {
        $text .= 'No. Invoice : ' . $message['orderNumber'] . "\n";
    }
    if ($invoiceDate !== '') {
        $text .= 'Tanggal Dibuat : ' . $invoiceDate . "\n";
    }
    if (!empty($message['vaNumber'])) {
        $text .= 'No. Pembayaran (VA) : ' . $message['vaNumber'] . "\n";
    }
    $text .= 'Nama : ' . ($message['studentName'] ?? '') . "\n";
    if (!empty($message['studentClass'])) {
        $text .= 'Kelas : ' . $message['studentClass'] . "\n";
    }
    if (!empty($message['studentNis'])) {
        $text .= 'NIS : ' . $message['studentNis'] . "\n";
    }
    if (!empty($message['recipientEmail'])) {
        $text .= 'Email : ' . $message['recipientEmail'] . "\n";
    }
    $text .= 'Tipe/Jenis Tagihan : ' . $billType . "\n";
    if ($activeDate !== '') {
        $text .= 'Tanggal Aktif : ' . $activeDate . "\n";
    }
    if ($expireDate !== '') {
        $text .= 'Tanggal Berakhir : ' . $expireDate . "\n";
    }
    $text .= "\nRincian Pembayaran :\n" . implode("\n", $textItems) . "\n\n";
    $text .= 'Total Nominal : ' . $totalLabel . "\n";
    if (isset($message['outstandingAmount']) && $message['outstandingAmount'] !== null && $message['outstandingAmount'] !== '') {
        $text .= 'Outstanding / Sisa Cicilan : ' . format_rupiah($message['outstandingAmount']) . "\n";
    }
    if (!empty($message['paymentLink'])) {
        $text .= "\nBayar Disini : " . $message['paymentLink'] . "\n";
    }
    if (!empty($message['paymentGuide'])) {
        $text .= "\nPANDUAN/TATA CARA PEMBAYARAN:\n" . $message['paymentGuide'] . "\n";
    }

    return ['html' => $html, 'text' => $text];
}
