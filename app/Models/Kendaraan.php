<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Kendaraan extends Model
{
    use SoftDeletes; // hapus = tandai deleted_at; lihat SampahService dan tempat sampah /admin/sampah

    protected $table = 'kendaraan';
    protected $primaryKey = 'id_kendaraan';
    protected $guarded = [];

    protected static function booted(): void
    {
        // km_awal = kilometer saat unit diinput (default 0): patokan servis berkala sebelum ada servis selesai
        static::creating(function (self $unit) {
            $unit->km_awal ??= (int) ($unit->km_terakhir ?? 0);
        });
    }

    public function tipe()
    {
        return $this->belongsTo(TipeKendaraan::class, 'id_tipe', 'id_tipe')->withTrashed(); // unit di tempat sampah bisa bertipe yang juga di sampah
    }

    public function detail()
    {
        return $this->hasOne(DetailKendaraan::class, 'id_kendaraan', 'id_kendaraan');
    }

    public function pemesanan()
    {
        return $this->hasMany(Pemesanan::class, 'id_kendaraan', 'id_kendaraan');
    }
}
