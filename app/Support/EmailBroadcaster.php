<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class EmailBroadcaster
{
    /**
     * Antri satu atau banyak email billing ke emailBroadcaster.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{queued:int,failed:int,data:array<int,mixed>,errors:array<int,mixed>}
     */
    public static function enqueueBilling(array $messages): array
    {
        $messages = array_values(array_filter($messages, static fn ($row) => is_array($row)));
        if ($messages === []) {
            return [
                'queued' => 0,
                'failed' => 0,
                'data' => [],
                'errors' => [],
            ];
        }

        $base = rtrim((string) config('email_broadcaster.url', ''), '/');
        if ($base === '') {
            throw new RuntimeException('EMAIL_BROADCASTER_URL belum dikonfigurasi.');
        }

        $url = $base . '/api/billing.php';
        $timeout = max(5, (int) config('email_broadcaster.timeout', 30));
        $token = trim((string) config('email_broadcaster.token', ''));

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
            $headers['X-Api-Token'] = $token;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->post($url, ['messages' => $messages]);
        } catch (Throwable $e) {
            Log::error('email broadcaster request failed', [
                'url' => $url,
                'count' => count($messages),
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                'Gagal menghubungi Email Broadcaster: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $body = $response->json();
        if (!is_array($body)) {
            $body = [];
        }

        if (!$response->successful()) {
            $message = (string) ($body['error'] ?? $body['message'] ?? ('HTTP ' . $response->status()));
            Log::error('email broadcaster enqueue rejected', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $body,
            ]);
            throw new RuntimeException('Email Broadcaster menolak antrian: ' . $message);
        }

        $queued = (int) ($body['queued'] ?? count($body['data'] ?? []));
        $failed = (int) ($body['failed'] ?? count($body['errors'] ?? []));

        Log::info('email broadcaster enqueue', [
            'url' => $url,
            'queued' => $queued,
            'failed' => $failed,
        ]);

        return [
            'queued' => $queued,
            'failed' => $failed,
            'data' => is_array($body['data'] ?? null) ? $body['data'] : [],
            'errors' => is_array($body['errors'] ?? null) ? $body['errors'] : [],
        ];
    }

    /**
     * Proses antrian PENDING sekarang (tanpa tunggu cron).
     *
     * @return array<string, mixed>
     */
    public static function processNow(): array
    {
        $base = rtrim((string) config('email_broadcaster.url', ''), '/');
        if ($base === '') {
            throw new RuntimeException('EMAIL_BROADCASTER_URL belum dikonfigurasi.');
        }

        $url = $base . '/api/process-now.php';
        $timeout = max(10, (int) config('email_broadcaster.timeout', 30));
        $token = trim((string) config('email_broadcaster.token', ''));

        $headers = [
            'Accept' => 'application/json',
        ];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
            $headers['X-Api-Token'] = $token;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->post($url);
        } catch (Throwable $e) {
            Log::warning('email broadcaster process-now failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                'Gagal memproses antrian email sekarang: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $body = $response->json();
        if (!is_array($body)) {
            $body = [];
        }

        if (!$response->successful()) {
            $message = (string) ($body['error'] ?? $body['message'] ?? ('HTTP ' . $response->status()));
            throw new RuntimeException('Process-now ditolak: ' . $message);
        }

        return $body;
    }
}
