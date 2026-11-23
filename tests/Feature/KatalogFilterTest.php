<?php

namespace Tests\Feature;

use App\Models\Kendaraan;
use App\Models\TipeKendaraan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /katalog/tipe: filter kursi dan transmisi, dengan "any" berarti semua (sama dengan tidak diisi). */
class KatalogFilterTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/katalog/tipe';

    private function tipe(string $nama, int $kursi, string $transmisi): void
    {
        $t = TipeKendaraan::create(['nama' => $nama, 'merk' => 'M', 'kursi' => $kursi, 'bahan_bakar' => 'bensin', 'harga_lepas_kunci_manual' => 300000, 'harga_lepas_kunci_matic' => 350000]);
        Kendaraan::create(['id_tipe' => $t->id_tipe, 'plat_nomor' => "N {$t->id_tipe} AB", 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => $transmisi, 'status' => 'tersedia']);
    }

    private function nama(string $query): array
    {
        $mulai = now()->addDays(5)->toDateString();

        return collect($this->getJson(self::URL."?tgl_mulai={$mulai}&durasi=2{$query}")->assertOk()->json('data'))->pluck('nama')->sort()->values()->all();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tipe('Brio', 5, 'manual');
        $this->tipe('Avanza', 7, 'matic');
    }

    public function test_tanpa_filter_menampilkan_semua_tipe(): void
    {
        $this->assertSame(['Avanza', 'Brio'], $this->nama(''));
    }

    public function test_kursi_angka_hanya_menampilkan_tipe_dengan_kursi_itu(): void
    {
        $this->assertSame(['Brio'], $this->nama('&kursi=5'));
        $this->assertSame(['Avanza'], $this->nama('&kursi=7'));
    }

    public function test_transmisi_hanya_menampilkan_tipe_yang_punya_unit_dengan_transmisi_itu(): void
    {
        $this->assertSame(['Avanza'], $this->nama('&transmisi=matic'));
        $this->assertSame(['Brio'], $this->nama('&transmisi=manual'));
    }

    public function test_any_berarti_semua_untuk_kursi_dan_transmisi(): void
    {
        $this->assertSame(['Avanza', 'Brio'], $this->nama('&kursi=any'));
        $this->assertSame(['Avanza', 'Brio'], $this->nama('&transmisi=any'));
        $this->assertSame(['Avanza', 'Brio'], $this->nama('&kursi=ANY&transmisi=any'));
        $this->assertSame(['Brio'], $this->nama('&kursi=5&transmisi=any'));
        $this->assertSame([], $this->nama('&kursi=5&transmisi=matic'), 'Filter lain tetap berlaku bila hanya satu yang any');
    }

    public function test_nilai_selain_angka_atau_any_ditolak(): void
    {
        $mulai = now()->addDays(5)->toDateString();

        $this->getJson(self::URL."?tgl_mulai={$mulai}&durasi=2&kursi=banyak")->assertStatus(422)->assertJsonValidationErrors('kursi');
        $this->getJson(self::URL."?tgl_mulai={$mulai}&durasi=2&transmisi=otomatis")->assertStatus(422)->assertJsonValidationErrors('transmisi');
    }
}
