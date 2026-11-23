<?php

namespace App\Http\Resources;

use App\Models\LogAktivitas;
use App\Models\Pemesanan;

/**
 * Pesanan untuk admin (Spek API bagian 9). Daftar: ringkas tanpa tombol aksi. Detail: semua field detail user
 * ditambah pelanggan (termasuk no_hp), serah terima, jaminan, pengembalian, review, catatan, log aktivitas, dan aksi.
 */
class PemesananAdminResource
{
    public static function ringkas(Pemesanan $p): array
    {
        return [
            'id' => $p->id_pemesanan,
            'nomor' => $p->nomor,
            'tgl_pesan' => $p->tgl_pesan->toDateString(),
            'pelanggan' => ['nama' => $p->pelanggan->nama],
            'tipe' => ['id' => $p->tipe->id_tipe, 'nama' => $p->tipe->nama],
            'plat_nomor' => $p->kendaraan?->plat_nomor,
            'tgl_mulai' => $p->tgl_mulai->toDateString(),
            'status' => $p->status,
        ];
    }

    /** Relasi yang harus sudah dimuat: lihat PemesananAdminController::show. */
    public static function detail(Pemesanan $p): array
    {
        // Dasar sama dengan yang dilihat user; bagian khusus admin menimpa atau menambah
        $d = PemesananUserResource::detail($p)->resolve();
        unset($d['aksi']);

        $sewa = $p->pembayaran->where('jenis', 'sewa');
        $menunggu = self::refundMenunggu($p)->sortBy('id_pembayaran');
        $refund = $menunggu->first() ?? $sewa->whereNotNull('refund_cara')->sortByDesc('id_pembayaran')->first();
        $jaminan = $p->jaminan;
        $pg = $p->pengembalian;
        $st = $p->serahTerima;
        $pelanggan = $p->pelanggan;

        return array_merge($d, [
            'tgl_pesan' => $p->tgl_pesan->toDateString(),
            'domisili' => $p->domisili,
            'catatan' => $p->catatan,
            'plat_nomor' => $p->kendaraan?->plat_nomor,
            'pelanggan' => [
                'nama' => $pelanggan->nama, 'no_hp' => $pelanggan->no_hp, 'email' => $pelanggan->akun->email,
                'provinsi' => $pelanggan->provinsi, 'kota' => $pelanggan->kota, 'no_identitas' => $pelanggan->no_identitas,
            ],
            'riwayat_pembayaran' => $p->pembayaran->sortBy('id_pembayaran')->map(fn ($b) => [
                'jenis' => $b->jenis, 'metode' => $b->metode, 'jumlah' => $b->jumlah, 'status' => $b->status,
                'order_id' => $b->order_id_midtrans, 'tgl_bayar' => $b->tgl_bayar?->toIso8601String(),
            ])->values()->all(),
            'refund' => $refund ? [
                'id_pembayaran' => $refund->id_pembayaran, 'cara' => $refund->refund_cara, 'selesai' => (bool) $refund->refund_selesai, 'status_pembayaran' => $refund->status,
                'jumlah' => $refund->jumlah, 'metode' => $refund->metode,
                'bank' => $refund->bank, 'no_rekening' => $refund->no_rekening, 'nama_rekening' => $refund->nama_rekening,
            ] : null,
            // Semua refund yang harus ditransfer admin (bisa lebih dari satu: pembatalan dan kelebihan bayar)
            'refund_menunggu' => $menunggu->map(fn ($b) => ['id_pembayaran' => $b->id_pembayaran, 'jumlah' => $b->jumlah, 'order_id' => $b->order_id_midtrans])->values()->all(),
            'serah_terima' => $st ? [
                'tgl_serah' => $st->tgl_serah->toIso8601String(), 'plat_nomor' => $st->kendaraan?->plat_nomor,
                'odometer_awal' => $st->odometer_awal, 'bbm_awal' => $st->bbm_awal, 'catatan_kondisi' => $st->catatan_kondisi,
                'jumlah_foto' => count($st->foto_kondisi ?? []), 'admin' => $st->admin?->nama_tampil,
            ] : null,
            'jaminan' => $jaminan->isEmpty() ? null : [
                'status' => $jaminan->every->dikembalikan ? 'dikembalikan' : 'ditahan',
                'daftar' => $jaminan->map(fn ($j) => ['jenis' => $j->jenis, 'diterima' => $j->diterima, 'dikembalikan' => $j->dikembalikan])->values()->all(),
            ],
            'pengembalian' => $pg ? [
                'tgl_kembali' => $pg->tgl_kembali->toDateString(), 'jam_kembali' => substr((string) $pg->jam_kembali, 0, 5),
                'hari_terlambat' => $pg->hari_terlambat, 'tarif_denda_per_hari' => $pg->tarif_denda_per_hari,
                'denda_keterlambatan' => $pg->denda_keterlambatan, 'odometer_akhir' => $pg->odometer_akhir, 'bbm_akhir' => $pg->bbm_akhir,
                'biaya_kekurangan_bbm' => $pg->biaya_kekurangan_bbm, 'kebersihan' => $pg->kebersihan,
                'kondisi' => $pg->kondisi->map(fn ($k) => ['bagian' => $k->bagian, 'kondisi' => $k->kondisi, 'biaya' => $k->biaya, 'keterangan' => $k->keterangan, 'ada_foto' => $pg->foto->contains('bagian', $k->bagian)])->values()->all(),
                'biaya_tambahan' => $pg->biayaTambahan->map(fn ($b) => ['jenis' => $b->jenis, 'keterangan' => $b->keterangan, 'nominal' => $b->nominal])->values()->all(),
                'jumlah_foto' => $pg->foto->count(), 'catatan_admin' => $pg->catatan_admin,
            ] : null,
            'review' => $p->review ? [
                'id' => $p->review->id_review, 'rating' => $p->review->rating, 'komentar' => $p->review->komentar,
                'tgl_review' => $p->review->tgl_review->toDateString(), 'tampil_di_katalog' => $p->review->tampil_di_katalog,
            ] : null,
            'catatan_admin' => $p->catatan_admin,
            'alasan_pengajuan' => $p->alasan_pengajuan,
            'alasan_pembatalan' => $p->alasan_pembatalan,
            'status_sebelumnya' => $p->status_sebelumnya,
            'log_aktivitas' => self::log($p),
            'aksi' => self::aksi($p),
        ]);
    }

