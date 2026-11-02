<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pemesanan extends Model
{
    protected $table = 'pemesanan';
    protected $primaryKey = 'id_pemesanan';
    protected $fillable = [
        'id_pelanggan', 'id_kendaraan', 'id_admin', 'jenis_pesanan',
        'tgl_pesan', 'tgl_mulai', 'tgl_selesai', 'total_biaya', 'status',
    ];

    protected function casts(): array
    {
        return [
            'tgl_pesan' => 'date:Y-m-d',
            'tgl_mulai' => 'date:Y-m-d',
            'tgl_selesai' => 'date:Y-m-d',
        ];
    }

    public function pelanggan()
    {
        return $this->belongsTo(Pelanggan::class, 'id_pelanggan', 'id_pelanggan');
    }

    public function kendaraan()
    {
        return $this->belongsTo(Kendaraan::class, 'id_kendaraan', 'id_kendaraan');
    }

    public function admin()
    {
        return $this->belongsTo(Akun::class, 'id_admin', 'id_akun');
    }

    public function pembayaran()
    {
        return $this->hasOne(Pembayaran::class, 'id_pemesanan', 'id_pemesanan');
    }

    public function pengembalian()
    {
        return $this->hasOne(Pengembalian::class, 'id_pemesanan', 'id_pemesanan');
    }

    public function review()
    {
        return $this->hasOne(Review::class, 'id_pemesanan', 'id_pemesanan');
    }
}