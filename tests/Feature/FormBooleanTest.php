<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** Form (Swagger, HTML) mengirim boolean sebagai teks "true" atau "false"; endpoint admin harus menerimanya seperti JSON. */
class FormBooleanTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->token] = $this->buatAdmin();
    }

    /** Body form: nilai dikirim sebagai teks, persis seperti Swagger. */
    private function form(string $metode, string $url, array $isi)
    {
        $this->app['auth']->forgetGuards();
        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token, 'Content-Type' => 'application/x-www-form-urlencoded']);

        return $this->call($metode, $url, $isi, [], [], $server);
    }

    public function test_status_unit_menerima_konfirmasi_teks_true_dan_false(): void
    {
        $unit = $this->buatTipe(1)->kendaraan()->first();
        $url = "/api/v1/admin/unit/{$unit->id_kendaraan}/status";

        $this->form('PATCH', $url, ['status' => 'tersedia', 'konfirmasi' => 'false'])->assertOk();
        $this->form('PATCH', $url, ['status' => 'tersedia', 'konfirmasi' => 'true'])->assertOk();
        $this->form('PATCH', $url, ['status' => 'tersedia', 'konfirmasi' => 'bukan-boolean'])->assertStatus(422)->assertJsonValidationErrors('konfirmasi');
    }

    public function test_review_admin_menerima_tampil_di_katalog_teks_true_dan_false(): void
    {
        [$p] = $this->buatPelanggan();
        $pesanan = $this->buatPesanan($p, $this->buatTipe(1), ['status' => 'selesai']);
        $review = Review::create(['id_pemesanan' => $pesanan->id_pemesanan, 'rating' => 5, 'tgl_review' => now()->toDateString(), 'tampil_di_katalog' => false]);
        $url = "/api/v1/admin/review/{$review->id_review}";

        $this->form('PATCH', $url, ['tampil_di_katalog' => 'true'])->assertOk()->assertJsonPath('data.tampil_di_katalog', true);
        $this->assertTrue($review->fresh()->tampil_di_katalog);

        $this->form('PATCH', $url, ['tampil_di_katalog' => 'false'])->assertOk()->assertJsonPath('data.tampil_di_katalog', false);
        $this->assertFalse($review->fresh()->tampil_di_katalog);

        $this->form('PATCH', $url, [])->assertStatus(422)->assertJsonValidationErrors('tampil_di_katalog');
    }
}
