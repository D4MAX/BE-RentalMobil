<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kendaraan extends Model
{
    protected $table = 'kendaraan';
    protected $primaryKey = 'id_kendaraan';
    protected $fillable = [
        'plat_nomor', 'merk', 'tipe', 'tahun', 'warna',
        'harga_sewa_per_hari', 'km_terakhir', 'status',
    ];

    public function detail()
    {
        return $this->hasOne(DetailKendaraan::class, 'id_kendaraan', 'id_kendaraan');
    }

    public function pemesanan()
    {
        return $this->hasMany(Pemesanan::class, 'id_kendaraan', 'id_kendaraan');
    }

    public function perawatan()
    {
        return $this->hasMany(Perawatan::class, 'id_kendaraan', 'id_kendaraan');
    }
}