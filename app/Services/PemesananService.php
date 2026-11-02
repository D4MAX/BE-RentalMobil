<?php

namespace App\Services;

use App\Models\Kendaraan;
use App\Models\Pembayaran;
use App\Models\Pemesanan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PemesananService
{
    public const STATUS_AKTIF = ['menunggu', 'dikonfirmasi', 'pengajuan_batal'];

    /**
     * Buat pemesanan + pembayaran. Jumlah bayar harus sama dengan total biaya,
     * kalau tidak pemesanan tidak dibuat (flowchart: "pembayaran valid?").
     */
    public function buat(array $data, int $idPelanggan): Pemesanan
    {
        return DB::transaction(function () use ($data, $idPelanggan) {
            $kendaraan = Kendaraan::lockForUpdate()->findOrFail($data['id_kendaraan']);

            if ($kendaraan->status === 'dalam_perawatan') {
                throw ValidationException::withMessages([
                    'id_kendaraan' => 'Mobil sedang dalam perawatan.',
                ]);
            }

            $mulai = Carbon::parse($data['tgl_mulai'])->startOfDay();
            $selesai = Carbon::parse($data['tgl_selesai'])->startOfDay();

            $bentrok = Pemesanan::where('id_kendaraan', $kendaraan->id_kendaraan)
                ->whereIn('status', self::STATUS_AKTIF)
                ->where('tgl_mulai', '<=', $selesai->toDateString())
                ->where('tgl_selesai', '>=', $mulai->toDateString())
                ->exists();

            if ($bentrok) {
                throw ValidationException::withMessages([
                    'tgl_mulai' => 'Mobil sudah dipesan pada tanggal tersebut.',
                ]);
            }

            $lamaSewa = max(1, (int) $mulai->diffInDays($selesai));
            $total = $lamaSewa * $kendaraan->harga_sewa_per_hari;

            if ((int) $data['jumlah'] !== $total) {
                throw ValidationException::withMessages([
                    'jumlah' => "Pembayaran tidak valid, pemesanan tidak dibuat. Total yang harus dibayar: {$total}.",
                ]);
            }

            $pemesanan = Pemesanan::create([
                'id_pelanggan' => $idPelanggan,
                'id_kendaraan' => $kendaraan->id_kendaraan,
                'jenis_pesanan' => 'online',
                'tgl_pesan' => now()->toDateString(),
                'tgl_mulai' => $mulai->toDateString(),
                'tgl_selesai' => $selesai->toDateString(),
                'total_biaya' => $total,
                'status' => 'dikonfirmasi',
            ]);

            Pembayaran::create([
                'id_pemesanan' => $pemesanan->id_pemesanan,
                'no_pembayaran' => 'PAY-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)),
                'metode' => $data['metode'],
                'jumlah' => $total,
                'tgl_bayar' => now(),
                'status' => 'lunas',
            ]);

            $kendaraan->update(['status' => 'disewa']);

            return $pemesanan->load(['kendaraan', 'pelanggan', 'pembayaran']);
        });
    }

    /**
     * Kembalikan mobil ke "tersedia" bila tidak ada pemesanan aktif lain.
     */
    public function bebaskanKendaraan(Kendaraan $kendaraan): void
    {
        $masihAdaPesanan = Pemesanan::where('id_kendaraan', $kendaraan->id_kendaraan)
            ->whereIn('status', self::STATUS_AKTIF)
            ->exists();

        if (! $masihAdaPesanan && $kendaraan->status === 'disewa') {
            $kendaraan->update(['status' => 'tersedia']);
        }
    }
}