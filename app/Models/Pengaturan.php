<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pengaturan extends Model
{
    protected $table = 'pengaturan';
    protected $primaryKey = 'kunci';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    public static function ambil(string $kunci, ?string $default = null): ?string
    {
        return static::query()->whereKey($kunci)->value('nilai') ?? $default;
    }
}
