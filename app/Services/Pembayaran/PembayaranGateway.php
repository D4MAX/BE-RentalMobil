<?php

namespace App\Services\Pembayaran;

/** Penerbit tagihan dan refund online. Implementasi: Midtrans asli atau gateway palsu (lokal dan testing). */
interface PembayaranGateway
{
    /**
     * @param  array{nama: string, email: string, no_hp: ?string}  $pembeli
     * @param  int  $kedaluwarsaMenit  masa berlaku token; harus berakhir sekitar batas_pembayaran pesanan
     *
     * @throws \Throwable bila tagihan gagal diterbitkan
     */
    public function buatTransaksi(string $orderId, int $jumlah, array $pembeli, int $kedaluwarsaMenit): TransaksiPembayaran;

    /**
     * Refund penuh pembayaran yang sudah lunas. Hanya channel di Pembayaran::CHANNEL_REFUND_MIDTRANS.
     *
     * @throws \Throwable bila refund ditolak atau gateway tidak terjangkau
     */
    public function refund(string $orderId, int $jumlah, string $alasan): void;

    /**
     * Batalkan transaksi yang belum dibayar agar tidak bisa dibayar lagi (best effort: pemanggil mengabaikan galatnya).
     *
     * @throws \Throwable
     */
    public function batalkanTransaksi(string $orderId): void;
}
