<?php

namespace Fmipa\EipKlien;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Jejak "Masuk sebagai" (ADR 0019 bagian D). Baris tidak pernah dihapus dan
 * setelah dibuat hanya `selesai_at` / `cara_selesai` yang boleh diisi.
 *
 * @property int $id
 * @property string $pelaku_id
 * @property string $pelaku_nama
 * @property string $target_id
 * @property string $target_nama
 * @property string $alasan
 * @property Carbon $mulai_at
 * @property Carbon|null $selesai_at
 * @property string|null $cara_selesai
 * @property string|null $ip
 * @property string|null $user_agent
 */
class ImpersonasiLog extends Model
{
    public const CARA = ['kembali', 'kedaluwarsa', 'keluar'];

    protected $table = 'impersonasi_log';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mulai_at' => 'datetime', 'selesai_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $log) {
            $lain = array_diff(array_keys($log->getDirty()), ['selesai_at', 'cara_selesai']);
            if ($lain !== [] || $log->getOriginal('selesai_at') !== null) {
                throw new LogicException('Jejak masuk sebagai tidak boleh diubah.');
            }
        });

        static::deleting(fn () => throw new LogicException('Jejak masuk sebagai tidak boleh dihapus.'));
    }

    public function selesai(): bool
    {
        return $this->selesai_at !== null;
    }
}
