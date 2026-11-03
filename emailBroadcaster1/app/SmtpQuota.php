<?php

/**
 * Kuota kirim per akun SMTP berbasis file (tanpa Redis/DB ekstra).
 * Default: 20 pesan / akun / jendela 10 menit.
 */
class SmtpQuota
{
    private string $path;
    private int $limit;
    private int $windowSeconds;

    public function __construct(?string $storageDir = null)
    {
        $root = $storageDir ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage');
        if (!is_dir($root)) {
            @mkdir($root, 0775, true);
        }
        $this->path = $root . DIRECTORY_SEPARATOR . 'smtp_quota.json';
        $this->limit = Config::smtpQuotaPerAccount();
        $this->windowSeconds = Config::smtpQuotaWindowSeconds();
    }

    /**
     * @return array{window_start:int,counts:array<string,int>,limit:int,window_seconds:int}
     */
    public function snapshot(): array
    {
        $state = $this->loadFresh();
        return [
            'window_start' => $state['window_start'],
            'counts' => $state['counts'],
            'limit' => $this->limit,
            'window_seconds' => $this->windowSeconds,
        ];
    }

    public function remaining(string $accountId): int
    {
        $state = $this->loadFresh();
        $used = (int) ($state['counts'][$accountId] ?? 0);
        return max(0, $this->limit - $used);
    }

    public function record(string $accountId, int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }
        $state = $this->loadFresh();
        $state['counts'][$accountId] = (int) ($state['counts'][$accountId] ?? 0) + $n;
        $this->save($state);
    }

    /**
     * @return array{window_start:int,counts:array<string,int>}
     */
    private function loadFresh(): array
    {
        $now = time();
        $state = $this->read();
        $start = (int) ($state['window_start'] ?? 0);
        if ($start <= 0 || ($now - $start) >= $this->windowSeconds) {
            $state = [
                'window_start' => $now,
                'counts' => [],
            ];
            $this->save($state);
        }
        if (!isset($state['counts']) || !is_array($state['counts'])) {
            $state['counts'] = [];
        }
        return $state;
    }

    /**
     * @return array{window_start?:int,counts?:array<string,int>}
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array{window_start:int,counts:array<string,int>} $state
     */
    private function save(array $state): void
    {
        file_put_contents(
            $this->path,
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
