<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PengembalianKondisi extends Model
{
    protected $table = 'pengembalian_kondisi';
    protected $primaryKey = 'id_kondisi';
    protected $guarded = [];
}
