<?php

namespace App\Services;

use App\Models\Pemesanan;
use App\Models\Pengaturan;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Nota dan laporan PDF (dompdf). Dipisah menjadi HTML dan PDF: isi PDF terkompresi sehingga tes memeriksa HTML-nya,
 * dan memeriksa PDF hanya dari tanda %PDF.
 */
class DokumenPdfService
{
    private const JENIS = ['dengan_sopir' => 'Dengan sopir', 'lepas_kunci' => 'Lepas kunci'];

    public static function rupiah(int|string|null $nominal): string
    {
        return 'Rp'.number_format((int) $nominal, 0, ',', '.');
    }

    /** Nota hanya berisi data yang boleh dilihat pelanggan: tanpa catatan admin, log, dan identitas. */
    public function notaHtml(Pemesanan $p): string
    {
        $p->loadMissing(['pelanggan', 'tipe', 'kendaraan', 'pembayaran']);

        $sewa = $p->pembayaran->where('jenis', 'sewa');
        $bayar = $sewa->whereIn('status', ['lunas', 'dikembalikan'])->sortByDesc('id_pembayaran')->first() ?? $sewa->sortByDesc('id_pembayaran')->first();
        $tambahan = $p->pembayaran->where('jenis', 'tambahan')->sortByDesc('id_pembayaran')->first();
        $lokasi = $p->lokasi_jemput ?: ($p->metode_ambil === 'ambil_di_kantor' ? 'Ambil di kantor' : '-');

        return view('pdf.nota', [
            'nomor' => $p->nomor, 'tglPesan' => $p->tgl_pesan->toDateString(), 'statusPesanan' => str_replace('_', ' ', $p->status),
            'pelanggan' => $p->pelanggan->nama, 'tipe' => $p->tipe->nama.($p->kendaraan ? ' ('.$p->kendaraan->plat_nomor.')' : ''),
            'jenisSewa' => self::JENIS[$p->jenis_sewa] ?? $p->jenis_sewa,
            'tglMulai' => $p->tgl_mulai->toDateString(), 'tglKembali' => $p->tgl_kembali->toDateString(), 'durasi' => $p->durasi,
            'jamSerah' => substr((string) $p->jam_serah, 0, 5), 'lokasi' => $lokasi,
            'tarifPerHari' => intdiv((int) $p->total_biaya, max(1, $p->durasi)), 'total' => (int) $p->total_biaya,
            'pembayaran' => [
                'status' => $bayar?->status ?? '-', 'metode' => $bayar?->metode, 'order_id' => $bayar?->order_id_midtrans,
                'tgl_bayar' => $bayar?->tgl_bayar?->format('Y-m-d H:i'),
            ],
            'tagihanTambahan' => $tambahan ? ['jumlah' => $tambahan->jumlah, 'status' => $tambahan->status] : null,
            'kantor' => ['alamat' => Pengaturan::ambil('alamat'), 'telepon' => Pengaturan::ambil('telepon'), 'email' => Pengaturan::ambil('email')],
            'rupiah' => fn ($n) => self::rupiah($n),
        ])->render();
    }

    public function nota(Pemesanan $p): string
    {
        return Pdf::loadHTML($this->notaHtml($p))->setPaper('a5')->output();
    }

    /** @param  array{periode: array, ringkasan: array, data: array}  $laporan */
    public function laporanHtml(array $laporan, ?string $status): string
    {
        return view('pdf.laporan', [
            'dari' => $laporan['periode']['dari'], 'sampai' => $laporan['periode']['sampai'], 'status' => $status,
            'ringkasan' => $laporan['ringkasan'], 'data' => $laporan['data'], 'rupiah' => fn ($n) => self::rupiah($n),
        ])->render();
    }

    public function laporan(array $laporan, ?string $status): string
    {
        return Pdf::loadHTML($this->laporanHtml($laporan, $status))->setPaper('a4', 'landscape')->output();
    }
}
