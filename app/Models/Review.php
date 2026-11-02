<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $table = 'review';
    protected $primaryKey = 'id_review';
    protected $fillable = ['id_pemesanan', 'rating', 'komentar', 'tgl_review'];

    protected function casts(): array
    {
        return ['tgl_review' => 'date:Y-m-d'];
    }

    public function pemesanan()
    {
        return $this->belongsTo(Pemesanan::class, 'id_pemesanan', 'id_pemesanan');
    }
}