    /** Refund yang harus ditransfer admin dan belum selesai, apa pun status pesanannya. */
    public static function refundMenunggu(Pemesanan $p)
    {
        return $p->pembayaran->filter(fn ($b) => $b->jenis === 'sewa' && $b->status === 'lunas'
            && $b->refund_cara === 'transfer_admin' && ! $b->refund_selesai);
    }

    /**
     * Tombol di tampilan admin. Tidak ada tombol pembatalan: admin hanya menyetujui atau menolak pengajuan pelanggan.
     * Tidak ada tombol pengingat (V22 tidak punya pengingat). Kembalikan jaminan baru aktif setelah tagihan lunas.
     */
    public static function aksi(Pemesanan $p): array
    {
        $tagihanMenunggu = $p->pembayaran->where('jenis', 'tambahan')->where('status', 'menunggu')->isNotEmpty();
        $jaminanDitahan = $p->jenis_sewa === 'lepas_kunci' && $p->jaminan->isNotEmpty() && ! $p->jaminan->every->dikembalikan;

        return [
            'bisa_serahkan' => $p->status === 'dikonfirmasi',
            'bisa_selesaikan' => $p->status === 'berjalan',
            'bisa_setujui' => $p->status === 'pengajuan_pembatalan',
            'bisa_tolak' => $p->status === 'pengajuan_pembatalan',
            'bisa_refund_selesai' => self::refundMenunggu($p)->isNotEmpty(),
            'bisa_tandai_lunas' => $p->status === 'selesai' && $tagihanMenunggu,
            'bisa_kembalikan_jaminan' => $p->status === 'selesai' && $jaminanDitahan && ! $tagihanMenunggu,
        ];
    }

    /** Riwayat pesanan beserta riwayat pembayarannya (refund otomatis dicatat atas objek pembayaran). */
    private static function log(Pemesanan $p): array
    {
        $idBayar = $p->pembayaran->pluck('id_pembayaran')->all();

        return LogAktivitas::query()->with('akun.pelanggan')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('objek', 'pemesanan')->where('id_objek', $p->id_pemesanan))
                ->orWhere(fn ($w) => $w->where('objek', 'pembayaran')->whereIn('id_objek', $idBayar)))
            ->orderBy('waktu')->orderBy('id_log')
            ->get()
            ->map(fn ($l) => [
                'waktu' => $l->waktu->toIso8601String(), 'aksi' => $l->aksi,
                'oleh' => match ($l->pelaku) {
                    'sistem' => 'Sistem',
                    'pelanggan' => 'Pelanggan',
                    default => $l->akun?->nama_tampil ?? 'Admin',
                },
            ])->all();
    }
}
