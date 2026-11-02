<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pelanggan extends Model
{
    protected $table = 'pelanggan';
    protected $primaryKey = 'id_pelanggan';
    protected $fillable = ['id_akun', 'nama', 'no_hp', 'no_identitas', 'alamat'];

    public function akun()
    {
        return $this->belongsTo(Akun::class, 'id_akun', 'id_akun');
    }

    public function pemesanan()
    {
        return $this->hasMany(Pemesanan::class, 'id_pelanggan', 'id_pelanggan');
    }
}