<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pembayaran extends Model
{
    protected $table = 'pembayaran';
    protected $primaryKey = 'id_pembayaran';
    protected $guarded = [];

    /**
     * Channel yang boleh di-refund lewat API Midtrans (dokumentasi "Refund Transactions", dibaca 2026-11-13).
     * Virtual account (bank_transfer, echannel) dan gerai (cstore) tidak mendukung refund: ditransfer admin.
     */
    public const CHANNEL_REFUND_MIDTRANS = ['credit_card', 'gopay', 'shopeepay', 'dana', 'ovo', 'qris', 'kredivo', 'akulaku'];

    /**
     * Rekening tujuan refund wajib diisi pelanggan hanya untuk virtual account dan gerai. Channel lain tidak perlu:
     * kalau refund lewat API gagal, admin menghubungi pelanggan di luar sistem dan menulis hasilnya di catatan admin.
     */
    public const CHANNEL_WAJIB_REKENING = ['bank_transfer', 'echannel', 'cstore'];

    public static function wajibRekening(?string $paymentType): bool
    {
        return in_array($paymentType, self::CHANNEL_WAJIB_REKENING, true);
    }

    /** midtrans bila channel mendukung refund lewat API, selain itu transfer_admin (termasuk channel tak dikenal atau kosong). */
    public static function caraRefundUntuk(?string $paymentType): string
    {
        return in_array($paymentType, self::CHANNEL_REFUND_MIDTRANS, true) ? 'midtrans' : 'transfer_admin';
    }

    protected function casts(): array
    {
        return ['tgl_bayar' => 'datetime', 'refund_selesai' => 'boolean'];
    }

    public function pemesanan()
    {
        return $this->belongsTo(Pemesanan::class, 'id_pemesanan', 'id_pemesanan');
    }
}
