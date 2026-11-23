<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $table = 'review';
    protected $primaryKey = 'id_review';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['tgl_review' => 'date:Y-m-d', 'tampil_di_katalog' => 'boolean'];
    }

    public function pemesanan()
    {
        return $this->belongsTo(Pemesanan::class, 'id_pemesanan', 'id_pemesanan');
    }
}
