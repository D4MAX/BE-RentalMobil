<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokenAkun extends Model
{
    protected $table = 'token_akun';
    protected $primaryKey = 'id_token';
    protected $guarded = [];

    public function akun()
    {
        return $this->belongsTo(Akun::class, 'id_akun', 'id_akun');
    }

    protected function casts(): array
    {
        return ['kedaluwarsa_at' => 'datetime', 'dipakai_at' => 'datetime'];
    }
}
