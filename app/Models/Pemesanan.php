<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pemesanan extends Model
{
    protected $table = 'pemesanan';
    protected $primaryKey = 'id_pemesanan';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tgl_pesan' => 'date:Y-m-d',
            'tgl_mulai' => 'date:Y-m-d',
            'tgl_kembali' => 'date:Y-m-d',
            'batas_pembayaran' => 'datetime',
            'setuju_syarat' => 'boolean',
        ];
    }

    public function getNomorAttribute(): string
    {
        return 'PSN-'.str_pad((string) $this->id_pemesanan, 4, '0', STR_PAD_LEFT);
    }

    /** Pengajuan pembatalan: hanya dikonfirmasi dan paling lambat H-1 (tanggal kalender zona aplikasi, Asia/Jakarta). */
    public function bisaAjukanPembatalan(): bool
    {
        return $this->status === 'dikonfirmasi' && ! $this->lewatBatasH1();
    }

    /** Hari ini (WIB) sudah lewat tgl_mulai - 1 hari. */
    public function lewatBatasH1(): bool
    {
        return now()->toDateString() > $this->tgl_mulai->copy()->subDay()->toDateString();
    }

    public function bisaBayar(): bool
    {
        return $this->status === 'menunggu_pembayaran' && $this->batas_pembayaran?->gt(now());
    }

    /** Tombol di tampilan user (Spek API bagian 6): dihitung dari status dan tanggal. */
    public function aksiUser(): array
    {
        return [
            'bisa_bayar' => (bool) $this->bisaBayar(),
            'bisa_batalkan' => $this->status === 'menunggu_pembayaran',
            'bisa_ajukan_pembatalan' => $this->bisaAjukanPembatalan(),
            'bisa_review' => $this->status === 'selesai' && ! $this->review,
            'bisa_unduh_nota' => in_array($this->status, ['dikonfirmasi', 'berjalan', 'selesai', 'pengajuan_pembatalan'], true),
            // Perpanjang sewa: tombol membuka Katalog dengan tanggal mulai tgl_kembali + 1 hari (dihitung tampilan)
            'bisa_perpanjang' => $this->status === 'berjalan',
            'bisa_pesan_lagi' => in_array($this->status, ['selesai', 'dibatalkan'], true),
        ];
    }

    public function aksiUtama(): ?string
    {
        $aksi = $this->aksiUser();

        return match (true) {
            $aksi['bisa_bayar'] => 'bayar',
            $aksi['bisa_ajukan_pembatalan'] => 'ajukan_pembatalan',
            $aksi['bisa_review'] => 'beri_review',
            $aksi['bisa_pesan_lagi'] => 'pesan_lagi',
            default => null,
        };
    }

    public function serahTerima()
    {
        return $this->hasOne(SerahTerima::class, 'id_pemesanan', 'id_pemesanan');
    }

    public function jaminan()
    {
        return $this->hasMany(Jaminan::class, 'id_pemesanan', 'id_pemesanan');
    }

    public function pengembalian()
    {
        return $this->hasOne(Pengembalian::class, 'id_pemesanan', 'id_pemesanan');
    }

    public function pembayaran()
    {
        return $this->hasMany(Pembayaran::class, 'id_pemesanan', 'id_pemesanan');
    }

    public function pelanggan()
    {
        return $this->belongsTo(Pelanggan::class, 'id_pelanggan', 'id_pelanggan');
    }

    public function tipe()
    {
        return $this->belongsTo(TipeKendaraan::class, 'id_tipe', 'id_tipe');
    }

    public function kendaraan()
    {
        return $this->belongsTo(Kendaraan::class, 'id_kendaraan', 'id_kendaraan')->withTrashed(); // riwayat tetap menampilkan unit yang di-soft delete
    }

    public function review()
    {
        return $this->hasOne(Review::class, 'id_pemesanan', 'id_pemesanan');
    }
}
