<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetailPerawatan extends Model
{
    protected $table = 'detail_perawatan';
    protected $primaryKey = 'id_detail';
    protected $fillable = ['id_perawatan', 'komponen', 'keterangan'];

    public function perawatan()
    {
        return $this->belongsTo(Perawatan::class, 'id_perawatan', 'id_perawatan');
    }
}