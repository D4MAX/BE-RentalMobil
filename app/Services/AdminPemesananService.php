<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Requests\Api\PengembalianRequest;
use App\Models\Akun;
use App\Models\BiayaTambahan;
use App\Models\Jaminan;
use App\Models\Kendaraan;
use App\Models\LogAktivitas;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Pemesanan;
use App\Models\Pengembalian;
use App\Models\PengembalianFoto;
use App\Models\PengembalianKondisi;
use App\Models\Perawatan;
use App\Models\SerahTerima;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Aksi admin atas pesanan (Spek API bagian 9). Setiap aksi menulis log_aktivitas atas nama admin. Tidak ada
 * pembatalan oleh admin: admin hanya menyetujui atau menolak pengajuan pelanggan.
 *
 * Aturan kunci (REPEATABLE READ di MySQL): ambil semua kunci baris lebih dulu (pesanan, lalu unit), baru membaca
 * biasa. Pembacaan biasa pertama membuat snapshot; snapshot yang dibuat sebelum kunci tidak melihat commit lawan.
 */
class AdminPemesananService
{
    public function __construct(private RefundService $refund) {}

    // ---------------------------------------------------------------- unit tersedia

    /** Unit tipe yang sama, berstatus tersedia, dan tidak sedang disewa (tidak ada pesanan berjalan yang memakainya). */
    public function unitBebas(int $idTipe)
    {
        return Kendaraan::query()
            ->where('id_tipe', $idTipe)->where('status', 'tersedia')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('pemesanan')
                ->whereColumn('pemesanan.id_kendaraan', 'kendaraan.id_kendaraan')->where('pemesanan.status', 'berjalan'))
            ->orderBy('plat_nomor');
    }

    public function unitTersedia(int $id): array
    {
        $pemesanan = Pemesanan::query()->find($id) ?? throw $this->tidakDitemukan();
        if ($pemesanan->status !== 'dikonfirmasi') {
            throw new ApiException('Hanya pesanan dikonfirmasi yang bisa diserahkan', 'STATUS_TIDAK_VALID');
        }

        $unit = $this->unitBebas($pemesanan->id_tipe)->get(['id_kendaraan', 'plat_nomor', 'warna', 'km_terakhir']);

        return [
            'unit' => $unit->map(fn ($u) => ['id' => $u->id_kendaraan, 'plat_nomor' => $u->plat_nomor, 'warna' => $u->warna, 'km_terakhir' => $u->km_terakhir])->values()->all(),
            'jumlah' => $unit->count(),
        ];
    }

    // ---------------------------------------------------------------- serah terima

    /**
     * @param  array<string,mixed>  $d  hasil SerahTerimaRequest
     * @param  list<UploadedFile>  $foto
     */
    public function serahkan(Akun $admin, int $id, array $d, array $foto = []): array
    {
        $tersimpan = []; // semua berkas yang ditulis, untuk dibersihkan bila transaksi batal
        $fotoKondisi = [];

        try {
            return DB::transaction(function () use ($admin, $id, $d, $foto, &$tersimpan, &$fotoKondisi) {
                // Kunci: pesanan lalu unit. Dua admin yang memilih plat sama bertemu di kunci unit.
                $pemesanan = $this->kunciPesanan($id);
                $unit = Kendaraan::query()->lockForUpdate()->find($d['id_kendaraan']);

                if ($pemesanan->status !== 'dikonfirmasi') {
                    throw new ApiException('Status pesanan tidak valid untuk serah terima', 'STATUS_TIDAK_VALID');
                }
                if (! $unit || (int) $unit->id_tipe !== (int) $pemesanan->id_tipe) {
                    throw ValidationException::withMessages(['id_kendaraan' => ['Unit bukan dari tipe yang dipesan']]);
                }
                $disewa = Pemesanan::where('id_kendaraan', $unit->id_kendaraan)->where('status', 'berjalan')->exists();
                if ($unit->status !== 'tersedia' || $disewa) {
                    throw new ApiException('Unit yang dipilih sudah tidak bebas', 'UNIT_SUDAH_DIPAKAI');
                }

                $kunci = $pemesanan->jenis_sewa === 'lepas_kunci';
                $domisili = $d['domisili_disesuaikan'] ?? $pemesanan->domisili;

                if ($domisili !== $pemesanan->domisili) {
                    LogAktivitas::catat(
                        "Domisili pesanan disesuaikan dari {$pemesanan->domisili} ke {$domisili} (KTP asli berbeda)",
                        $admin, 'pemesanan', $id
                    );
                }

                $serah = SerahTerima::create([
                    'id_pemesanan' => $id, 'id_kendaraan' => $unit->id_kendaraan, 'id_admin' => $admin->id_akun,
                    'tgl_serah' => now(),
                    'odometer_awal' => $d['odometer_awal'] ?? null, 'bbm_awal' => $d['bbm_awal'] ?? null,
                    'catatan_kondisi' => $d['catatan_kondisi'] ?? null,
                ]);

                if ($kunci) {
                    Pelanggan::query()->whereKey($pemesanan->id_pelanggan)->update(['no_identitas' => $d['no_identitas']]);
                    // Foto jaminan disimpan di disk privat (bukan publik)
                    foreach ($this->daftarJaminan($d['jaminan'], $domisili) as $jenis => $itemFoto) {
                        $tersimpan[] = $path = $d['foto_jaminan'][$itemFoto]->store("jaminan/{$id}", 'local');
                        Jaminan::create(['id_pemesanan' => $id, 'jenis' => $jenis, 'foto' => $path, 'diterima' => true, 'dikembalikan' => false]);
                    }
                }

                foreach ($foto as $berkas) {
                    $tersimpan[] = $fotoKondisi[] = $berkas->store("serah-terima/{$id}", 'local');
                }
                $serah->update(['foto_kondisi' => $fotoKondisi ?: null]);

                $pemesanan->update(['status' => 'berjalan', 'id_kendaraan' => $unit->id_kendaraan, 'id_admin' => $admin->id_akun, 'domisili' => $domisili]);
                LogAktivitas::catat("Unit diserahkan: {$unit->plat_nomor}", $admin, 'pemesanan', $id);

                return ['status' => 'berjalan', 'plat_nomor' => $unit->plat_nomor, 'jaminan_status' => $kunci ? 'ditahan' : null];
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($tersimpan); // berkas yatim bila transaksi dibatalkan
            throw $e;
        }
    }

    /** @return array<string,string> jenis jaminan yang diterima (butir a sampai e) => nama item di foto_jaminan */
    private function daftarJaminan(array $j, string $domisili): array
    {
        $daftar = ['ktp_asli' => 'ktp_asli', 'sim_c_asli' => 'sim_c_asli', 'dokumen_tambahan:'.$j['dokumen_tambahan'] => 'dokumen_tambahan'];

        return $daftar + ($domisili === 'malang_raya'
            ? ['motor_stnk' => 'motor_stnk']
            : ['booking_transportasi' => 'booking_transportasi', 'booking_hotel' => 'booking_hotel']);
    }

    // ---------------------------------------------------------------- pengembalian

    /** Ringkasan biaya (Spek API bagian 12 no. 3 sampai 5). Murni hitungan, tidak menyimpan. */
    public function ringkasan(Pemesanan $p, array $d): array
    {
        $sopir = $p->jenis_sewa === 'dengan_sopir';
        $tarifPerHari = intdiv((int) $p->total_biaya, max(1, $p->durasi));

        // Selisih tanggal kalender: sampai 23.59 tanggal kembali tidak terlambat
        $rencana = CarbonImmutable::parse($p->tgl_kembali->toDateString())->startOfDay();
        $aktual = CarbonImmutable::parse($d['tgl_kembali'])->startOfDay();
        $terlambat = max(0, (int) round($rencana->diffInDays($aktual, false)));

        $denda = $terlambat * $tarifPerHari;
        $bbm = $sopir ? 0 : (int) ($d['biaya_kekurangan_bbm'] ?? 0);
        $kerusakan = (int) collect($d['kondisi'])->where('kondisi', '!=', 'baik')->sum(fn ($k) => (int) ($k['biaya'] ?? 0));
        $lain = (int) collect($d['biaya_tambahan'] ?? [])->sum(fn ($b) => (int) $b['nominal']);
        $tagihan = $denda + $bbm + $kerusakan + $lain;

        return [
            'biaya_sewa' => (int) $p->total_biaya,
            'hari_terlambat' => $terlambat,
            'denda_keterlambatan' => $denda,
            'kekurangan_bbm' => $bbm,
            'biaya_kerusakan' => $kerusakan,
            'biaya_lain' => $lain,
            'tagihan_tambahan' => $tagihan,
            'total_akhir' => (int) $p->total_biaya + $tagihan,
        ];
    }

    public function previewPengembalian(int $id, array $d): array
    {
        $p = Pemesanan::query()->with('serahTerima')->find($id) ?? throw $this->tidakDitemukan();
        $this->periksaPengembalian($p, $d);

        return ['ringkasan' => $this->ringkasan($p, $d)];
    }

    /**
     * @param  array<string,mixed>  $d  hasil PengembalianRequest
     * @param  array<string,UploadedFile>  $foto  satu foto per bagian (kunci = nama bagian)
     */
    public function selesaikan(Akun $admin, int $id, array $d, array $foto = []): array
    {
        $tersimpan = [];

        try {
            return DB::transaction(function () use ($admin, $id, $d, $foto, &$tersimpan) {
                $p = $this->kunciPesanan($id);
                $unit = $p->id_kendaraan ? Kendaraan::query()->lockForUpdate()->find($p->id_kendaraan) : null;
                $p->load('serahTerima');

                $this->periksaPengembalian($p, $d);
                $ringkasan = $this->ringkasan($p, $d);
                $tagihan = $ringkasan['tagihan_tambahan'];

                $pengembalian = Pengembalian::create([
                    'id_pemesanan' => $id,
                    'tgl_kembali' => $d['tgl_kembali'], 'jam_kembali' => $d['jam_kembali'],
                    'tarif_denda_per_hari' => intdiv((int) $p->total_biaya, max(1, $p->durasi)),
                    'hari_terlambat' => $ringkasan['hari_terlambat'], 'denda_keterlambatan' => $ringkasan['denda_keterlambatan'],
                    'odometer_akhir' => $d['odometer_akhir'] ?? null, 'bbm_akhir' => $d['bbm_akhir'] ?? null,
                    'biaya_kekurangan_bbm' => $ringkasan['kekurangan_bbm'], 'kebersihan' => $d['kebersihan'] ?? null,
                    'catatan_admin' => $d['catatan_admin'] ?? null, 'disetujui_penyewa' => true,
                ]);

                foreach ($d['kondisi'] as $k) {
                    PengembalianKondisi::create([
                        'id_pengembalian' => $pengembalian->id_pengembalian, 'bagian' => $k['bagian'], 'kondisi' => $k['kondisi'],
                        'biaya' => $k['kondisi'] === 'baik' ? 0 : (int) $k['biaya'], 'keterangan' => $k['keterangan'] ?? null,
                    ]);
                }
                foreach ($d['biaya_tambahan'] ?? [] as $b) {
                    BiayaTambahan::create(['id_pengembalian' => $pengembalian->id_pengembalian, 'jenis' => $b['jenis'],
                        'keterangan' => $b['keterangan'] ?? null, 'nominal' => (int) $b['nominal']]);
                }
                foreach (PengembalianRequest::BAGIAN as $bagian) {
                    if (! isset($foto[$bagian])) {
                        continue;
                    }
                    $tersimpan[] = $path = $foto[$bagian]->store("pengembalian/{$id}", 'local');
                    PengembalianFoto::create(['id_pengembalian' => $pengembalian->id_pengembalian, 'bagian' => $bagian, 'path_foto' => $path]);
                }

                // Tagihan tambahan tercatat sebagai pembayaran berjenis tambahan (di luar Midtrans: tunai atau transfer)
                $tagihanStatus = null;
                if ($tagihan > 0) {
                    $langsung = in_array($d['status_pembayaran_tagihan'] ?? 'belum_dibayar', ['tunai', 'transfer'], true);
                    Pembayaran::create([
                        'id_pemesanan' => $id, 'jenis' => 'tambahan', 'jumlah' => $tagihan,
                        'metode' => $langsung ? $d['status_pembayaran_tagihan'] : null,
                        'status' => $langsung ? 'lunas' : 'menunggu', 'tgl_bayar' => $langsung ? now() : null,
                    ]);
                    $tagihanStatus = $langsung ? 'lunas' : 'belum';
                }

                // Jaminan (lepas kunci): pegawai yang menerima mobil memegang jaminan, jadi otomatis dikembalikan bila tidak ada
                // tagihan atau tagihan langsung lunas; bila tagihan belum lunas, ditahan sampai tagihan itu lunas (tandaiTagihanLunas)
                $tagihanBelumLunas = $tagihan > 0 && $tagihanStatus !== 'lunas';
                $adaJaminan = Jaminan::where('id_pemesanan', $id)->exists();
                if ($adaJaminan && ! $tagihanBelumLunas) {
                    $this->kembalikanJaminanOtomatis($id, $admin, 'saat pengembalian');
                }
                $jaminanStatus = ! $adaJaminan ? null : ($tagihanBelumLunas ? 'ditahan' : 'dikembalikan');

                // Unit: tersedia lagi, atau masuk perawatan dengan catatan asal pesanan
                $perluServis = (bool) ($d['unit_perlu_servis'] ?? false);
                if ($unit) {
                    $unit->update([
                        'km_terakhir' => max((int) $unit->km_terakhir, (int) ($d['odometer_akhir'] ?? 0)),
                        'status' => $perluServis ? 'dalam_perawatan' : 'tersedia',
                    ]);
                    if ($perluServis) {
                        Perawatan::create([
                            'id_kendaraan' => $unit->id_kendaraan, 'id_pemesanan_asal' => $id,
                            'jenis_servis' => $d['servis']['keperluan'], 'tingkat_servis' => $d['servis']['tingkat'],
                            'tgl_perawatan' => now()->toDateString(), 'km_saat_ini' => $unit->km_terakhir,
                            'status' => 'berlangsung', 'catatan' => "Dari pengembalian {$p->nomor}",
                        ]);
                    }
                }

                $p->update(['status' => 'selesai', 'id_admin' => $admin->id_akun]);
                LogAktivitas::catat('Pesanan diselesaikan (pengembalian dan pemeriksaan)', $admin, 'pemesanan', $id);
                if ($perluServis) {
                    LogAktivitas::catat('Unit masuk perawatan dari pengembalian: '.$d['servis']['keperluan'], $admin, 'kendaraan', $unit?->id_kendaraan);
                }

                return [
                    'status' => 'selesai', 'ringkasan' => $ringkasan, 'tagihan_status' => $tagihanStatus,
                    'jaminan_status' => $jaminanStatus, 'unit_status' => $unit ? ($perluServis ? 'dalam_perawatan' : 'tersedia') : null,
                ];
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($tersimpan);
            throw $e;
        }
    }

    /** Status, tanggal aktual, dan odometer: pemeriksaan yang butuh data pesanan. */
    private function periksaPengembalian(Pemesanan $p, array $d): void
    {
        if ($p->status !== 'berjalan') {
            throw new ApiException('Hanya pesanan berjalan yang bisa diselesaikan', 'STATUS_TIDAK_VALID');
        }
        if ($d['tgl_kembali'] < $p->tgl_mulai->toDateString()) {
            throw ValidationException::withMessages(['tgl_kembali' => ['Tanggal kembali tidak boleh sebelum tanggal mulai sewa']]);
        }
        $awal = $p->serahTerima?->odometer_awal;
        if (isset($d['odometer_akhir']) && $awal !== null && (int) $d['odometer_akhir'] < (int) $awal) {
            throw ValidationException::withMessages(['odometer_akhir' => ["Odometer akhir tidak boleh lebih kecil dari odometer awal ({$awal})"]]);
        }
    }

    // ---------------------------------------------------------------- pengajuan pembatalan

    /**
     * Setujui: pesanan dibatalkan (alasan pengajuan_disetujui), unit lepas, dana kembali penuh. Channel yang didukung
     * Midtrans di-refund lewat API sesudah commit; bila gagal atau tidak didukung, jatuh ke transfer_admin.
     */
    public function setujuiPembatalan(Akun $admin, int $id): array
    {
        $bayar = DB::transaction(function () use ($admin, $id) {
            $p = $this->kunciPesanan($id);
            if ($p->status !== 'pengajuan_pembatalan') {
                throw new ApiException('Status pesanan bukan pengajuan pembatalan', 'STATUS_TIDAK_VALID');
            }

            $bayar = $this->pembayaranLunas($id, kunci: true);
            $p->update(['status' => 'dibatalkan', 'alasan_pembatalan' => 'pengajuan_disetujui', 'id_admin' => $admin->id_akun]);
            $bayar?->update(['refund_cara' => Pembayaran::caraRefundUntuk($bayar->metode), 'refund_selesai' => false]);
            LogAktivitas::catat('Pengajuan pembatalan disetujui, unit dilepas', $admin, 'pemesanan', $id);

            return $bayar;
        });

        if ($bayar && $bayar->fresh()->refund_cara === 'midtrans') {
            $this->refund->lewatMidtrans($bayar->id_pembayaran, $bayar->order_id_midtrans, (int) $bayar->jumlah, 'Pembatalan disetujui');
        }

        $bayar = $bayar?->fresh();

        return [
            'status' => 'dibatalkan',
            'refund' => $bayar ? ['metode' => $bayar->metode, 'cara' => $bayar->refund_cara, 'selesai' => (bool) $bayar->refund_selesai] : null,
        ];
    }

    /** Tolak: status kembali ke status_sebelumnya; data rekening refund yang sempat diisi pelanggan dihapus. */
    public function tolakPembatalan(Akun $admin, int $id): array
    {
        return DB::transaction(function () use ($admin, $id) {
            $p = $this->kunciPesanan($id);
            if ($p->status !== 'pengajuan_pembatalan') {
                throw new ApiException('Status pesanan bukan pengajuan pembatalan', 'STATUS_TIDAK_VALID');
            }

            $kembali = $p->status_sebelumnya ?: 'dikonfirmasi';
            $p->update(['status' => $kembali, 'status_sebelumnya' => null, 'id_admin' => $admin->id_akun]);
            $this->pembayaranLunas($id, kunci: true)?->update(['bank' => null, 'no_rekening' => null, 'nama_rekening' => null, 'refund_cara' => null]);
            LogAktivitas::catat("Pengajuan pembatalan ditolak, status kembali ke {$kembali}", $admin, 'pemesanan', $id);

            return ['status' => $kembali];
        });
    }

    /**
     * Admin sudah mentransfer refund di luar sistem. Berlaku untuk SEMUA pembayaran sewa berstatus lunas dengan
     * refund_cara=transfer_admin dan refund_selesai=false, apa pun status pesanannya: pesanan dibatalkan, juga
     * kelebihan bayar (pembayaran ganda) pada pesanan yang tetap dikonfirmasi. Bila ada lebih dari satu yang menunggu
     * pada pesanan yang sama, admin harus menyebut id_pembayaran.
     */
    public function refundSelesai(Akun $admin, int $id, ?int $idPembayaran = null): array
    {
        return DB::transaction(function () use ($admin, $id, $idPembayaran) {
            $this->kunciPesanan($id);
            $menunggu = Pembayaran::query()->where('id_pemesanan', $id)->where('jenis', 'sewa')->where('status', 'lunas')
                ->where('refund_cara', 'transfer_admin')->where('refund_selesai', false)
                ->when($idPembayaran, fn ($q) => $q->where('id_pembayaran', $idPembayaran))
                ->orderBy('id_pembayaran')->lockForUpdate()->get();

            if ($menunggu->isEmpty()) {
                throw new ApiException('Tidak ada refund yang menunggu', 'STATUS_TIDAK_VALID');
            }
            if ($menunggu->count() > 1) {
                throw ValidationException::withMessages(['id_pembayaran' => ['Ada lebih dari satu refund yang menunggu; sebutkan id_pembayaran: '.$menunggu->pluck('id_pembayaran')->implode(', ')]]);
            }

            $bayar = $menunggu->first();
            $bayar->update(['status' => 'dikembalikan', 'refund_selesai' => true]);
            LogAktivitas::catat('Refund ditransfer admin, ditandai selesai', $admin, 'pemesanan', $id);

            return ['refund_selesai' => true, 'id_pembayaran' => $bayar->id_pembayaran];
        });
    }

    /** Tagihan tambahan dibayar di luar Midtrans (tunai atau transfer) dan ditandai admin. */
    public function tandaiTagihanLunas(Akun $admin, int $id, ?string $metode): array
    {
        return DB::transaction(function () use ($admin, $id, $metode) {
            $p = $this->kunciPesanan($id);
            if ($p->status !== 'selesai') {
                throw new ApiException('Tagihan tambahan hanya ada pada pesanan selesai', 'STATUS_TIDAK_VALID');
            }

            $tagihan = Pembayaran::query()->where('id_pemesanan', $id)->where('jenis', 'tambahan')->where('status', 'menunggu')->lockForUpdate()->get();
            if ($tagihan->isEmpty()) {
                throw new ApiException('Tidak ada tagihan tambahan yang belum lunas', 'STATUS_TIDAK_VALID');
            }

            foreach ($tagihan as $t) {
                $t->update(['status' => 'lunas', 'metode' => $metode, 'tgl_bayar' => now()]);
            }
            LogAktivitas::catat('Tagihan tambahan ditandai lunas'.($metode ? " ({$metode})" : ''), $admin, 'pemesanan', $id);

            // Semua tagihan menunggu baru saja lunas: jaminan yang ditahan karena tagihan itu ikut dikembalikan
            $jaminanStatus = null;
            if (Jaminan::where('id_pemesanan', $id)->exists()) {
                if (Jaminan::where('id_pemesanan', $id)->where('dikembalikan', false)->exists()) {
                    $this->kembalikanJaminanOtomatis($id, $admin, 'tagihan lunas');
                }
                $jaminanStatus = 'dikembalikan';
            }

            return ['tagihan_status' => 'lunas', 'jaminan_status' => $jaminanStatus];
        });
    }

    /** Menandai seluruh jaminan pesanan dikembalikan dan mencatatnya. Dipanggil di dalam transaksi yang mengunci pesanan. */
    private function kembalikanJaminanOtomatis(int $id, Akun $admin, string $saat): void
    {
        Jaminan::where('id_pemesanan', $id)->where('dikembalikan', false)->update(['dikembalikan' => true]);
        LogAktivitas::catat("Jaminan dikembalikan otomatis ({$saat})", $admin, 'pemesanan', $id);
    }

    /** Jaminan fisik dikembalikan: pesanan selesai, lepas kunci, jaminan ditahan, dan tagihan tambahan sudah lunas. */
    public function kembalikanJaminan(Akun $admin, int $id): array
    {
        return DB::transaction(function () use ($admin, $id) {
            $p = $this->kunciPesanan($id);
            $jaminan = Jaminan::query()->where('id_pemesanan', $id)->lockForUpdate()->get();

            if ($p->status !== 'selesai' || $p->jenis_sewa !== 'lepas_kunci' || $jaminan->isEmpty() || $jaminan->every->dikembalikan) {
                throw new ApiException('Tidak ada jaminan yang ditahan untuk dikembalikan', 'STATUS_TIDAK_VALID');
            }
            if (Pembayaran::where('id_pemesanan', $id)->where('jenis', 'tambahan')->where('status', 'menunggu')->exists()) {
                throw new ApiException('Jaminan ditahan sampai tagihan lunas', 'TAGIHAN_BELUM_LUNAS');
            }

            Jaminan::where('id_pemesanan', $id)->update(['dikembalikan' => true]);
            LogAktivitas::catat('Jaminan dikembalikan', $admin, 'pemesanan', $id);

            return ['jaminan_status' => 'dikembalikan'];
        });
    }

    public function simpanCatatan(Akun $admin, int $id, ?string $catatan): array
    {
        return DB::transaction(function () use ($admin, $id, $catatan) {
            $this->kunciPesanan($id)->update(['catatan_admin' => $catatan]);
            LogAktivitas::catat('Catatan admin diperbarui', $admin, 'pemesanan', $id);

            return ['catatan_admin' => $catatan];
        });
    }

    // ---------------------------------------------------------------- bantu

    private function kunciPesanan(int $id): Pemesanan
    {
        return Pemesanan::query()->lockForUpdate()->find($id) ?? throw $this->tidakDitemukan();
    }

    private function pembayaranLunas(int $idPemesanan, bool $kunci = false): ?Pembayaran
    {
        return Pembayaran::query()->where('id_pemesanan', $idPemesanan)->where('jenis', 'sewa')->where('status', 'lunas')
            ->orderByDesc('id_pembayaran')->when($kunci, fn ($q) => $q->lockForUpdate())->first();
    }

    private function tidakDitemukan(): ApiException
    {
        return new ApiException('Pesanan tidak ditemukan', 'TIDAK_DITEMUKAN', 404);
    }
}
