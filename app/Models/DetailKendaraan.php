<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetailKendaraan extends Model
{
    protected $table = 'detail_kendaraan';
    protected $primaryKey = 'id_detail_kendaraan';
    protected $guarded = [];

    /** Unit pemilik detail (ikut aturan soft delete: unit di tempat sampah tidak terbaca lewat relasi ini). */
    public function kendaraan()
    {
        return $this->belongsTo(Kendaraan::class, 'id_kendaraan', 'id_kendaraan');
    }

    protected function casts(): array
    {
        return ['masa_berlaku_stnk' => 'date:Y-m-d'];
    }
}
