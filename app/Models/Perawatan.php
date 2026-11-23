<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Perawatan extends Model
{
    protected $table = 'perawatan';
    protected $primaryKey = 'id_perawatan';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['tgl_perawatan' => 'date:Y-m-d', 'tgl_berikutnya' => 'date:Y-m-d'];
    }

    public function kendaraan()
    {
        return $this->belongsTo(Kendaraan::class, 'id_kendaraan', 'id_kendaraan')->withTrashed(); // riwayat tetap menampilkan unit yang di-soft delete
    }

    public function pemesananAsal()
    {
        return $this->belongsTo(Pemesanan::class, 'id_pemesanan_asal', 'id_pemesanan');
    }

    public function detail()
    {
        return $this->hasMany(DetailPerawatan::class, 'id_perawatan', 'id_perawatan');
    }
}
