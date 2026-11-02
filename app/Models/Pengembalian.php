<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pengembalian extends Model
{
    protected $table = 'pengembalian';
    protected $primaryKey = 'id_pengembalian';
    protected $fillable = [
        'id_pemesanan', 'tgl_kembali', 'kondisi_mobil', 'hari_terlambat',
        'denda_keterlambatan', 'denda_kerusakan', 'biaya_tambahan', 'total_denda',
    ];

    protected function casts(): array
    {
        return ['tgl_kembali' => 'date:Y-m-d'];
    }

    public function pemesanan()
    {
        return $this->belongsTo(Pemesanan::class, 'id_pemesanan', 'id_pemesanan');
    }
}