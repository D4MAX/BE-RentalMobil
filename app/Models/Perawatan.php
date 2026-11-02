<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Perawatan extends Model
{
    protected $table = 'perawatan';
    protected $primaryKey = 'id_perawatan';
    protected $fillable = [
        'id_kendaraan', 'jenis_servis', 'tingkat_servis', 'tgl_perawatan', 'km_saat_ini',
        'bengkel', 'biaya', 'catatan', 'status', 'km_berikutnya', 'tgl_berikutnya',
    ];

    protected function casts(): array
    {
        return [
            'tgl_perawatan' => 'date:Y-m-d',
            'tgl_berikutnya' => 'date:Y-m-d',
        ];
    }

    public function kendaraan()
    {
        return $this->belongsTo(Kendaraan::class, 'id_kendaraan', 'id_kendaraan');
    }

    public function detail()
    {
        return $this->hasMany(DetailPerawatan::class, 'id_perawatan', 'id_perawatan');
    }
}