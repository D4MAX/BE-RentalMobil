<?php

namespace Tests\Feature;

use App\Models\DetailKendaraan;
use App\Models\Kendaraan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** GET /admin/unit: jumlah query tidak boleh bertambah mengikuti jumlah unit (sebelumnya satu query "sedang disewa" per unit). */
class DaftarUnitQueryTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private function jumlahQueryDaftarUnit(string $token): int
    {
        $this->app['auth']->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson('/api/v1/admin/unit')->assertOk();
        $jumlah = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $jumlah;
    }

    public function test_jumlah_query_tetap_walau_unit_bertambah_dan_status_disewa_tetap_benar(): void
    {
        $this->freezeTime(); // Sanctum menulis last_used_at per detik: tanpa ini jumlah query bisa beda 1 saat detik berganti
        [, $token] = $this->buatAdmin();
        $this->withToken($token)->getJson('/api/v1/admin/unit')->assertOk(); // pemanasan: last_used_at terisi sekali
        [$p] = $this->buatPelanggan();
        $tipe = $this->buatTipe(2);
        $this->buatPesananBerjalan($p, $tipe);
        $sedikit = $this->jumlahQueryDaftarUnit($token);

        $tipeLain = $this->buatTipe(8, 'Lain');
        foreach (Kendaraan::where('id_tipe', $tipeLain->id_tipe)->get() as $u) {
            DetailKendaraan::create(['id_kendaraan' => $u->id_kendaraan, 'no_rangka' => "R{$u->id_kendaraan}", 'no_mesin' => "M{$u->id_kendaraan}"]);
        }
        $banyak = $this->jumlahQueryDaftarUnit($token);

        $this->assertSame($sedikit, $banyak, 'Query per unit (N+1) terdeteksi');

        $this->app['auth']->forgetGuards();
        $data = $this->withToken($token)->getJson('/api/v1/admin/unit')->json('data');
        $this->assertSame(10, count($data));
        $this->assertSame(1, collect($data)->where('sedang_disewa', true)->count());
        $this->assertSame(1, collect($data)->where('status_tampil', 'disewa')->count());
    }
}
