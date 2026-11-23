<?php

namespace Tests\Feature;

use App\Models\TipeKendaraan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** Indeks unik (nama, merk) di tipe_kendaraan dan kolom km_awal di kendaraan: migrasi naik dan turun. */
class MigrasiTipeDanKmAwalTest extends TestCase
{
    use RefreshDatabase;

    private function migrasi(string $berkas): object
    {
        return require database_path("migrations/{$berkas}.php");
    }

    private function adaIndeksUnik(): bool
    {
        return collect(Schema::getIndexes('tipe_kendaraan'))->contains(fn ($i) => $i['unique'] && $i['columns'] === ['nama', 'merk']);
    }

    private function tipe(string $nama, string $merk): void
    {
        DB::table('tipe_kendaraan')->insert(['nama' => $nama, 'merk' => $merk, 'kursi' => 5, 'bahan_bakar' => 'bensin', 'harga_sopir_per_hari' => 1]);
    }

    public function test_indeks_unik_tipe_naik_turun_dan_menolak_ganda_tanpa_peka_huruf(): void
    {
        $berkas = '2026_11_18_000001_add_unique_nama_merk_to_tipe_kendaraan';
        $this->assertTrue($this->adaIndeksUnik());

        $this->tipe('Avanza', 'Toyota');
        try {
            $this->tipe('avanza', 'TOYOTA');
            $this->fail('Ganda lolos');
        } catch (QueryException $e) {
            $this->assertStringContainsString('tipe_kendaraan_nama_merk_unique', $e->getMessage());
        }
        $this->tipe('Avanza', 'Daihatsu'); // merk lain boleh

        $this->migrasi($berkas)->down();
        $this->assertFalse($this->adaIndeksUnik());
        $this->tipe('avanza', 'TOYOTA'); // tanpa indeks, ganda masuk

        // Naik gagal dengan pesan jelas dan data tidak diubah
        try {
            $this->migrasi($berkas)->up();
            $this->fail('Migrasi seharusnya berhenti');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('merk "Toyota"', $e->getMessage());
            $this->assertStringContainsString('tipe', $e->getMessage());
        }
        $this->assertFalse($this->adaIndeksUnik());
        $this->assertSame(3, TipeKendaraan::count(), 'Data lama tidak disentuh');

        DB::table('tipe_kendaraan')->where('nama', 'avanza')->delete();
        $this->migrasi($berkas)->up();
        $this->assertTrue($this->adaIndeksUnik());
    }

    public function test_km_awal_diisi_dari_km_terakhir_saat_migrasi_dan_otomatis_saat_unit_dibuat(): void
    {
        $berkas = '2026_11_18_000002_add_km_awal_to_kendaraan';
        $this->assertTrue(Schema::hasColumn('kendaraan', 'km_awal'));

        // Unit lama (sebelum kolom ada): km_awal diisi dari km_terakhir
        $this->migrasi($berkas)->down();
        $this->assertFalse(Schema::hasColumn('kendaraan', 'km_awal'));
        $this->tipe('Brio', 'Honda');
        $idTipe = DB::table('tipe_kendaraan')->value('id_tipe');
        foreach ([['N 1 AB', 45800], ['N 2 AB', 0]] as [$plat, $km]) {
            DB::table('kendaraan')->insert(['id_tipe' => $idTipe, 'plat_nomor' => $plat, 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual', 'km_terakhir' => $km, 'status' => 'tersedia']);
        }

        $this->migrasi($berkas)->up();
        $this->assertSame([45800, 0], DB::table('kendaraan')->orderBy('id_kendaraan')->pluck('km_awal')->map(fn ($k) => (int) $k)->all());

        // Unit baru lewat model: km_awal = km_terakhir saat dibuat, default 0
        $a = \App\Models\Kendaraan::create(['id_tipe' => $idTipe, 'plat_nomor' => 'N 3 AB', 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual', 'km_terakhir' => 1200, 'status' => 'tersedia']);
        $b = \App\Models\Kendaraan::create(['id_tipe' => $idTipe, 'plat_nomor' => 'N 4 AB', 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual', 'status' => 'tersedia']);
        $this->assertSame([1200, 0], [(int) $a->refresh()->km_awal, (int) $b->refresh()->km_awal]);

        // Mengubah km_terakhir sesudahnya tidak menggeser km_awal
        $a->update(['km_terakhir' => 9000]);
        $this->assertSame(1200, (int) $a->refresh()->km_awal);
    }
}
