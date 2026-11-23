<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SerahTerima extends Model
{
    protected $table = 'serah_terima';
    protected $primaryKey = 'id_serah_terima';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['tgl_serah' => 'datetime', 'foto_kondisi' => 'array'];
    }

    public function kendaraan()
    {
        return $this->belongsTo(Kendaraan::class, 'id_kendaraan', 'id_kendaraan')->withTrashed(); // riwayat tetap menampilkan unit yang di-soft delete
    }

    public function admin()
    {
        return $this->belongsTo(Akun::class, 'id_admin', 'id_akun');
    }
}
