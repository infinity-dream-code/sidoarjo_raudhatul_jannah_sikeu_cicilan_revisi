<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentEmailLog extends Model
{
    protected $table = 'payment_email_logs';

    protected $fillable = [
        'aa',
        'billpaid_snapshot',
        'installment_snapshot',
        'payment_amount',
        'queued_at',
        'broadcaster_id',
    ];

    protected $casts = [
        'billpaid_snapshot' => 'integer',
        'installment_snapshot' => 'integer',
        'payment_amount' => 'integer',
        'queued_at' => 'datetime',
    ];

    public static function lastSnapshot(string $aa): int
    {
        return (int) static::query()
            ->where('aa', $aa)
            ->max('billpaid_snapshot');
    }

    public static function lastInstallmentSnapshot(string $aa): int
    {
        return (int) static::query()
            ->where('aa', $aa)
            ->max('installment_snapshot');
    }

    /**
     * @return array{billpaid:int,installment:int}
     */
    public static function lastState(string $aa): array
    {
        $row = static::query()
            ->where('aa', $aa)
            ->orderByDesc('billpaid_snapshot')
            ->orderByDesc('installment_snapshot')
            ->orderByDesc('id')
            ->first(['billpaid_snapshot', 'installment_snapshot']);

        return [
            'billpaid' => (int) ($row->billpaid_snapshot ?? 0),
            'installment' => (int) ($row->installment_snapshot ?? 0),
        ];
    }
}
