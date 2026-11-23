<?php

namespace App\Services\Pembayaran;

use RuntimeException;

/** Tanpa jaringan: dipakai saat PEMBAYARAN_GATEWAY=palsu (bawaan) dan APP_ENV=testing. */
class PembayaranGatewayPalsu implements PembayaranGateway
{
    /** Pencatat untuk tes */
    public array $transaksiDibuat = [];

    public array $refundDicatat = [];

    public array $batalDicatat = [];

    public bool $gagalBuat = false;

    public bool $gagalBatal = false;

    public bool $gagalRefund = false;

    public function buatTransaksi(string $orderId, int $jumlah, array $pembeli, int $kedaluwarsaMenit): TransaksiPembayaran
    {
        if ($this->gagalBuat) {
            throw new RuntimeException('Gateway palsu: gagal membuat transaksi');
        }
        $this->transaksiDibuat[] = compact('orderId', 'jumlah', 'kedaluwarsaMenit');

        return new TransaksiPembayaran($orderId, 'snap-palsu-'.$orderId);
    }

    public function refund(string $orderId, int $jumlah, string $alasan): void
    {
        if ($this->gagalRefund) {
            throw new RuntimeException('Gateway palsu: gagal refund');
        }
        $this->refundDicatat[] = compact('orderId', 'jumlah', 'alasan');
    }

    public function batalkanTransaksi(string $orderId): void
    {
        if ($this->gagalBatal) {
            throw new RuntimeException('Gateway palsu: gagal membatalkan transaksi');
        }
        $this->batalDicatat[] = $orderId;
    }
}
