<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\DetailKendaraan;
use App\Models\Kendaraan;
use App\Models\TipeKendaraan;
use App\Services\KendaraanAdminService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** Indeks unik no_rangka, no_mesin, no_bpkb di detail_kendaraan: migrasi naik dan turun, serta jaring pengaman 422. */
class IndeksUnikNomorUnitTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private const KOLOM = ['no_rangka', 'no_mesin', 'no_bpkb'];

    private ?TipeKendaraan $tipeBersama = null;

    private function migrasi(): object
    {
        return require database_path('migrations/2026_11_15_000003_add_unique_nomor_to_detail_kendaraan.php');
    }

    /** @return list<string> kolom yang punya indeks unik tunggal */
    private function kolomUnik(): array
    {
        return collect(Schema::getIndexes('detail_kendaraan'))->filter(fn ($i) => $i['unique'] && count($i['columns']) === 1)
            ->map(fn ($i) => $i['columns'][0])->values()->all();
    }

    private function unit(string $plat, array $detail = []): Kendaraan
    {
        $tipe = $this->tipeBersama ??= $this->buatTipe(0);
        $u = Kendaraan::create(['id_tipe' => $tipe->id_tipe, 'plat_nomor' => $plat, 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual', 'status' => 'tersedia']);
        DetailKendaraan::create(['id_kendaraan' => $u->id_kendaraan] + $detail);

        return $u;
    }

    public function test_migrasi_membuat_indeks_unik_dan_turun_lalu_naik_kembali(): void
    {
        foreach (self::KOLOM as $k) {
            $this->assertContains($k, $this->kolomUnik());
        }

        $this->migrasi()->down();
        foreach (self::KOLOM as $k) {
            $this->assertNotContains($k, $this->kolomUnik(), 'Turun menghapus indeks');
        }
        $this->assertContains('id_kendaraan', $this->kolomUnik(), 'Indeks lain tidak ikut terhapus');

        $this->migrasi()->up();
        foreach (self::KOLOM as $k) {
            $this->assertContains($k, $this->kolomUnik(), 'Naik membuat indeks lagi');
        }
    }

    public function test_database_menolak_nomor_ganda_tanpa_peka_huruf_tetapi_null_boleh_banyak(): void
    {
        $asli = ['no_rangka' => 'MH4001', 'no_mesin' => 'L1', 'no_bpkb' => 'B1'];
        $this->unit('N 1 AB', $asli);

        $nomor = 100;
        foreach (self::KOLOM as $k) {
            foreach ([$asli[$k], strtolower($asli[$k])] as $nilai) {
                try {
                    $this->unit('N '.(++$nomor).' ZZ', [$k => $nilai]);
                    $this->fail("Nomor ganda {$k} lolos");
                } catch (QueryException $e) {
                    $this->assertStringContainsString($k, $e->getMessage());
                }
            }
        }

        // NULL boleh banyak
        $this->unit('N 2 AB');
        $this->unit('N 3 AB');
        $this->unit('N 4 AB', ['no_rangka' => null, 'no_mesin' => null, 'no_bpkb' => null]);
        $this->assertSame(4, DetailKendaraan::count());
    }

    public function test_naik_gagal_dengan_pesan_jelas_bila_ada_nomor_ganda_dan_teks_kosong_dijadikan_null(): void
    {
        $this->migrasi()->down();
        $this->unit('N 1 AB', ['no_rangka' => 'MH4001', 'no_mesin' => '', 'no_bpkb' => '  ']);
        $kedua = $this->unit('N 2 AB', ['no_rangka' => 'mh4001', 'no_mesin' => '', 'no_bpkb' => '']);

        try {
            $this->migrasi()->up();
            $this->fail('Migrasi seharusnya berhenti');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no_rangka "MH4001" dipakai unit', $e->getMessage());
            $this->assertStringNotContainsString('no_mesin', $e->getMessage(), 'Teks kosong tidak dianggap ganda');
        }
        $this->assertNotContains('no_rangka', $this->kolomUnik(), 'Tidak ada indeks setengah jadi');

        // Setelah dikoreksi, naik berhasil dan teks kosong sudah menjadi NULL
        DetailKendaraan::where('id_kendaraan', $kedua->id_kendaraan)->update(['no_rangka' => null]);
        $this->migrasi()->up();
        $this->assertSame(0, DB::table('detail_kendaraan')->whereNotNull('no_mesin')->count());
        $this->assertSame(0, DB::table('detail_kendaraan')->whereNotNull('no_bpkb')->count());
    }

    public function test_service_mengubah_penolakan_indeks_menjadi_422_per_kolom_bukan_500(): void
    {
        $admin = Akun::create(['nama' => 'Adm', 'email' => 'a@mail.test', 'password' => 'abc12345', 'role' => 'admin', 'email_terverifikasi_at' => now()]);
        $tipe = $this->buatTipe(0);
        $this->tipeBersama = $tipe;
        $this->unit('N 1 AB', ['no_rangka' => 'MH4001', 'no_mesin' => 'L1', 'no_bpkb' => 'B1']);
        $layanan = app(KendaraanAdminService::class);

        // Melewati pengecekan di controller (seperti dua permintaan bersamaan): yang menolak adalah indeks database
        $pesan = ['no_rangka' => 'No rangka sudah dipakai unit lain.', 'no_mesin' => 'No mesin sudah dipakai unit lain.', 'no_bpkb' => 'No BPKB sudah dipakai unit lain.'];
        foreach (['no_rangka' => 'MH4001', 'no_mesin' => 'l1', 'no_bpkb' => 'B1'] as $kolom => $nilai) {
            try {
                $layanan->buatUnit($admin, ['id_tipe' => $tipe->id_tipe, 'plat_nomor' => 'N 7 ZZ', 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual', 'detail' => [$kolom => $nilai]]);
                $this->fail("{$kolom} lolos");
            } catch (ValidationException $e) {
                $this->assertSame(["detail.{$kolom}" => [$pesan[$kolom]]], $e->errors());
            }
        }
        $this->assertSame(1, Kendaraan::count(), 'Unit yang gagal ikut dibatalkan');

        // Ubah unit lain menjadi nomor yang sudah dipakai
        $lain = $this->unit('N 2 AB', ['no_rangka' => 'MH9']);
        try {
            $layanan->ubahUnit($admin, $lain->id_kendaraan, ['id_tipe' => $tipe->id_tipe, 'plat_nomor' => 'N 2 AB', 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual', 'detail' => ['no_rangka' => 'mh4001']]);
            $this->fail('Ubah lolos');
        } catch (ValidationException $e) {
            $this->assertSame(['detail.no_rangka' => [$pesan['no_rangka']]], $e->errors());
        }
        $this->assertSame('MH9', DetailKendaraan::where('id_kendaraan', $lain->id_kendaraan)->value('no_rangka'));

        // Plat ganda lewat jalur yang sama
        try {
            $layanan->buatUnit($admin, ['id_tipe' => $tipe->id_tipe, 'plat_nomor' => 'N 1 AB', 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual']);
            $this->fail('Plat ganda lolos');
        } catch (ValidationException $e) {
            $this->assertSame(['plat_nomor' => ['Plat nomor sudah dipakai unit lain.']], $e->errors());
        }
    }
}
