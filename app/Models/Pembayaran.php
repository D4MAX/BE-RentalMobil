<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pembayaran extends Model
{
    protected $table = 'pembayaran';
    protected $primaryKey = 'id_pembayaran';
    protected $fillable = ['id_pemesanan', 'no_pembayaran', 'metode', 'jumlah', 'tgl_bayar', 'status'];

    protected function casts(): array
    {
        return ['tgl_bayar' => 'datetime'];
    }

    public function pemesanan()
    {
        return $this->belongsTo(Pemesanan::class, 'id_pemesanan', 'id_pemesanan');
    }
}