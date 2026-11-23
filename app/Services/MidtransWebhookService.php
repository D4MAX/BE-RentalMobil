<?php

namespace App\Services;

use App\Models\LogAktivitas;
use App\Models\Pembayaran;
use App\Models\Pemesanan;
use App\Models\Pengaturan;
use App\Models\TipeKendaraan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** POST /webhook/midtrans (Spek API bagian 7). Idempoten; seluruh perubahan status dalam satu transaksi. */
class MidtransWebhookService
{
    public function __construct(
        private KetersediaanService $ketersediaan,
        private PemesananService $pemesanan,
        private RefundService $refund,
    ) {}

    /** signature_key = sha512(order_id + status_code + gross_amount + ServerKey). Kunci kosong = semua ditolak. */
    public function tandaTanganValid(array $d): bool
    {
        $kunci = (string) config('midtrans.server_key');
        if ($kunci === '') {
            return false;
        }
        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $k) {
            if (! isset($d[$k]) || ! is_scalar($d[$k])) {
                return false;
            }
        }

        $seharusnya = hash('sha512', $d['order_id'].$d['status_code'].$d['gross_amount'].$kunci);

        return hash_equals($seharusnya, strtolower((string) $d['signature_key']));
    }

    public function proses(array $d): void
    {
        // Dicari di luar transaksi: pembacaan biasa pertama di dalam transaksi akan membuat snapshot
        // REPEATABLE READ yang basi untuk pemeriksaan sisa unit. Di dalam, kunci diambil lebih dulu.
        $idPemesanan = Pembayaran::where('order_id_midtrans', $d['order_id'])->where('jenis', 'sewa')->value('id_pemesanan');
        if (! $idPemesanan) {
            Log::info('Webhook Midtrans untuk order tak dikenal diabaikan', ['order_id' => $d['order_id']]);

            return;
        }

        $refund = DB::transaction(fn () => $this->dalamTransaksi($idPemesanan, $d), 3);

        if ($refund) {
            $this->refund->lewatMidtrans(...$refund, alasan: 'Unit tidak tersedia saat pembayaran diterima');
        }
    }

    /** @return array{0:int,1:string,2:int}|null id_pembayaran, order_id, jumlah bila refund Midtrans perlu dipanggil */
    private function dalamTransaksi(int $idPemesanan, array $d): ?array
    {
        // Urutan kunci: pesanan, pembayaran, (sopir), tipe
        $pemesanan = Pemesanan::query()->lockForUpdate()->find($idPemesanan);
        $bayar = Pembayaran::query()->where('order_id_midtrans', $d['order_id'])->lockForUpdate()->first();
        if (! $pemesanan || ! $bayar) {
            return null;
        }

        $status = $d['transaction_status'] ?? null;

        return match (true) {
            $status === 'settlement' => $this->lunas($pemesanan, $bayar, $d),
            // Kartu: capture baru berarti uang pasti masuk bila fraud_status accept (atau tidak dikirim). "challenge" ditahan
            // Midtrans untuk ditinjau; hasilnya datang sebagai settlement, deny, atau cancel.
            $status === 'capture' => ($d['fraud_status'] ?? 'accept') === 'accept'
                ? $this->lunas($pemesanan, $bayar, $d) : null,
            $status === 'expire' => $this->kedaluwarsa($pemesanan, $bayar),
            in_array($status, ['deny', 'cancel'], true) => $this->ditolak($bayar),
            in_array($status, ['refund', 'partial_refund', 'chargeback'], true) => $this->dilaporkan($bayar, $status),
            default => null, // pending dan status tak dikenal: tidak ada perubahan
        };
    }

    /** Refund atau chargeback yang terjadi di sisi Midtrans: dicatat saja, admin yang memutuskan tindak lanjutnya. */
    private function dilaporkan(Pembayaran $bayar, string $status): ?array
    {
        if ($bayar->status === 'dikembalikan') {
            return null; // refund yang diminta sistem sendiri: Midtrans hanya menggemakannya
        }

        LogAktivitas::catat(
            "Midtrans melaporkan {$status}, periksa di dashboard Midtrans dan tindak lanjuti manual",
            null, 'pembayaran', $bayar->id_pembayaran
        );

        return null;
    }

    private function lunas(Pemesanan $pemesanan, Pembayaran $bayar, array $d): ?array
    {
        if (in_array($bayar->status, ['lunas', 'dikembalikan'], true)) {
            return null; // notifikasi ulang
        }
        if ((int) round((float) ($d['gross_amount'] ?? 0)) !== (int) $bayar->jumlah) {
            Log::warning('Webhook Midtrans: nominal tidak cocok, diabaikan', ['order_id' => $d['order_id']]);

            return null;
        }

        // Kunci sopir dan tipe, lalu periksa ulang jatah di dalam transaksi
        if ($pemesanan->jenis_sewa === 'dengan_sopir') {
            Pengaturan::query()->whereKey('jumlah_sopir')->lockForUpdate()->first();
        }
        TipeKendaraan::query()->lockForUpdate()->find($pemesanan->id_tipe);

        $dihidupkan = $pemesanan->status === 'dibatalkan';

        // Dalam batas: unit masih ditahan pesanan ini, tidak perlu dihitung ulang.
        $dalamBatas = $pemesanan->status === 'menunggu_pembayaran' && $pemesanan->batas_pembayaran->gt(now());

        // Lewat batas (scheduler belum jalan) atau sudah dibatalkan karena kedaluwarsa: hidup lagi bila tanggal
        // belum lewat dan jatah masih ada. Pesanan dibatalkan user, atau sudah dikonfirmasi lewat pembayaran
        // lain (bayar dua kali), tidak pernah dihidupkan: uangnya dikembalikan.
        $bisaHidup = ($pemesanan->status === 'menunggu_pembayaran'
                || ($dihidupkan && $pemesanan->alasan_pembatalan === 'kedaluwarsa'))
            && $pemesanan->tgl_mulai->toDateString() >= now()->toDateString();

        $layak = $dalamBatas || ($bisaHidup && $this->jatahMasihAda($pemesanan));

        $metode = $d['payment_type'] ?? null;

        if ($layak) {
            $bayar->update(['status' => 'lunas', 'metode' => $metode, 'tgl_bayar' => now()]);
            Pembayaran::where('id_pemesanan', $pemesanan->id_pemesanan)->where('jenis', 'sewa')
                ->where('status', 'menunggu')->where('id_pembayaran', '!=', $bayar->id_pembayaran)->update(['status' => 'gagal']);
            $pemesanan->update(['status' => 'dikonfirmasi', 'alasan_pembatalan' => null]);
            LogAktivitas::catat(
                'Pembayaran berhasil, status Dikonfirmasi'.($dihidupkan ? ' (pesanan dihidupkan kembali)' : ''),
                null, 'pemesanan', $pemesanan->id_pemesanan
            );

            return null;
        }

        // Jatah sudah terpakai: pembayaran dibatalkan otomatis dan direfund penuh, tanpa pesanan baru
        if ($pemesanan->status === 'menunggu_pembayaran') {
            $this->pemesanan->batalkanTerkunci($pemesanan, 'kedaluwarsa', 'Pembayaran terlambat, pesanan dibatalkan otomatis');
        }
        $cara = Pembayaran::caraRefundUntuk($metode);
        $bayar->update([
            'status' => 'lunas', 'metode' => $metode, 'tgl_bayar' => now(),
            'refund_cara' => $cara, 'refund_selesai' => false,
        ]);
        LogAktivitas::catat(
            'Pembayaran masuk tetapi unit sudah terpakai, dana dikembalikan penuh'
                .($cara === 'transfer_admin' ? ' (menunggu transfer admin)' : ' lewat Midtrans'),
            null, 'pemesanan', $pemesanan->id_pemesanan
        );

        return $cara === 'midtrans' ? [$bayar->id_pembayaran, $bayar->order_id_midtrans, (int) $bayar->jumlah] : null;
    }

    private function kedaluwarsa(Pemesanan $pemesanan, Pembayaran $bayar): ?array
    {
        if ($bayar->status !== 'menunggu') {
            return null;
        }
        $bayar->update(['status' => 'gagal']);
        $this->pemesanan->batalkanTerkunci($pemesanan, 'kedaluwarsa', 'Pembayaran kedaluwarsa, pesanan dibatalkan');

        return null;
    }

    /** deny atau cancel: pembayaran gagal, pesanan tetap menunggu sampai kedaluwarsa. */
    private function ditolak(Pembayaran $bayar): ?array
    {
        if ($bayar->status === 'menunggu') {
            $bayar->update(['status' => 'gagal']);
        }

        return null;
    }

    private function jatahMasihAda(Pemesanan $pemesanan): bool
    {
        $mulai = $pemesanan->tgl_mulai->toDateString();
        $kembali = $pemesanan->tgl_kembali->toDateString();

        if (($this->ketersediaan->sisaUnit($mulai, $kembali, $pemesanan->id_tipe)[$pemesanan->id_tipe] ?? 0) < 1) {
            return false;
        }
        if ($pemesanan->jenis_sewa === 'dengan_sopir') {
            $sisa = $this->ketersediaan->sisaSopir($mulai, $kembali);

            return $sisa === null || $sisa >= 1;
        }

        return true;
    }
}
