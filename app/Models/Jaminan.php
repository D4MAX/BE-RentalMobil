<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Jaminan extends Model
{
    protected $table = 'jaminan';
    protected $primaryKey = 'id_jaminan';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['diterima' => 'boolean', 'dikembalikan' => 'boolean'];
    }
}
