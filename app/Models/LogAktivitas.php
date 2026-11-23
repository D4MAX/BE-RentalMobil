<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogAktivitas extends Model
{
    protected $table = 'log_aktivitas';
    protected $primaryKey = 'id_log';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['waktu' => 'datetime'];
    }

    public function akun()
    {
        return $this->belongsTo(Akun::class, 'id_akun', 'id_akun');
    }

    /** Catat aksi. $pelaku null = Sistem. */
    public static function catat(string $aksi, ?Akun $pelaku = null, ?string $objek = null, ?int $idObjek = null): self
    {
        return static::create([
            'id_akun' => $pelaku?->id_akun,
            'pelaku' => $pelaku === null ? 'sistem' : ($pelaku->isAdmin() ? 'admin' : 'pelanggan'),
            'aksi' => $aksi,
            'objek' => $objek,
            'id_objek' => $idObjek,
            'waktu' => now(),
        ]);
    }
}
