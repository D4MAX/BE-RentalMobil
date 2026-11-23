<?php

namespace Tests\Feature;

use App\Models\Pembayaran;
use App\Models\Pengaturan;
use App\Models\Review;
use App\Services\DokumenPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** GET /pemesanan/{id}/nota, POST /pemesanan/{id}/review, PATCH /admin/review/{id}. */
class NotaReviewTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private const API = '/api/v1/pemesanan';

    private function sebagai(string $token, string $metode, string $url, array $isi = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($metode, $url, $isi);
    }

    // ---------------------------------------------------------------- nota

    public function test_nota_pdf_untuk_pesanan_dikonfirmasi_ke_atas(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(8);

        foreach (['dikonfirmasi', 'berjalan', 'selesai', 'pengajuan_pembatalan'] as $status) {
            $p = $this->lunasi($this->buatPesanan($budi, $t, ['status' => $status]));
            $r = $this->sebagai($token, 'GET', self::API."/{$p->id_pemesanan}/nota")->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $r->getContent(), $status);
            $this->assertStringContainsString("nota-{$p->nomor}.pdf", $r->headers->get('Content-Disposition'));
        }
    }

    public function test_nota_ditolak_untuk_pesanan_belum_dibayar_dibatalkan_milik_orang_lain_atau_admin(): void
    {
        [$budi, $token] = $this->buatPelanggan('budi@mail.test');
        [, $tokenSari] = $this->buatPelanggan('sari@mail.test');
        [, $tokenAdmin] = $this->buatAdmin();
        $t = $this->buatTipe(8);

        foreach (['menunggu_pembayaran', 'dibatalkan'] as $status) {
            $p = $this->buatPesanan($budi, $t, ['status' => $status]);
            $this->sebagai($token, 'GET', self::API."/{$p->id_pemesanan}/nota")->assertStatus(409)
                ->assertJsonPath('code', 'STATUS_TIDAK_VALID')->assertJsonPath('message', 'Pesanan belum dibayar');
        }

        $p = $this->lunasi($this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']));
        $this->sebagai($tokenSari, 'GET', self::API."/{$p->id_pemesanan}/nota")->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');
        $this->sebagai($tokenAdmin, 'GET', self::API."/{$p->id_pemesanan}/nota")->assertForbidden();
        $this->sebagai($token, 'GET', self::API.'/9999/nota')->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson(self::API."/{$p->id_pemesanan}/nota")->assertUnauthorized();
    }

    public function test_isi_nota_memuat_rincian_pembayaran_dan_tidak_membocorkan_data_internal(): void
    {
        [$budi] = $this->buatPelanggan();
        $budi->update(['no_identitas' => '3573010000000009']);
        $p = $this->buatPesanan($budi, $this->buatTipe(1, 'Avanza'), ['status' => 'dikonfirmasi', 'durasi' => 3, 'catatan_admin' => 'RAHASIA-ADMIN', 'lokasi_jemput' => 'Jl. Veteran No. 5']);
        $this->lunasi($p, 'qris');
        Pengaturan::updateOrCreate(['kunci' => 'alamat'], ['nilai' => 'Jl. Joyo Agung No. 103, Malang']);

        $html = app(DokumenPdfService::class)->notaHtml($p->fresh());

        foreach ([$p->nomor, 'Budi', 'Avanza', 'Lepas kunci', 'Rp300.000', 'Rp900.000', '3 hari', 'qris', 'lunas', $p->nomor.'-1', 'Jl. Veteran No. 5', 'Jl. Joyo Agung No. 103, Malang', 'dikonfirmasi'] as $harus) {
            $this->assertStringContainsString($harus, $html);
        }
        $this->assertStringNotContainsString('RAHASIA-ADMIN', $html);
        $this->assertStringNotContainsString('3573010000000009', $html);
    }

    public function test_nota_memuat_tagihan_tambahan_dan_meloloskan_karakter_html(): void
    {
        [$budi] = $this->buatPelanggan();
        $budi->update(['nama' => '<script>alert(1)</script>']);
        $p = $this->lunasi($this->buatPesanan($budi, $this->buatTipe(1), ['status' => 'selesai']));
        Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'tambahan', 'jumlah' => 530000, 'status' => 'menunggu']);

        $html = app(DokumenPdfService::class)->notaHtml($p->fresh());

        $this->assertStringContainsString('Rp530.000', $html);
        $this->assertStringContainsString('menunggu', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ---------------------------------------------------------------- review

    public function test_beri_review_pesanan_selesai_sekali_saja_dan_tersembunyi_di_katalog(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(1), ['status' => 'selesai']);
        $this->assertTrue($this->sebagai($token, 'GET', self::API."/{$p->id_pemesanan}")->json('data.aksi.bisa_review'));

        $this->sebagai($token, 'POST', self::API."/{$p->id_pemesanan}/review", ['rating' => 5, 'komentar' => 'Mobil bersih dan nyaman'])->assertCreated()
            ->assertJsonPath('data', ['rating' => 5, 'komentar' => 'Mobil bersih dan nyaman']);

        $review = Review::where('id_pemesanan', $p->id_pemesanan)->sole();
        $this->assertSame([5, 'Mobil bersih dan nyaman', now()->toDateString(), false], [$review->rating, $review->komentar, $review->tgl_review->toDateString(), $review->tampil_di_katalog]);
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'id_akun' => $budi->id_akun, 'pelaku' => 'pelanggan', 'aksi' => 'Pelanggan memberi review']);
        $this->assertFalse($this->sebagai($token, 'GET', self::API."/{$p->id_pemesanan}")->json('data.aksi.bisa_review'));

        $this->sebagai($token, 'POST', self::API."/{$p->id_pemesanan}/review", ['rating' => 1])->assertStatus(409)->assertJsonPath('code', 'SUDAH_ADA_REVIEW');
        $this->assertSame(1, Review::count());

        // Belum tampil di katalog sebelum admin menampilkannya
        $mulai = now()->addDays(3)->toDateString();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson("/api/v1/katalog/tipe/{$p->id_tipe}?tgl_mulai={$mulai}&durasi=1")->assertJsonPath('data.review', []);
    }

    public function test_review_tanpa_komentar_dan_validasi(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(5);
        $p = $this->buatPesanan($budi, $t, ['status' => 'selesai']);
        $url = self::API."/{$p->id_pemesanan}/review";

        $this->sebagai($token, 'POST', $url, [])->assertStatus(422)->assertJsonValidationErrors('rating');
        foreach ([0, 6, 'bagus', 2.5] as $salah) {
            $this->sebagai($token, 'POST', $url, ['rating' => $salah])->assertStatus(422)->assertJsonValidationErrors('rating');
        }
        $this->sebagai($token, 'POST', $url, ['rating' => 5, 'komentar' => str_repeat('x', 501)])->assertStatus(422)->assertJsonValidationErrors('komentar');
        $this->assertSame(0, Review::count());

        $this->sebagai($token, 'POST', $url, ['rating' => 3, 'komentar' => str_repeat('x', 500)])->assertCreated();
        $q = $this->buatPesanan($budi, $t, ['status' => 'selesai']);
        $this->sebagai($token, 'POST', self::API."/{$q->id_pemesanan}/review", ['rating' => 4])->assertCreated()->assertJsonPath('data.komentar', null);
    }

    public function test_review_hanya_untuk_pesanan_selesai_milik_sendiri(): void
    {
        [$budi, $token] = $this->buatPelanggan('budi@mail.test');
        [, $tokenSari] = $this->buatPelanggan('sari@mail.test');
        [, $tokenAdmin] = $this->buatAdmin();
        $t = $this->buatTipe(8);

        foreach (['menunggu_pembayaran', 'dikonfirmasi', 'berjalan', 'pengajuan_pembatalan', 'dibatalkan'] as $status) {
            $p = $this->buatPesanan($budi, $t, ['status' => $status]);
            $this->sebagai($token, 'POST', self::API."/{$p->id_pemesanan}/review", ['rating' => 5])->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        }

        $selesai = $this->buatPesanan($budi, $t, ['status' => 'selesai']);
        $this->sebagai($tokenSari, 'POST', self::API."/{$selesai->id_pemesanan}/review", ['rating' => 5])->assertNotFound();
        $this->sebagai($tokenAdmin, 'POST', self::API."/{$selesai->id_pemesanan}/review", ['rating' => 5])->assertForbidden();
        $this->sebagai($token, 'POST', self::API.'/9999/review', ['rating' => 5])->assertNotFound();
        $this->assertSame(0, Review::count());
    }

    // ---------------------------------------------------------------- admin menampilkan review

    public function test_admin_menampilkan_dan_menyembunyikan_review_di_katalog(): void
    {
        [$admin, $tokenAdmin] = $this->buatAdmin();
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(1);
        $p = $this->buatPesanan($budi, $t, ['status' => 'selesai']);
        $this->sebagai($token, 'POST', self::API."/{$p->id_pemesanan}/review", ['rating' => 5, 'komentar' => 'Mantap'])->assertCreated();

        // Admin melihat id review di detail pesanan
        $idReview = $this->sebagai($tokenAdmin, 'GET', "/api/v1/admin/pemesanan/{$p->id_pemesanan}")->assertJsonPath('data.review.tampil_di_katalog', false)->json('data.review.id');
        $mulai = now()->addDays(3)->toDateString();
        $katalog = function () use ($t, $mulai) {
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();

            return $this->getJson("/api/v1/katalog/tipe/{$t->id_tipe}?tgl_mulai={$mulai}&durasi=1")->json('data.review');
        };
        $this->assertSame([], $katalog());

        $this->sebagai($tokenAdmin, 'PATCH', "/api/v1/admin/review/{$idReview}", ['tampil_di_katalog' => true])->assertOk()->assertJsonPath('data.tampil_di_katalog', true);
        $this->assertSame([['rating' => 5, 'komentar' => 'Mantap', 'nama' => 'Budi', 'tgl_review' => now()->toDateString()]], $katalog());
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'review', 'id_objek' => $idReview, 'id_akun' => $admin->id_akun, 'aksi' => 'Review ditampilkan di katalog']);

        $this->sebagai($tokenAdmin, 'PATCH', "/api/v1/admin/review/{$idReview}", ['tampil_di_katalog' => false])->assertOk()->assertJsonPath('data.tampil_di_katalog', false);
        $this->assertSame([], $katalog());
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'review', 'id_objek' => $idReview, 'aksi' => 'Review disembunyikan dari katalog']);
    }

    public function test_patch_review_validasi_404_dan_hanya_admin(): void
    {
        [, $tokenAdmin] = $this->buatAdmin();
        [$budi, $token] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(1), ['status' => 'selesai']);
        $review = Review::create(['id_pemesanan' => $p->id_pemesanan, 'rating' => 4, 'tgl_review' => now()->toDateString()]);

        $this->sebagai($tokenAdmin, 'PATCH', "/api/v1/admin/review/{$review->id_review}", [])->assertStatus(422)->assertJsonValidationErrors('tampil_di_katalog');
        $this->sebagai($tokenAdmin, 'PATCH', "/api/v1/admin/review/{$review->id_review}", ['tampil_di_katalog' => 'mungkin'])->assertStatus(422);
        $this->sebagai($tokenAdmin, 'PATCH', '/api/v1/admin/review/9999', ['tampil_di_katalog' => true])->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');
        $this->sebagai($token, 'PATCH', "/api/v1/admin/review/{$review->id_review}", ['tampil_di_katalog' => true])->assertForbidden();
        $this->assertFalse($review->fresh()->tampil_di_katalog);
    }
}
