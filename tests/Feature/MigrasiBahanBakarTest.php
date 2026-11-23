<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** bahan_bakar tipe kendaraan: hanya bensin dan diesel. Migrasi menolak berjalan (tanpa mengubah data) bila masih ada tipe listrik. */
class MigrasiBahanBakarTest extends TestCase
{
    use RefreshDatabase;

    private const BERKAS = '2026_11_22_000002_hapus_listrik_dari_bahan_bakar';

    /** @var list<int> baris yang dibuat tes ini */
    private array $idDibuat = [];

    private function migrasi(): object
    {
        return require database_path('migrations/'.self::BERKAS.'.php');
    }

    private function tipe(string $nama, string $bahanBakar, ?string $dihapus = null): int
    {
        return $this->idDibuat[] = DB::table('tipe_kendaraan')->insertGetId([
            'nama' => $nama, 'merk' => 'Uji', 'kursi' => 5, 'bahan_bakar' => $bahanBakar, 'harga_sopir_per_hari' => 1, 'deleted_at' => $dihapus,
        ]);
    }

    /**
     * ALTER TABLE di MySQL meng-commit transaksi, jadi baris yang dibuat sesudah down() tidak ikut di-rollback oleh RefreshDatabase.
     * Hapus semuanya dan pulihkan enum, supaya tes lain tidak melihat sisa data atau kolom yang berubah.
     */
    private function pulihkan(): void
    {
        DB::table('tipe_kendaraan')->whereIn('id_tipe', $this->idDibuat)->delete();
        DB::table('tipe_kendaraan')->where('bahan_bakar', 'listrik')->delete();
        $this->migrasi()->up();
    }

    /** true bila database menolak menyimpan bahan_bakar listrik (mode strict MySQL). */
    private function listrikDitolak(): bool
    {
        try {
            $this->tipe('Coba'.uniqid(), 'listrik');
        } catch (QueryException) {
            return true;
        }
        DB::table('tipe_kendaraan')->where('bahan_bakar', 'listrik')->delete(); // lolos: kolom masih menerima listrik

        return false;
    }

    public function test_kolom_hanya_menerima_bensin_dan_diesel(): void
    {
        $this->tipe('Avanza', 'bensin');
        $this->tipe('Dakar', 'diesel');
        $this->assertSame(2, DB::table('tipe_kendaraan')->count());

        $this->assertTrue($this->listrikDitolak(), 'Database harus menolak bahan_bakar listrik');
    }

    public function test_naik_berhenti_dengan_pesan_jelas_bila_masih_ada_tipe_listrik_termasuk_di_tempat_sampah_dan_data_tidak_disentuh(): void
    {
        $this->migrasi()->down(); // kembali ke enum lama (bensin, diesel, listrik)

        try {
            $aktif = $this->tipe('Brio Listrik', 'listrik');
            $sampah = $this->tipe('Atto', 'listrik', now()->toDateTimeString());

            try {
                $this->migrasi()->up();
                $this->fail('Migrasi seharusnya berhenti');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('listrik', $e->getMessage());
                $this->assertStringContainsString("#{$aktif}", $e->getMessage());
                $this->assertStringContainsString("#{$sampah}", $e->getMessage(), 'Tipe di tempat sampah ikut dihitung');
                $this->assertStringContainsString('Data tidak diubah', $e->getMessage());
            }
            $this->assertSame(2, DB::table('tipe_kendaraan')->where('bahan_bakar', 'listrik')->count(), 'Data lama tidak disentuh');

            // Hanya satu yang diselesaikan: migrasi tetap berhenti
            DB::table('tipe_kendaraan')->where('id_tipe', $aktif)->update(['bahan_bakar' => 'bensin']);
            $this->expectException(RuntimeException::class);
            $this->migrasi()->up();
        } finally {
            $this->pulihkan();
        }
    }

    public function test_naik_berhasil_tanpa_tipe_listrik_dan_bisa_turun_naik_lagi(): void
    {
        $this->migrasi()->down();

        try {
            $id = $this->tipe('Lama', 'diesel');
            $this->migrasi()->up();

            $this->assertSame('diesel', DB::table('tipe_kendaraan')->where('id_tipe', $id)->value('bahan_bakar'), 'Data bensin dan diesel tetap');
            $this->assertTrue($this->listrikDitolak());
        } finally {
            $this->pulihkan();
        }
    }
}
