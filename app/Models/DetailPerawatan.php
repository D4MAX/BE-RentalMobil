<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetailPerawatan extends Model
{
    protected $table = 'detail_perawatan';
    protected $primaryKey = 'id_detail';
    protected $guarded = [];
}
