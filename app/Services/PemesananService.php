<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Akun;
use App\Models\LogAktivitas;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Pemesanan;
use App\Models\Pengaturan;
use App\Models\Review;
use App\Models\TipeKendaraan;
use App\Services\Pembayaran\PembayaranGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PemesananService
{
    public function __construct(
        private KetersediaanService $ketersediaan,
        private PembayaranGateway $gateway,
    ) {}

    /**
     * POST /pemesanan (Spek API bagian 6 dan 12).
     *
     * Transaksi database hanya memeriksa dan menyimpan (pesanan + baris pembayaran "menunggu") lalu commit, sehingga
     * kunci tipe tidak tertahan oleh jaringan. Tagihan Midtrans diminta SESUDAH commit; bila gagal, pesanan dibatalkan,
     * unit dilepas, dan balasannya 502 PEMBAYARAN_GAGAL.
     *
     * @return array<string,mixed> isi "data" respons 201
     */
    public function buat(Akun $akun, Pelanggan $pelanggan, array $d): array
    {
        $mulai = CarbonImmutable::parse($d['tgl_mulai'])->toDateString();
        $kembali = CarbonImmutable::parse($mulai)->addDays($d['durasi'] - 1)->toDateString();
        $sopir = $d['jenis_sewa'] === 'dengan_sopir';

        [$pemesanan, $bayar] = DB::transaction(function () use ($akun, $pelanggan, $d, $mulai, $kembali, $sopir) {
            // 1. Ambil SEMUA kunci lebih dulu, urutan tetap (pelanggan, sopir, tipe) agar tidak buntu.
            //    Pembacaan biasa baru boleh sesudah kunci tipe terpegang: pada REPEATABLE READ (MySQL) snapshot
            //    dibuat saat pembacaan biasa pertama, dan snapshot lama tidak melihat pesanan yang baru commit.
            Pelanggan::query()->whereKey($pelanggan->id_pelanggan)->lockForUpdate()->first();
            if ($sopir) {
                // Sopir dipakai bersama semua tipe, jadi kunci tipe saja tidak cukup
                Pengaturan::query()->whereKey('jumlah_sopir')->lockForUpdate()->first();
            }
            $tipe = TipeKendaraan::query()->lockForUpdate()->findOrFail($d['id_tipe']);

            $tarif = $this->tarifPerHari($tipe, $d);

            // 2. Urutan pemeriksaan menurut spek: tagihan lama, pesanan menunggu, unit, sopir
            $adaTagihan = Pembayaran::query()
                ->where('jenis', 'tambahan')->where('status', 'menunggu')
                ->whereHas('pemesanan', fn ($q) => $q->where('id_pelanggan', $pelanggan->id_pelanggan))
                ->exists();
            if ($adaTagihan) {
                throw new ApiException('Anda masih punya tagihan tambahan yang belum lunas', 'TAGIHAN_BELUM_LUNAS');
            }

            $adaMenunggu = Pemesanan::query()
                ->where('id_pelanggan', $pelanggan->id_pelanggan)
                ->where('status', 'menunggu_pembayaran')->where('batas_pembayaran', '>', now())
                ->exists();
            if ($adaMenunggu) {
                throw new ApiException('Selesaikan atau batalkan pesanan yang menunggu pembayaran lebih dulu', 'PESANAN_MENUNGGU_ADA');
            }

            if (($this->ketersediaan->sisaUnit($mulai, $kembali, $tipe->id_tipe)[$tipe->id_tipe] ?? 0) < 1) {
                throw new ApiException('Mobil sudah dipesan pada tanggal tersebut', 'UNIT_TIDAK_TERSEDIA');
            }

            if ($sopir) {
                $sisaSopir = $this->ketersediaan->sisaSopir($mulai, $kembali);
                if ($sisaSopir !== null && $sisaSopir < 1) {
                    throw new ApiException('Sopir tidak tersedia pada tanggal tersebut', 'SOPIR_TIDAK_TERSEDIA');
                }
            }

            // 3. Simpan pesanan dan baris pembayaran (menunggu); tagihan Midtrans menyusul setelah commit
            $total = $tarif * $d['durasi'];
            $pemesanan = Pemesanan::create([
                'id_pelanggan' => $pelanggan->id_pelanggan,
                'id_tipe' => $tipe->id_tipe,
                'jenis_sewa' => $d['jenis_sewa'],
                'transmisi' => $sopir ? null : $d['transmisi'],
                'tgl_pesan' => now()->toDateString(),
                'tgl_mulai' => $mulai,
                'durasi' => $d['durasi'],
                'tgl_kembali' => $kembali,
                'metode_ambil' => $sopir ? null : $d['metode_ambil'],
                'jam_serah' => $d['jam_serah'],
                'lokasi_jemput' => $sopir || $d['metode_ambil'] === 'diantar' ? $d['lokasi_jemput'] : null,
                'tujuan' => $sopir ? $d['tujuan'] : null,
                'domisili' => $this->domisili($pelanggan),
                'catatan' => $d['catatan'] ?? null,
                'setuju_syarat' => true,
                'total_biaya' => $total,
                'status' => 'menunggu_pembayaran',
                'batas_pembayaran' => now()->addMinutes(config('rental.batas_bayar_menit')),
            ]);

            $bayar = Pembayaran::create([
                'id_pemesanan' => $pemesanan->id_pemesanan,
                'jenis' => 'sewa',
                'jumlah' => $total,
                'order_id_midtrans' => $pemesanan->nomor.'-1',
                'status' => 'menunggu',
            ]);

            LogAktivitas::catat('Pesanan dibuat', $akun, 'pemesanan', $pemesanan->id_pemesanan);

            return [$pemesanan, $bayar];
        });

        // Di luar transaksi: gagal berarti pesanan dibatalkan dan unit dilepas
        try {
            $transaksi = $this->minta($akun, $pelanggan, $pemesanan, $bayar);
        } catch (Throwable $e) {
            report($e);
            $this->batalkanOtomatis($pemesanan->id_pemesanan, 'pembayaran_gagal', 'Pesanan dibatalkan: tagihan pembayaran gagal dibuat');
            throw new ApiException('Tagihan pembayaran gagal dibuat, coba lagi', 'PEMBAYARAN_GAGAL', 502);
        }

        return [
            'id' => $pemesanan->id_pemesanan,
            'nomor' => $pemesanan->nomor,
            'status' => $pemesanan->status,
            'tgl_mulai' => $mulai,
            'durasi' => $pemesanan->durasi,
            'tgl_kembali' => $kembali,
            'total_biaya' => $pemesanan->total_biaya,
            'batas_pembayaran' => $pemesanan->batas_pembayaran->toIso8601String(),
            'midtrans' => ['snap_token' => $transaksi->snapToken, 'order_id' => $transaksi->orderId],
        ];
    }

    /**
     * POST /pemesanan/{id}/pembayaran: snap token baru ("Bayar sekarang"). Tiap percobaan punya order_id sendiri
     * (PSN-0015-2, -3, ...) dan baris pembayaran sendiri; notifikasi dari token mana pun tetap dikenali.
     */
    public function bayarLagi(Akun $akun, Pelanggan $pelanggan, int $id): array
    {
        [$pemesanan, $bayar] = DB::transaction(function () use ($pelanggan, $id) {
            $pemesanan = Pemesanan::query()->where('id_pelanggan', $pelanggan->id_pelanggan)->lockForUpdate()->find($id);
            if (! $pemesanan) {
                throw new ApiException('Pesanan tidak ditemukan', 'TIDAK_DITEMUKAN', 404);
            }

            $kedaluwarsa = $pemesanan->status === 'menunggu_pembayaran'
                ? $pemesanan->batas_pembayaran->lte(now())
                : ($pemesanan->status === 'dibatalkan' && $pemesanan->alasan_pembatalan === 'kedaluwarsa');
            if ($kedaluwarsa) {
                throw new ApiException('Batas pembayaran 1 jam sudah lewat', 'PEMBAYARAN_KEDALUWARSA');
            }
            if ($pemesanan->status !== 'menunggu_pembayaran') {
                throw new ApiException('Status pesanan tidak valid untuk pembayaran', 'STATUS_TIDAK_VALID');
            }

            $urut = Pembayaran::where('id_pemesanan', $pemesanan->id_pemesanan)->where('jenis', 'sewa')->count() + 1;
            $bayar = Pembayaran::create([
                'id_pemesanan' => $pemesanan->id_pemesanan,
                'jenis' => 'sewa',
                'jumlah' => $pemesanan->total_biaya,
                'order_id_midtrans' => $pemesanan->nomor.'-'.$urut,
                'status' => 'menunggu',
            ]);

            return [$pemesanan, $bayar];
        });

        try {
            $transaksi = $this->minta($akun, $pelanggan, $pemesanan, $bayar);
        } catch (Throwable $e) {
            report($e);
            $bayar->update(['status' => 'gagal']); // pesanan tetap menunggu; user boleh mencoba lagi
            throw new ApiException('Tagihan pembayaran gagal dibuat, coba lagi', 'PEMBAYARAN_GAGAL', 502);
        }

        return [
            'snap_token' => $transaksi->snapToken,
            'batas_pembayaran' => $pemesanan->batas_pembayaran->toIso8601String(),
        ];
    }

    /**
     * Pesanan menunggu pembayaran menjadi dibatalkan: pembayaran menunggu menjadi gagal, unit dilepas (status
     * dibatalkan tidak dihitung), log dari Sistem. Dipakai scheduler, webhook expire, dan jalur gateway gagal.
     * Mengembalikan false bila pesanan sudah bukan menunggu_pembayaran (idempoten).
     */
    public function batalkanOtomatis(int $idPemesanan, string $alasan, string $aksiLog): bool
    {
        return DB::transaction(function () use ($idPemesanan, $alasan, $aksiLog) {
            $pemesanan = Pemesanan::query()->lockForUpdate()->find($idPemesanan);

            return $pemesanan !== null && $this->batalkanTerkunci($pemesanan, $alasan, $aksiLog);
        });
    }

    /** Sama seperti batalkanOtomatis, untuk baris pesanan yang sudah dikunci pemanggil di dalam transaksinya. */
    public function batalkanTerkunci(Pemesanan $pemesanan, string $alasan, string $aksiLog, ?Akun $pelaku = null): bool
    {
        if ($pemesanan->status !== 'menunggu_pembayaran') {
            return false;
        }

        $pemesanan->update(['status' => 'dibatalkan', 'alasan_pembatalan' => $alasan]);
        Pembayaran::where('id_pemesanan', $pemesanan->id_pemesanan)->where('jenis', 'sewa')
            ->where('status', 'menunggu')->update(['status' => 'gagal']);
        LogAktivitas::catat($aksiLog, $pelaku, 'pemesanan', $pemesanan->id_pemesanan);

        return true;
    }

    /**
     * POST /pemesanan/{id}/batalkan: hanya menunggu_pembayaran milik sendiri (belum ada uang). Baris pesanan dikunci
     * seperti di webhook, sehingga pembatalan dan notifikasi settlement yang bersamaan tidak bisa sama-sama menang:
     * kalau webhook lebih dulu pesanan sudah dikonfirmasi (409), kalau pembatalan lebih dulu webhook me-refund.
     */
    public function batalkan(Akun $akun, Pelanggan $pelanggan, int $id): array
    {
        $orderIds = DB::transaction(function () use ($akun, $pelanggan, $id) {
            $pemesanan = $this->milikLocked($pelanggan, $id);
            if ($pemesanan->status !== 'menunggu_pembayaran') {
                throw new ApiException('Hanya pesanan yang menunggu pembayaran yang bisa dibatalkan', 'STATUS_TIDAK_VALID');
            }

            $orderIds = Pembayaran::where('id_pemesanan', $id)->where('jenis', 'sewa')
                ->where('status', 'menunggu')->pluck('order_id_midtrans')->all();
            $this->batalkanTerkunci($pemesanan, 'dibatalkan_pelanggan', 'Pelanggan membatalkan pesanan', $akun);

            return $orderIds;
        });

        // Sesudah commit, best effort: transaksi Midtrans yang belum dibayar dimatikan. Galat apa pun diabaikan,
        // pembatalan sudah sah (pembayaran yang masih lolos akan di-refund oleh webhook).
        foreach ($orderIds as $orderId) {
            try {
                $this->gateway->batalkanTransaksi($orderId);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['status' => 'dibatalkan'];
    }

    /**
     * POST /pemesanan/{id}/ajukan-pembatalan: hanya dikonfirmasi dan paling lambat H-1 (tanggal WIB). Status menjadi
     * pengajuan_pembatalan, status_sebelumnya disimpan, dan tanggal tetap terblokir (status ini ikut dihitung terpakai).
     * Rekening tujuan refund wajib hanya untuk virtual account dan gerai (Pembayaran::wajibRekening). Channel lain
     * di-refund lewat API Midtrans; bila gagal, jatuh ke transfer_admin dan admin menghubungi pelanggan di luar sistem.
     *
     * @param  array{alasan: string, bank?: ?string, no_rekening?: ?string, nama_rekening?: ?string}  $d
     */
    public function ajukanPembatalan(Akun $akun, Pelanggan $pelanggan, int $id, array $d): array
    {
        DB::transaction(function () use ($akun, $pelanggan, $id, $d) {
            $pemesanan = $this->milikLocked($pelanggan, $id);
            if ($pemesanan->status !== 'dikonfirmasi') {
                throw new ApiException('Status pesanan tidak valid untuk pengajuan pembatalan', 'STATUS_TIDAK_VALID');
            }
            if ($pemesanan->lewatBatasH1()) {
                throw new ApiException('Pesanan tidak bisa dibatalkan', 'LEWAT_BATAS_H1');
            }

            $bayar = Pembayaran::where('id_pemesanan', $id)->where('jenis', 'sewa')->where('status', 'lunas')
                ->orderByDesc('id_pembayaran')->first();
            $perluRekening = Pembayaran::wajibRekening($bayar?->metode);

            if ($perluRekening) {
                Validator::make($d, [
                    'bank' => ['required', 'string', 'max:50'],
                    'no_rekening' => ['required', 'string', 'max:50'],
                    'nama_rekening' => ['required', 'string', 'max:100'],
                ], ['required' => 'Wajib diisi untuk pembayaran virtual account'])->validate();
            }

            $pemesanan->update([
                'status' => 'pengajuan_pembatalan',
                'status_sebelumnya' => $pemesanan->status,
                'alasan_pengajuan' => $d['alasan'], // teks bebas pelanggan; alasan_pembatalan hanya kode sistem
            ]);
            // Hanya rekening tujuan yang disimpan. refund_cara baru diisi saat uang memang harus dikembalikan (persetujuan
            // admin, atau webhook): dengan begitu refund_cara terisi dan refund_selesai false selalu berarti ada utang refund.
            if ($perluRekening) {
                $bayar?->update(['bank' => $d['bank'], 'no_rekening' => $d['no_rekening'], 'nama_rekening' => $d['nama_rekening']]);
            }
            LogAktivitas::catat('Pelanggan mengajukan pembatalan', $akun, 'pemesanan', $id);
        });

        return ['status' => 'pengajuan_pembatalan'];
    }

    /**
     * Tugas terjadwal: dikonfirmasi yang lewat tgl_kembali tanpa serah terima ditutup (dibatalkan, alasan
     * tidak_diambil). Pembayaran tetap lunas dan tidak ada refund (Spek API bagian 13). Idempoten.
     */
    public function tutupTidakDiambil(int $idPemesanan): bool
    {
        return DB::transaction(function () use ($idPemesanan) {
            $pemesanan = Pemesanan::query()->lockForUpdate()->find($idPemesanan);
            if (! $pemesanan || $pemesanan->status !== 'dikonfirmasi'
                || $pemesanan->tgl_kembali->toDateString() >= now()->toDateString()
                || DB::table('serah_terima')->where('id_pemesanan', $idPemesanan)->exists()) {
                return false;
            }

            $pemesanan->update(['status' => 'dibatalkan', 'alasan_pembatalan' => 'tidak_diambil']);
            LogAktivitas::catat('Pesanan ditutup: unit tidak diambil sampai tanggal kembali (pembayaran tetap lunas, tanpa refund)', null, 'pemesanan', $idPemesanan);

            return true;
        });
    }

    /**
     * POST /pemesanan/{id}/review: hanya pesanan selesai, sekali per pesanan. Review baru tersembunyi di katalog sampai
     * admin menampilkannya.
     */
    public function beriReview(Akun $akun, Pelanggan $pelanggan, int $id, array $d): array
    {
        return DB::transaction(function () use ($akun, $pelanggan, $id, $d) {
            $pemesanan = $this->milikLocked($pelanggan, $id);
            if ($pemesanan->status !== 'selesai') {
                throw new ApiException('Review hanya untuk pesanan yang sudah selesai', 'STATUS_TIDAK_VALID');
            }
            if (Review::where('id_pemesanan', $id)->exists()) {
                throw new ApiException('Pesanan ini sudah pernah diberi review', 'SUDAH_ADA_REVIEW');
            }

            Review::create([
                'id_pemesanan' => $id, 'rating' => $d['rating'], 'komentar' => $d['komentar'] ?? null,
                'tgl_review' => now()->toDateString(), 'tampil_di_katalog' => false,
            ]);
            LogAktivitas::catat('Pelanggan memberi review', $akun, 'pemesanan', $id);

            return ['rating' => (int) $d['rating'], 'komentar' => $d['komentar'] ?? null];
        });
    }

    /** Baris pesanan milik pelanggan, dikunci. Bukan miliknya atau tidak ada: 404 yang sama. */
    private function milikLocked(Pelanggan $pelanggan, int $id): Pemesanan
    {
        $pemesanan = Pemesanan::query()->where('id_pelanggan', $pelanggan->id_pelanggan)->lockForUpdate()->find($id);
        if (! $pemesanan) {
            throw new ApiException('Pesanan tidak ditemukan', 'TIDAK_DITEMUKAN', 404);
        }

        return $pemesanan;
    }

    private function minta(Akun $akun, Pelanggan $pelanggan, Pemesanan $pemesanan, Pembayaran $bayar)
    {
        // Token berakhir bersamaan dengan batas_pembayaran pesanan
        $sisaMenit = max(1, (int) ceil(now()->diffInSeconds($pemesanan->batas_pembayaran, false) / 60));

        return $this->gateway->buatTransaksi($bayar->order_id_midtrans, (int) $bayar->jumlah, [
            'nama' => $pelanggan->nama, 'email' => $akun->email, 'no_hp' => $pelanggan->no_hp,
        ], $sisaMenit);
    }

    /** Tarif per hari menurut jenis sewa dan transmisi; kosong berarti tipe tidak melayani jenis itu. */
    private function tarifPerHari(TipeKendaraan $tipe, array $d): int
    {
        if ($d['jenis_sewa'] === 'dengan_sopir') {
            $tarif = $tipe->harga_sopir_per_hari;
            $kolom = 'jenis_sewa';
        } else {
            $tarif = $d['transmisi'] === 'matic' ? $tipe->harga_lepas_kunci_matic : $tipe->harga_lepas_kunci_manual;
            $kolom = 'transmisi';
        }

        if (! $tarif) {
            throw ValidationException::withMessages([$kolom => ['Tipe ini tidak melayani pilihan tersebut']]);
        }

        return (int) $tarif;
    }

    /** Malang Raya = Kota Malang, Kabupaten Malang, Kota Batu; dihitung dari kota di profil. */
    private function domisili(Pelanggan $pelanggan): string
    {
        return in_array(Str::lower(trim($pelanggan->kota)), config('rental.kota_malang_raya'), true)
            ? 'malang_raya'
            : 'luar_malang_raya';
    }
}
