<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WhatsappGateway
{
    public static function clientId(): string
    {
        $id = trim((string) config('whatsapp.client_id', ''));
        if ($id === '') {
            $id = 'sidoarjo_raudlatul_j';
        }

        // Kolom client di farrelep_broadcaster.whatsapp_queue = varchar(20).
        return substr($id, 0, 20);
    }

    public static function enqueue(string $phone, string $message, int|string $logId): mixed
    {
        $client = self::clientId();

        try {
            $result = DB::connection('mysql_wa')->select(
                'CALL new_whatsapp_queue(:client, :logId, :phone, :message)',
                [
                    'client' => $client,
                    'logId' => $logId,
                    'phone' => $phone,
                    'message' => $message,
                ]
            );
        } catch (Throwable $e) {
            Log::channel('whatsapp')->error('queue enqueue failed', [
                'client' => $client,
                'log_id' => $logId,
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                'Gagal mengantrekan pesan WhatsApp: ' . $e->getMessage(),
                0,
                $e
            );
        }

        Log::channel('whatsapp')->info('queue enqueue', [
            'client' => $client,
            'log_id' => $logId,
            'phone' => $phone,
            'result' => $result,
        ]);

        return $result;
    }
}
