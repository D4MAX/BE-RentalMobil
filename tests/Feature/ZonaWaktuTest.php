<?php

namespace Tests\Feature;

use App\Models\Pemesanan;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** Zona waktu aplikasi Asia/Jakarta: "besok", tgl_kembali, dan batas pembayaran mengikuti tanggal WIB, bukan UTC. */
class ZonaWaktuTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    public function test_zona_waktu_aplikasi_jakarta(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        $this->assertSame('Asia/Jakarta', date_default_timezone_get());
        $this->assertSame('+07:00', now()->format('P'));
    }

    public function test_besok_dan_tgl_kembali_dihitung_menurut_tanggal_jakarta(): void
    {
        // 13 Nov 2026 18:30 UTC = 14 Nov 2026 01:30 WIB: "hari ini" 14 Nov, "besok" 15 Nov
        $this->travelTo(Carbon::parse('2026-11-13 18:30:00', 'UTC'));
        $this->assertSame('2026-11-14', now()->toDateString());

        [, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(2);
        $isi = fn (string $mulai) => [
            'id_tipe' => $t->id_tipe, 'jenis_sewa' => 'lepas_kunci', 'transmisi' => 'manual', 'tgl_mulai' => $mulai,
            'durasi' => 3, 'jam_serah' => '09:00', 'metode_ambil' => 'ambil_di_kantor',
            'setuju_syarat' => true, 'setuju_syarat_jaminan' => true,
        ];

        // 14 Nov = hari ini menurut WIB (besok menurut UTC): harus ditolak
        $this->withToken($token)->postJson('/api/v1/pemesanan', $isi('2026-11-14'))->assertStatus(422)->assertJsonValidationErrors('tgl_mulai');
        $this->getJson('/api/v1/katalog/tipe?tgl_mulai=2026-11-14&durasi=1')->assertStatus(422);

        $r = $this->withToken($token)->postJson('/api/v1/pemesanan', $isi('2026-11-15'))->assertCreated()
            ->assertJsonPath('data.tgl_kembali', '2026-11-17')
            ->assertJsonPath('data.batas_pembayaran', '2026-11-14T02:30:00+07:00');

        $p = Pemesanan::findOrFail($r->json('data.id'));
        $this->assertSame('2026-11-14', $p->tgl_pesan->toDateString());
        $this->assertSame('2026-11-14 02:30:00', $p->batas_pembayaran->format('Y-m-d H:i:s'));
        $this->getJson('/api/v1/katalog/tipe?tgl_mulai=2026-11-15&durasi=1')->assertOk();
    }

    public function test_batas_hari_ke_depan_30_hari_menurut_tanggal_jakarta(): void
    {
        $this->travelTo(Carbon::parse('2026-11-13 18:30:00', 'UTC')); // 14 Nov WIB
        $this->getJson('/api/v1/katalog/tipe?tgl_mulai=2026-12-14&durasi=1')->assertOk();
        $this->getJson('/api/v1/katalog/tipe?tgl_mulai=2026-12-15&durasi=1')->assertStatus(422);
    }
}
