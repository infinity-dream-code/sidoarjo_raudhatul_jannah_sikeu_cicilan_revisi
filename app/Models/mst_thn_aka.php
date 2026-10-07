<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class mst_thn_aka extends Model
{
    protected $connection = "DATA_MYSQL";

    protected $table = "mst_thn_aka";

    protected $primaryKey = "urut";

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = [
        "urut",
        "thn_aka",
    ];

    /** @var array<string, self> */
    private static array $ensureMemo = [];

    public static function resetEnsureMemo(): void
    {
        self::$ensureMemo = [];
    }

    /**
     * Ambil tahun akademik; buat otomatis di master jika belum ada.
     */
    public static function ensure(string $thnAka): ?self
    {
        $thnAka = trim($thnAka);
        if ($thnAka === '') {
            return null;
        }

        $memoKey = strtoupper($thnAka);
        if (isset(self::$ensureMemo[$memoKey])) {
            return self::$ensureMemo[$memoKey];
        }

        $existing = static::query()
            ->whereRaw('UPPER(TRIM(thn_aka)) = ?', [$memoKey])
            ->first();

        if ($existing) {
            self::$ensureMemo[$memoKey] = $existing;

            return $existing;
        }

        $nextUrut = (int) (static::query()->max('urut') ?? 0) + 1;

        $created = new static();
        $created->urut = $nextUrut;
        $created->thn_aka = $thnAka;
        $created->save();
        $created = $created->fresh() ?? $created;

        Log::info('mst_thn_aka.auto_create', [
            'urut' => $created->urut,
            'thn_aka' => $created->thn_aka,
        ]);

        self::$ensureMemo[$memoKey] = $created;

        return $created;
    }

    public static function getMstThnAkaAttributes(): array|object
    {
        return static::select(["thn_aka"])
            ->whereNotNull("thn_aka")
            ->distinct()
            ->orderBy("thn_aka", "desc")
            ->get();
    }
}
