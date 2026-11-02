<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Akun extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'akun';
    protected $primaryKey = 'id_akun';
    protected $fillable = ['username', 'password', 'role'];
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public function pelanggan()
    {
        return $this->hasOne(Pelanggan::class, 'id_akun', 'id_akun');
    }
}