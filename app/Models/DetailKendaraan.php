<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetailKendaraan extends Model
{
    protected $table = 'detail_kendaraan';
    protected $primaryKey = 'id_detail_kendaraan';
    protected $fillable = [
        'id_kendaraan', 'nama_pemilik', 'status_kepemilikan', 'no_rangka', 'no_mesin',
        'no_bpkb', 'masa_berlaku_stnk', 'tgl_pembelian', 'harga_beli', 'catatan',
    ];

    protected function casts(): array
    {
        return [
            'masa_berlaku_stnk' => 'date:Y-m-d',
            'tgl_pembelian' => 'date:Y-m-d',
        ];
    }

    public function kendaraan()
    {
        return $this->belongsTo(Kendaraan::class, 'id_kendaraan', 'id_kendaraan');
    }
}