<?php

class NotifyController
{
    public function __construct(
        private BillingController $billing,
        private PaymentController $payment,
        private BillNoticeController $billNotice
    ) {
    }

    /**
     * @param string|null $defaultMethod Digunakan jika payload tidak punya method
     */
    public function store(?string $defaultMethod = null, ?array $body = null): void
    {
        Auth::check();
        $body = $body ?? read_json_body();

        $method = strtolower(trim((string) first_value($body, ['method', 'jenis'], $defaultMethod ?? '')));
        if ($method === '') {
            json_response([
                'ok' => false,
                'error' => 'Field wajib: method. Gunakan billing | payment_confirmation | bill_update | exp_update',
            ], 400);
        }

        if ($this->isBillingMethod($method)) {
            $this->billing->store($body);
            return;
        }

        if ($this->isPaymentMethod($method)) {
            $this->payment->store($body);
            return;
        }

        if ($this->isBillNoticeMethod($method)) {
            $this->billNotice->store($body);
            return;
        }

        json_response([
            'ok' => false,
            'error' => 'method tidak dikenali. Gunakan: billing | payment_confirmation | bill_update | exp_update',
            'received' => $method,
        ], 400);
    }

    private function isBillingMethod(string $method): bool
    {
        return in_array($method, [
            'billing',
            'tagihan',
            'invoice',
            'penagihan',
            'bill',
        ], true);
    }

    private function isPaymentMethod(string $method): bool
    {
        return in_array($method, [
            'payment_confirmation',
            'payment_success',
            'konfirmasi',
            'konfirmasi_pembayaran',
            'confirmation',
            'paid',
        ], true);
    }

    private function isBillNoticeMethod(string $method): bool
    {
        return in_array($method, [
            'bill_update',
            'edit_tagihan',
            'edit_nominal',
            'nama_tagihan',
            'exp_update',
            'expire_update',
            'expired_update',
            'ganti_expired',
            'tanggal_expired',
            'exp',
        ], true);
    }
}
