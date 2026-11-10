<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentEmailLog extends Model
{
    protected $table = 'payment_email_logs';

    protected $fillable = [
        'aa',
        'billpaid_snapshot',
        'payment_amount',
        'queued_at',
        'broadcaster_id',
    ];

    protected $casts = [
        'billpaid_snapshot' => 'integer',
        'payment_amount' => 'integer',
        'queued_at' => 'datetime',
    ];

    public static function lastSnapshot(string $aa): int
    {
        return (int) static::query()
            ->where('aa', $aa)
            ->max('billpaid_snapshot');
    }
}
