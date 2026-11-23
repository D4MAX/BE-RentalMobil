<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class Akun extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'akun';
    protected $primaryKey = 'id_akun';
    protected $fillable = ['nama', 'email', 'foto', 'password', 'role', 'aktif', 'email_terverifikasi_at', 'wajib_ganti_password'];
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'aktif' => 'boolean',
            'wajib_ganti_password' => 'boolean',
            'email_terverifikasi_at' => 'datetime',
        ];
    }

    /** Masa berlaku token login menurut peran (menit), dari config rental.token_admin_menit dan token_user_menit; minimal 1. */
    public function masaTokenMenit(): int
    {
        return max(1, (int) config($this->isAdmin() ? 'rental.token_admin_menit' : 'rental.token_user_menit'));
    }

    public function pelanggan()
    {
        return $this->hasOne(Pelanggan::class, 'id_akun', 'id_akun');
    }

    public function token()
    {
        return $this->hasMany(TokenAkun::class, 'id_akun', 'id_akun');
    }

    public function getNamaTampilAttribute(): string
    {
        return $this->pelanggan?->nama ?? $this->nama ?? $this->email;
    }

    /** URL foto profil di disk publik; null bila belum ada. */
    public function getFotoUrlAttribute(): ?string
    {
        return $this->foto ? Storage::disk('public')->url($this->foto) : null;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
