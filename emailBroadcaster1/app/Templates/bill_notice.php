<?php

function render_bill_notice_email(array $message): array
{
    $meta = $message['meta'] ?? parse_meta($message['metaJson'] ?? '{}');
    $type = strtoupper((string) ($message['type'] ?? 'BILL_UPDATE'));
    $isExp = $type === 'EXP_UPDATE';

    $title = $isExp
        ? 'Perubahan Tanggal Expired Tagihan'
        : 'Perubahan Data Tagihan';
    $intro = $isExp
        ? 'Tanggal expired tagihan ananda telah diperbarui. Berikut ringkasannya:'
        : 'Data tagihan ananda telah diubah. Berikut ringkasannya:';

    $org = (string) ($meta['organizationName'] ?? Config::organizationName());
    $brandBg = (string) Config::get('MAIL_BRAND_COLOR', '#0a0a0a');
    $brandAccent = (string) Config::get('MAIL_BRAND_ACCENT', '#4fbef7');

    $pairs = [
        ['Nama', $message['studentName'] ?? null],
        ['NIS', $message['studentNis'] ?? null],
        ['Kelas', $message['studentClass'] ?? null],
        ['No. VA', $message['vaNumber'] ?? null],
        ['No. Invoice / Tagihan', $message['orderNumber'] ?? null],
    ];

    if ($isExp) {
        $pairs[] = ['Nama Tagihan', $meta['billName'] ?? null];
        $pairs[] = ['Tanggal Expired Lama', $meta['oldExpireDate'] ?? null];
        $pairs[] = ['Tanggal Expired Baru', $meta['newExpireDate'] ?? ($meta['expireDate'] ?? null)];
    } else {
        $pairs[] = ['Nama Tagihan Lama', $meta['oldBillName'] ?? null];
        $pairs[] = ['Nama Tagihan Baru', $meta['newBillName'] ?? null];
        $pairs[] = ['Nominal Lama', isset($meta['oldAmount']) ? format_rupiah($meta['oldAmount']) : null];
        $pairs[] = ['Nominal Baru', isset($meta['newAmount']) ? format_rupiah($meta['newAmount']) : null];
        $pairs[] = ['Tanggal Expired', $meta['expireDate'] ?? null];
    }

    $detailHtml = '';
    $textLines = [];
    foreach ($pairs as [$label, $value]) {
        if ($value === null || $value === '') {
            continue;
        }
        $detailHtml .= '<tr>
          <td style="padding:6px 0;width:190px;color:#6b7280;vertical-align:top;">' . escape_html($label) . '</td>
          <td style="padding:6px 0;">: ' . escape_html((string) $value) . '</td>
        </tr>';
        $textLines[] = $label . ' : ' . $value;
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
            <div style="font-size:18px;font-weight:800;">' . escape_html($org) . '</div>
            <div style="margin-top:4px;font-size:14px;opacity:.9;">' . escape_html($title) . '</div>
          </td>
        </tr>
        <tr>
          <td style="padding:22px 20px;">
            <p style="margin:0 0 14px;line-height:1.6;">' . escape_html($intro) . '</p>
            <table role="presentation" width="100%" style="border-collapse:collapse;font-size:14px;">
              ' . $detailHtml . '
            </table>
            <p style="margin:20px 0 0;font-size:12px;color:#6b7280;line-height:1.6;">
              Jika ada pertanyaan, hubungi bagian keuangan ' . escape_html($org) . '.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';

    $text = $title . "\n\n" . $intro . "\n\n" . implode("\n", $textLines) . "\n";

    return ['html' => $html, 'text' => $text];
}
