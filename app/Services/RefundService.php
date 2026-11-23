<?php

namespace App\Services;

use App\Models\LogAktivitas;
use App\Models\Pembayaran;
use App\Services\Pembayaran\PembayaranGateway;
use Throwable;

/**
 * Refund penuh lewat API Midtrans (QRIS dan channel lain yang didukung, lihat Pembayaran::CHANNEL_REFUND_MIDTRANS).
 * Dipanggil SESUDAH commit. Tidak pernah melempar galat: bila Midtrans menolak atau tidak terjangkau, refund_cara
 * dialihkan ke transfer_admin dan dicatat di log. Admin lalu menghubungi pelanggan di luar sistem (tidak ada WhatsApp
 * di dalam sistem), mentransfer, dan menulis hasilnya di catatan admin sebelum menekan refund-selesai.
 */
class RefundService
{
    public function __construct(private PembayaranGateway $gateway) {}

    /** @return bool true bila Midtrans menerima refund; false bila dialihkan ke transfer_admin */
    public function lewatMidtrans(int $idPembayaran, string $orderId, int $jumlah, string $alasan): bool
    {
        try {
            $this->gateway->refund($orderId, $jumlah, $alasan);
            Pembayaran::whereKey($idPembayaran)->update(['status' => 'dikembalikan', 'refund_selesai' => true]);
            LogAktivitas::catat('Dana dikembalikan penuh lewat Midtrans', null, 'pembayaran', $idPembayaran);

            return true;
        } catch (Throwable $e) {
            report($e);
        }

        try {
            Pembayaran::whereKey($idPembayaran)->update(['refund_cara' => 'transfer_admin', 'refund_selesai' => false]);
            LogAktivitas::catat('Refund otomatis lewat Midtrans gagal, dialihkan ke transfer admin', null, 'pembayaran', $idPembayaran);
        } catch (Throwable $e) {
            report($e); // pencatatan pun gagal: tetap tidak melempar; dana masih lunas dengan refund_cara midtrans
        }

        return false;
    }
}
