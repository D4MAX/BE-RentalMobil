<?php

namespace Tests\Feature;

use App\Models\DetailKendaraan;
use App\Models\Kendaraan;
use App\Models\TipeKendaraan;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Data demo harus memenuhi aturan unit: no_rangka dan no_mesin terisi dan unik. */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_demo_punya_no_rangka_dan_no_mesin_unik(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class); // idempoten

        $detail = DetailKendaraan::all();
        $this->assertCount(5, $detail);
        foreach (['no_rangka', 'no_mesin'] as $kolom) {
            $nilai = $detail->pluck($kolom);
            $this->assertSame(0, $nilai->filter(fn ($v) => $v === null || trim($v) === '')->count(), "{$kolom} terisi semua");
            $this->assertCount(5, $nilai->unique(), "{$kolom} unik");
        }
        $this->assertSame('MHDEMON1234AB', Kendaraan::where('plat_nomor', 'N 1234 AB')->first()->detail->no_rangka);
    }

    public function test_seeder_memulihkan_data_demo_yang_ada_di_tempat_sampah_bukan_menggandakan(): void
    {
        $this->seed(DemoSeeder::class);
        Kendaraan::where('plat_nomor', 'N 1234 AB')->first()->delete();
        Kendaraan::where('plat_nomor', 'N 3456 EF')->first()->delete();
        TipeKendaraan::where('nama', 'Xenia')->first()->delete(); // tipe tanpa unit aktif, seperti hapus lewat API

        $this->seed(DemoSeeder::class);

        $this->assertSame(3, TipeKendaraan::count());
        $this->assertSame(5, Kendaraan::count());
        $this->assertSame(0, Kendaraan::onlyTrashed()->count() + TipeKendaraan::onlyTrashed()->count());
    }
}
