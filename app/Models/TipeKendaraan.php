<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TipeKendaraan extends Model
{
    use SoftDeletes; // hapus = tandai deleted_at; lihat SampahService dan tempat sampah /admin/sampah

    protected $table = 'tipe_kendaraan';
    protected $primaryKey = 'id_tipe';
    protected $guarded = [];

    public function foto()
    {
        return $this->hasMany(TipeFoto::class, 'id_tipe', 'id_tipe')->orderBy('urutan');
    }

    public function kendaraan()
    {
        return $this->hasMany(Kendaraan::class, 'id_tipe', 'id_tipe');
    }

    public function pemesanan()
    {
        return $this->hasMany(Pemesanan::class, 'id_tipe', 'id_tipe');
    }
}
