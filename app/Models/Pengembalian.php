<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pengembalian extends Model
{
    protected $table = 'pengembalian';
    protected $primaryKey = 'id_pengembalian';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['tgl_kembali' => 'date:Y-m-d', 'disetujui_penyewa' => 'boolean'];
    }

    public function kondisi()
    {
        return $this->hasMany(PengembalianKondisi::class, 'id_pengembalian', 'id_pengembalian');
    }

    public function biayaTambahan()
    {
        return $this->hasMany(BiayaTambahan::class, 'id_pengembalian', 'id_pengembalian');
    }

    public function foto()
    {
        return $this->hasMany(PengembalianFoto::class, 'id_pengembalian', 'id_pengembalian');
    }
}
