<?php

namespace Tests\Feature;

use App\Models\DetailPerawatan;
use App\Models\Pengembalian;
use App\Models\PengembalianKondisi;
use App\Models\Perawatan;
use App\Models\Pengaturan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** /admin/servis (Spek API bagian 10) dan aturan status servis (bagian 12 no. 6). */
class AdminServisTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private const API = '/api/v1/admin/servis';

    private string $token;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->token] = $this->buatAdmin();
    }

    private function panggil(string $metode, string $url, array $isi = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->json($metode, $url, $isi);
    }

    /**
     * POST /admin/servis sebagai form multipart: semua nilai berupa teks, boolean "true" atau "false", dan kolom yang
     * tidak diisi dikirim kosong (null di sini).
     */
    private function buat(array $isi, ?string $token = null)
    {
        foreach ($isi as $k => $v) {
            $isi[$k] = is_bool($v) ? ($v ? 'true' : 'false') : ($v === null ? '' : $v);
        }
        $this->app['auth']->forgetGuards();
        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Authorization' => 'Bearer '.($token ?? $this->token), 'Content-Type' => 'multipart/form-data']);

        return $this->call('POST', self::API, $isi, [], [], $server);
    }

    /**
     * POST /admin/servis/{id}/selesai sebagai form multipart: nilai berupa teks, komponen[] array, kolom kosong dikirim kosong.
     * Array dipertahankan agar PHP membaca komponen[] seperti form sungguhan.
     */
    private function selesaiForm(int|string $id, array $isi, ?string $token = null)
    {
        foreach ($isi as $k => $v) {
            if (! is_array($v)) {
                $isi[$k] = is_bool($v) ? ($v ? 'true' : 'false') : ($v === null ? '' : (string) $v);
            }
        }
        $this->app['auth']->forgetGuards();
        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Authorization' => 'Bearer '.($token ?? $this->token), 'Content-Type' => 'multipart/form-data']);

        return $this->call('POST', self::API."/{$id}/selesai", $isi, [], [], $server);
    }

    private function bodyServis(int $idUnit, array $ubah = []): array
    {
        return $ubah + ['id_kendaraan' => $idUnit, 'jenis_servis' => 'Servis berkala', 'tingkat_servis' => 'ringan',
            'tgl_perawatan' => now()->toDateString(), 'bengkel' => 'Bengkel Rekanan', 'km_saat_ini' => 46100];
    }

    private function bodySelesai(array $ubah = []): array
    {
        return $ubah + ['komponen' => ['Oli mesin dan filter', 'Rem'], 'biaya_bengkel' => 850000, 'km_saat_ini' => 46100];
    }

    private function unit(int $km = 45800)
    {
        $t = $this->buatTipe(1, 'T'.uniqid());
        $u = $t->kendaraan()->first();
        $u->update(['km_terakhir' => $km]);

        return $u;
    }

    /** Servis selesai langsung di database: km_saat_ini = $kmServis, tgl_berikutnya = $tglBerikutnya, km_berikutnya = $kmServis + 5000. */
    private function servisSelesai($unit, int $kmServis, string $tglBerikutnya, ?int $kmBerikutnya = null): Perawatan
    {
        return Perawatan::create(['id_kendaraan' => $unit->id_kendaraan, 'jenis_servis' => 'Servis', 'tingkat_servis' => 'ringan',
            'tgl_perawatan' => now()->subMonths(2)->toDateString(), 'status' => 'selesai', 'km_saat_ini' => $kmServis,
            'km_berikutnya' => $kmBerikutnya ?? $kmServis + 5000, 'tgl_berikutnya' => $tglBerikutnya]);
    }

    public function test_hanya_admin(): void
    {
        [, $tokenUser] = $this->buatPelanggan();
        foreach ([['GET', self::API], ['POST', self::API], ['GET', self::API.'/status-unit'], ['PUT', self::API.'/interval'], ['POST', self::API.'/1/selesai'], ['POST', self::API.'/1/batal']] as [$m, $u]) {
            $this->panggil($m, $u, [], $tokenUser)->assertForbidden()->assertJsonPath('code', 'AKSES_DITOLAK');
        }
    }

    // ---------------------------------------------------------------- catat

    public function test_catat_servis_membuat_unit_dalam_perawatan_dan_hilang_dari_katalog(): void
    {
        $unit = $this->unit();
        $mulai = now()->addDays(3)->toDateString();
        $katalog = function () use ($mulai) {
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();

            return count($this->getJson("/api/v1/katalog/tipe?tgl_mulai={$mulai}&durasi=1")->json('data'));
        };
        $this->assertSame(1, $katalog());

        $r = $this->buat($this->bodyServis($unit->id_kendaraan))->assertCreated();
        $r->assertJsonPath('data.status', 'berlangsung');

        $s = Perawatan::findOrFail($r->json('data.id'));
        $this->assertSame([$unit->id_kendaraan, 'Servis berkala', 'ringan', 'Bengkel Rekanan', 0, 46100, 'berlangsung', null],
            [$s->id_kendaraan, $s->jenis_servis, $s->tingkat_servis, $s->bengkel, $s->biaya_bengkel, $s->km_saat_ini, $s->status, $s->id_pemesanan_asal]);
        $unit->refresh();
        $this->assertSame(['dalam_perawatan', 46100], [$unit->status, $unit->km_terakhir]);
        $this->assertSame(0, $katalog());
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'perawatan', 'id_objek' => $s->id_perawatan, 'id_akun' => $this->admin->id_akun, 'pelaku' => 'admin']);
    }

    public function test_validasi_catat_servis(): void
    {
        $unit = $this->unit(45800);

        $this->buat([])->assertStatus(422)->assertJsonValidationErrors(['id_kendaraan', 'jenis_servis', 'tingkat_servis', 'tgl_perawatan', 'bengkel']);
        $this->buat($this->bodyServis($unit->id_kendaraan, ['tingkat_servis' => 'berat']))->assertStatus(422)->assertJsonValidationErrors('tingkat_servis');
        $this->buat($this->bodyServis(99999))->assertStatus(422)->assertJsonValidationErrors('id_kendaraan');
        // km lebih kecil dari sebelumnya
        $this->buat($this->bodyServis($unit->id_kendaraan, ['km_saat_ini' => 45000]))->assertStatus(422)->assertJsonValidationErrors('km_saat_ini');

        $this->assertSame(0, Perawatan::count());
        $this->assertSame('tersedia', $unit->fresh()->status);

        // Tanpa km: km tidak berubah. estimasi_biaya dari klien diabaikan: biaya_bengkel tetap 0 dan tidak tampil di respons
        $r = $this->buat($this->bodyServis($unit->id_kendaraan, ['estimasi_biaya' => 800000, 'km_saat_ini' => null]))->assertCreated();
        $this->assertSame([0, 45800], [(int) Perawatan::find($r->json('data.id'))->biaya_bengkel, $unit->fresh()->km_terakhir]);
        $r->assertJsonMissingPath('data.estimasi_biaya');
        $baris = $this->panggil('GET', self::API)->assertJsonMissingPath('data.sedang_servis.0.estimasi_biaya')->json('data.sedang_servis.0');
        $this->assertNull($baris['biaya_bengkel']);
        $this->assertNull($baris['biaya_ditanggung_perusahaan']);
        $this->panggil('GET', self::API)->assertJsonPath('data.ringkasan.total_biaya_bengkel', 0);
    }

    public function test_catat_servis_ditolak_bila_unit_disewa_atau_sudah_diservis(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(3);
        $unit = $t->kendaraan()->orderBy('id_kendaraan')->get();
        $this->buatPesanan($budi, $t, ['status' => 'berjalan', 'id_kendaraan' => $unit[0]->id_kendaraan]);

        $this->buat($this->bodyServis($unit[0]->id_kendaraan))->assertStatus(409)
            ->assertJsonPath('code', 'UNIT_SEDANG_DISEWA')->assertJsonPath('message', 'Unit sedang disewa, perawatan ditunda');

        $this->buat($this->bodyServis($unit[1]->id_kendaraan, ['km_saat_ini' => null]))->assertCreated();
        $this->buat($this->bodyServis($unit[1]->id_kendaraan, ['km_saat_ini' => null]))->assertStatus(409)->assertJsonPath('code', 'UNIT_SUDAH_DALAM_SERVIS');

        // Status dalam_perawatan tanpa catatan servis berlangsung juga dianggap sudah diservis
        $unit[2]->update(['status' => 'dalam_perawatan']);
        $this->buat($this->bodyServis($unit[2]->id_kendaraan, ['km_saat_ini' => null]))->assertStatus(409)->assertJsonPath('code', 'UNIT_SUDAH_DALAM_SERVIS');
        $this->assertSame(1, Perawatan::count());
    }

    public function test_catat_servis_pesanan_terdampak_dengan_konfirmasi(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(1);
        $unit = $t->kendaraan()->first();
        $p = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);

        $this->buat($this->bodyServis($unit->id_kendaraan, ['km_saat_ini' => null]))->assertStatus(409)
            ->assertJsonPath('code', 'PESANAN_TERDAMPAK')->assertJsonPath('pesanan_terdampak.0.id', $p->id_pemesanan)->assertJsonPath('pesanan_terdampak.0.nama', 'Budi');
        $this->assertSame(0, Perawatan::count());
        $this->assertSame('tersedia', $unit->fresh()->status);

        $this->buat($this->bodyServis($unit->id_kendaraan, ['km_saat_ini' => null, 'konfirmasi' => true]))->assertCreated();
        $this->assertSame('dalam_perawatan', $unit->fresh()->status);
    }

    public function test_konfirmasi_dari_teks_form_dibaca_benar(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(1);
        $unit = $t->kendaraan()->first();
        $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);
        $body = $this->bodyServis($unit->id_kendaraan, ['km_saat_ini' => null]);

        // "false", teks kosong, dan "0": tetap peringatan pesanan terdampak, tidak ada yang tercatat
        foreach (['false', '', '0'] as $nilai) {
            $this->buat($body + ['konfirmasi' => $nilai])->assertStatus(409)->assertJsonPath('code', 'PESANAN_TERDAMPAK');
        }
        $this->assertSame(0, Perawatan::count());

        // Teks yang bukan boolean ditolak pada kolomnya
        $this->buat($body + ['konfirmasi' => 'mungkin'])->assertStatus(422)->assertJsonValidationErrors('konfirmasi');

        // "true" (dan "1") melanjutkan
        $this->buat($body + ['konfirmasi' => 'true'])->assertCreated();
        $this->assertSame('dalam_perawatan', $unit->fresh()->status);
        $this->assertSame(1, Perawatan::count());
    }

    public function test_unit_tidak_tersedia_boleh_diservis_tanpa_peringatan_pesanan_terdampak(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(1);
        $unit = $t->kendaraan()->first();
        $unit->update(['status' => 'tidak_tersedia']);
        $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']); // kapasitas sudah nol sebelum servis

        $this->buat($this->bodyServis($unit->id_kendaraan, ['km_saat_ini' => null]))->assertCreated();
        $this->assertSame('dalam_perawatan', $unit->fresh()->status);
    }

    // ---------------------------------------------------------------- selesai dan batal

    public function test_selesai_menghitung_jadwal_berikutnya_biaya_ditanggung_perusahaan_dan_menyimpan_komponen(): void
    {
        $unit = $this->unit();
        $id = $this->buat($this->bodyServis($unit->id_kendaraan))->json('data.id');

        // Servis manual: biaya_ditagih_ke_penyewa dari klien diabaikan (tanpa 422), nilainya 0 sehingga perusahaan menanggung semuanya
        $this->selesaiForm($id, $this->bodySelesai(['biaya_ditagih_ke_penyewa' => 900000, 'km_saat_ini' => 46300]))->assertOk()
            ->assertJsonPath('data', [
                'status' => 'selesai', 'km_berikutnya' => 51300, // 46300 + interval bawaan 5000
                'tgl_berikutnya' => now()->addMonthsNoOverflow(6)->toDateString(),
                'biaya_ditanggung_perusahaan' => 850000,
            ]);

        $s = Perawatan::findOrFail($id);
        $this->assertSame(['selesai', 850000, 0, 46300], [$s->status, (int) $s->biaya_bengkel, (int) $s->biaya_ditagih_ke_penyewa, $s->km_saat_ini]);
        $this->assertSame(['Oli mesin dan filter', 'Rem'], DetailPerawatan::where('id_perawatan', $id)->orderBy('id_detail')->pluck('komponen')->all());
        $unit->refresh();
        $this->assertSame(['tersedia', 46300], [$unit->status, $unit->km_terakhir]);
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'perawatan', 'id_objek' => $id, 'id_akun' => $this->admin->id_akun, 'aksi' => "Servis selesai untuk {$unit->plat_nomor}"]);
    }

    public function test_selesai_tanpa_tagihan_semua_biaya_ditanggung_perusahaan_dan_tgl_akhir_bulan_tidak_meluap(): void
    {
        $unit = $this->unit();
        $id = $this->buat($this->bodyServis($unit->id_kendaraan))->json('data.id');

        // Servis manual: ditagih 0, jadi seluruh biaya bengkel ditanggung perusahaan
        $this->travelTo(\Carbon\Carbon::parse('2026-08-31 10:00:00'));
        $this->selesaiForm($id, $this->bodySelesai())->assertOk()
            ->assertJsonPath('data.biaya_ditanggung_perusahaan', 850000)->assertJsonPath('data.tgl_berikutnya', '2027-02-28')
            ->assertJsonMissingPath('data.selisih_biaya');
    }

    public function test_selesai_form_membaca_komponen_berulang_sebagai_array_berurutan(): void
    {
        $unit = $this->unit();
        $id = $this->buat($this->bodyServis($unit->id_kendaraan))->json('data.id');

        // Teks form mentah: komponen[] diulang tiga kali, dibaca PHP sebagai array
        $this->selesaiForm($id, ['komponen' => ['Oli mesin', 'Kampas rem', 'Filter udara'], 'biaya_bengkel' => '850000', 'km_saat_ini' => '46100'])
            ->assertOk()->assertJsonPath('data.biaya_ditanggung_perusahaan', 850000);

        $this->assertSame(['Oli mesin', 'Kampas rem', 'Filter udara'], DetailPerawatan::where('id_perawatan', $id)->orderBy('id_detail')->pluck('komponen')->all());
    }

    public function test_selesai_servis_dari_pengembalian_menurunkan_ditagih_dari_biaya_kerusakan_dan_mengabaikan_kiriman_klien(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(1);
        $unit = $t->kendaraan()->first();
        $p = $this->buatPesanan($budi, $t, ['status' => 'selesai']);
        $pg = Pengembalian::create(['id_pemesanan' => $p->id_pemesanan, 'tgl_kembali' => now()->toDateString(), 'jam_kembali' => '17:00']);
        foreach ([['depan', 'lecet', 300000], ['atap', 'penyok', 200000], ['interior', 'baik', 0]] as [$bagian, $kondisi, $biaya]) {
            PengembalianKondisi::create(['id_pengembalian' => $pg->id_pengembalian, 'bagian' => $bagian, 'kondisi' => $kondisi, 'biaya' => $biaya]);
        }
        $unit->update(['status' => 'dalam_perawatan']);
        $servis = Perawatan::create(['id_kendaraan' => $unit->id_kendaraan, 'id_pemesanan_asal' => $p->id_pemesanan, 'jenis_servis' => 'Perbaikan body',
            'tingkat_servis' => 'sedang', 'tgl_perawatan' => now()->toDateString(), 'status' => 'berlangsung']);

        // Kiriman 999999 diabaikan; nilai = 300000 + 200000 (kondisi baik tidak dihitung)
        $this->selesaiForm($servis->id_perawatan, $this->bodySelesai(['biaya_ditagih_ke_penyewa' => 999999, 'biaya_bengkel' => 800000, 'km_saat_ini' => 46100]))
            ->assertOk()->assertJsonPath('data.biaya_ditanggung_perusahaan', 300000);

        $this->assertSame(500000, (int) $servis->fresh()->biaya_ditagih_ke_penyewa);
        $riwayat = collect($this->panggil('GET', self::API)->json('data.riwayat'))->keyBy('id')[$servis->id_perawatan];
        $this->assertSame([500000, 300000, $p->nomor], [$riwayat['biaya_ditagih_ke_penyewa'], $riwayat['biaya_ditanggung_perusahaan'], $riwayat['asal_pesanan']]);
        $this->panggil('GET', self::API)->assertJsonPath('data.ringkasan.total_ditagih', 500000);
    }

    public function test_satu_pesanan_hanya_boleh_punya_satu_servis_asal(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(2);
        $p = $this->buatPesanan($budi, $t, ['status' => 'selesai']);
        $buat = fn ($unit) => Perawatan::create(['id_kendaraan' => $unit->id_kendaraan, 'id_pemesanan_asal' => $p->id_pemesanan, 'jenis_servis' => 'X',
            'tingkat_servis' => 'ringan', 'tgl_perawatan' => now()->toDateString()]);
        $unit = $t->kendaraan()->orderBy('id_kendaraan')->get();
        $buat($unit[0]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $buat($unit[1]);
    }

    public function test_validasi_dan_status_selesai(): void
    {
        $unit = $this->unit(45800);
        $id = $this->buat($this->bodyServis($unit->id_kendaraan, ['km_saat_ini' => null]))->json('data.id');

        $this->selesaiForm($id, [])->assertStatus(422)->assertJsonValidationErrors(['komponen', 'biaya_bengkel', 'km_saat_ini']);
        $this->selesaiForm($id, $this->bodySelesai(['komponen' => []]))->assertStatus(422)->assertJsonValidationErrors('komponen');
        $this->selesaiForm($id, $this->bodySelesai(['km_saat_ini' => 45000]))->assertStatus(422)->assertJsonValidationErrors('km_saat_ini');
        $this->assertSame('berlangsung', Perawatan::find($id)->status);

        $this->selesaiForm($id, $this->bodySelesai())->assertOk();
        $this->selesaiForm($id, $this->bodySelesai())->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        $this->selesaiForm(9999, $this->bodySelesai())->assertNotFound();
    }

    public function test_batal_mengembalikan_unit_tersedia(): void
    {
        $unit = $this->unit();
        $id = $this->buat($this->bodyServis($unit->id_kendaraan))->json('data.id');

        $this->panggil('POST', self::API."/{$id}/batal")->assertOk()->assertJsonPath('data.status', 'dibatalkan');

        $this->assertSame('dibatalkan', Perawatan::find($id)->status);
        $this->assertSame('tersedia', $unit->fresh()->status);
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'perawatan', 'id_objek' => $id, 'aksi' => "Servis dibatalkan untuk {$unit->plat_nomor}"]);

        $this->panggil('POST', self::API."/{$id}/batal")->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        $this->panggil('POST', self::API.'/9999/batal')->assertNotFound();
        // Setelah dibatalkan, unit boleh diservis lagi
        $this->buat($this->bodyServis($unit->id_kendaraan, ['km_saat_ini' => null]))->assertCreated();
    }

    // ---------------------------------------------------------------- interval dan status pengingat

    public function test_ubah_interval_menghitung_ulang_km_berikutnya_servis_terakhir(): void
    {
        $a = $this->unit(46300);
        $b = $this->unit(10000);
        $this->servisSelesai($a, 46300, now()->addMonths(6)->toDateString());
        $this->servisSelesai($b, 9000, now()->addMonths(6)->toDateString());

        $this->panggil('PUT', self::API.'/interval', ['interval_km' => 10000])->assertOk()->assertJsonPath('data.interval_km', 10000);

        $this->assertSame('10000', Pengaturan::ambil('interval_km'));
        $this->assertSame([56300, 19000], [Perawatan::where('id_kendaraan', $a->id_kendaraan)->value('km_berikutnya'), Perawatan::where('id_kendaraan', $b->id_kendaraan)->value('km_berikutnya')]);
        $this->assertDatabaseHas('log_aktivitas', ['id_akun' => $this->admin->id_akun, 'aksi' => 'Interval servis diubah menjadi 10000 km']);

        // Servis berikutnya memakai interval baru
        $id = $this->buat($this->bodyServis($a->id_kendaraan, ['km_saat_ini' => null]))->json('data.id');
        $this->selesaiForm($id, $this->bodySelesai(['km_saat_ini' => 50000]))->assertJsonPath('data.km_berikutnya', 60000);

        foreach ([0, -5, 'banyak', null] as $salah) {
            $this->panggil('PUT', self::API.'/interval', ['interval_km' => $salah])->assertStatus(422)->assertJsonValidationErrors('interval_km');
        }
    }

    public function test_status_unit_aman_segera_perlu_servis_dari_servis_selesai_terakhir(): void
    {
        $depan = now()->addMonths(5)->toDateString();

        $aman = $this->unit(46000);      $this->servisSelesai($aman, 45000, $depan);                          // sisa 4000 km, 5 bulan
        $segeraKm = $this->unit(49500);  $this->servisSelesai($segeraKm, 45000, $depan);                      // sisa 500 km
        $segeraHari = $this->unit(46000); $this->servisSelesai($segeraHari, 45000, now()->addDays(20)->toDateString()); // sisa 20 hari
        $batasKm = $this->unit(49000);   $this->servisSelesai($batasKm, 45000, $depan);                       // sisa tepat 1000 km
        $perluKm = $this->unit(50000);   $this->servisSelesai($perluKm, 45000, $depan);                       // km sudah mencapai km_berikutnya
        $perluHari = $this->unit(46000); $this->servisSelesai($perluHari, 45000, now()->toDateString());      // tanggal hari ini

        $r = collect($this->panggil('GET', self::API.'/status-unit')->assertOk()->json('data'))->keyBy('id_kendaraan');

        $status = fn ($u) => $r[$u->id_kendaraan]['status'];
        $this->assertSame('aman', $status($aman));
        $this->assertSame('segera', $status($segeraKm));
        $this->assertSame('segera', $status($segeraHari));
        $this->assertSame('segera', $status($batasKm));
        $this->assertSame('perlu_servis', $status($perluKm));
        $this->assertSame('perlu_servis', $status($perluHari));

        $this->assertSame(['km_terakhir' => 46000, 'km_sejak_servis' => 1000, 'sisa_km' => 4000], array_intersect_key($r[$aman->id_kendaraan], array_flip(['km_terakhir', 'km_sejak_servis', 'sisa_km'])));
        $this->assertSame(0, $r[$perluKm->id_kendaraan]['sisa_km']);
        $this->assertSame(now()->subMonths(2)->toDateString(), $r[$aman->id_kendaraan]['servis_terakhir']);
    }

    /** Unit yang dibuat lewat aplikasi: km_awal = km saat dibuat, created_at = $dibuat (null: sekarang). */
    private function unitBaru(int $kmAwal, ?string $dibuat = null)
    {
        $t = $this->buatTipe(0, 'T'.uniqid());
        $u = \App\Models\Kendaraan::create(['id_tipe' => $t->id_tipe, 'plat_nomor' => 'N '.random_int(1, 9999).' ZZ', 'tahun' => 2022, 'warna' => 'Putih',
            'transmisi' => 'manual', 'km_terakhir' => $kmAwal, 'status' => 'tersedia']);
        if ($dibuat) {
            $u->forceFill(['created_at' => $dibuat])->saveQuietly();
        }

        return $u->refresh();
    }

    public function test_unit_baru_tanpa_servis_memakai_km_awal_dan_tanggal_dibuat_sebagai_patokan(): void
    {
        $baru = $this->unitBaru(2000);
        $this->assertSame(2000, (int) $baru->km_awal, 'km_awal terisi dari km saat dibuat');

        $cari = fn ($u) => collect($this->panggil('GET', self::API.'/status-unit')->assertOk()->json('data'))->firstWhere('id_kendaraan', $u->id_kendaraan);

        // Aman: baru dibuat, sisa 5000 km dan 6 bulan
        $r = $cari($baru);
        $this->assertSame('aman', $r['status']);
        $this->assertSame([null, 0, 7000, 5000], [$r['servis_terakhir'], $r['km_sejak_servis'], $r['km_berikutnya'], $r['sisa_km']]);
        $this->assertSame(now()->addMonthsNoOverflow(6)->toDateString(), $r['tgl_berikutnya']);
        $this->assertSame((int) round(now()->startOfDay()->diffInDays(now()->addMonthsNoOverflow(6)->startOfDay())), $r['sisa_hari']);

        // Segera karena km: sisa 500 km (km_sejak_servis dihitung dari km_awal)
        $baru->update(['km_terakhir' => 6500]);
        $r = $cari($baru);
        $this->assertSame(['segera', 4500, 500], [$r['status'], $r['km_sejak_servis'], $r['sisa_km']]);

        // Perlu servis karena km: mencapai km_awal + interval
        $baru->update(['km_terakhir' => 7000]);
        $this->assertSame('perlu_servis', $cari($baru)['status']);

        // Segera karena tanggal: dibuat 5 bulan 20 hari lalu, sisa sekitar 10 hari (km masih jauh)
        $hampir = $this->unitBaru(2000, now()->subMonths(5)->subDays(20)->toDateTimeString());
        $r = $cari($hampir);
        $this->assertSame('segera', $r['status']);
        $this->assertLessThanOrEqual(30, $r['sisa_hari']);
        $this->assertSame(5000, $r['sisa_km']);

        // Perlu servis karena tanggal: dibuat 7 bulan lalu
        $lewat = $this->unitBaru(2000, now()->subMonths(7)->toDateTimeString());
        $r = $cari($lewat);
        $this->assertSame('perlu_servis', $r['status']);
        $this->assertLessThan(0, $r['sisa_hari']);
        $this->assertSame(5000, $r['sisa_km']);

        $this->assertEmpty(array_diff(collect($this->panggil('GET', self::API.'/status-unit')->json('data'))->pluck('status')->all(), ['aman', 'segera', 'perlu_servis']), 'Hanya tiga status');
    }

    public function test_setelah_ada_servis_selesai_patokan_pindah_ke_servis_terakhir(): void
    {
        // Unit dibuat 8 bulan lalu dengan km_awal 0: patokan awal sudah lewat, tetapi servis terakhir menggantikannya
        $unit = $this->unitBaru(0, now()->subMonths(8)->toDateTimeString());
        $unit->update(['km_terakhir' => 9000]);
        $cari = fn () => collect($this->panggil('GET', self::API.'/status-unit')->json('data'))->firstWhere('id_kendaraan', $unit->id_kendaraan);

        $this->assertSame('perlu_servis', $cari()['status'], 'Tanpa servis: km 9000 melewati 0 + 5000 dan tanggal lewat');

        $this->servisSelesai($unit, 8500, now()->addMonths(5)->toDateString());
        $r = $cari();
        $this->assertSame(['aman', 500, 4500, 13500], [$r['status'], $r['km_sejak_servis'], $r['sisa_km'], $r['km_berikutnya']]);
        $this->assertSame(now()->subMonths(2)->toDateString(), $r['servis_terakhir']);
        $this->assertSame(now()->addMonths(5)->toDateString(), $r['tgl_berikutnya']);
    }

    public function test_status_unit_memakai_servis_selesai_terakhir_bukan_yang_dibatalkan(): void
    {
        $unit = $this->unit(46000);
        $this->servisSelesai($unit, 30000, now()->subMonths(1)->toDateString(), 35000); // lama: sudah lewat
        $this->servisSelesai($unit, 45500, now()->addMonths(5)->toDateString());          // terbaru
        Perawatan::create(['id_kendaraan' => $unit->id_kendaraan, 'jenis_servis' => 'X', 'tingkat_servis' => 'ringan', 'tgl_perawatan' => now()->toDateString(), 'status' => 'dibatalkan', 'km_saat_ini' => 1]);

        $r = collect($this->panggil('GET', self::API.'/status-unit')->json('data'))->firstWhere('id_kendaraan', $unit->id_kendaraan);

        $this->assertSame(['aman', 500, 4500], [$r['status'], $r['km_sejak_servis'], $r['sisa_km']]);
    }

    public function test_dashboard_memuat_pengingat_servis(): void
    {
        $segera = $this->unit(49500);  $this->servisSelesai($segera, 45000, now()->addMonths(5)->toDateString());
        $perlu = $this->unit(50500);   $this->servisSelesai($perlu, 45000, now()->addMonths(5)->toDateString());
        $aman = $this->unit(46000);    $this->servisSelesai($aman, 45000, now()->addMonths(5)->toDateString());
        $sedangServis = $this->unit(52000); $this->servisSelesai($sedangServis, 45000, now()->addMonths(5)->toDateString());
        $sedangServis->update(['status' => 'dalam_perawatan']);

        $r = $this->panggil('GET', '/api/v1/admin/dashboard')->assertOk();

        $this->assertEqualsCanonicalizing([
            ['id_kendaraan' => $segera->id_kendaraan, 'plat_nomor' => $segera->plat_nomor, 'status' => 'segera'],
            ['id_kendaraan' => $perlu->id_kendaraan, 'plat_nomor' => $perlu->plat_nomor, 'status' => 'perlu_servis'],
        ], $r->json('data.pengingat_servis'));
        $r->assertJsonPath('data.hitungan.unit_dalam_servis', 1);
    }

    // ---------------------------------------------------------------- daftar

    public function test_ringkasan_sedang_servis_dan_riwayat_dengan_asal_pesanan(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(3, 'Brio');
        $unit = $t->kendaraan()->orderBy('id_kendaraan')->get();
        $asal = $this->buatPesanan($budi, $t, ['status' => 'selesai']);

        // Sedang servis (dari pengembalian: memuat asal pesanan), selesai, dan dibatalkan
        $berlangsung = Perawatan::create(['id_kendaraan' => $unit[0]->id_kendaraan, 'id_pemesanan_asal' => $asal->id_pemesanan, 'jenis_servis' => 'Perbaikan lecet',
            'tingkat_servis' => 'sedang', 'tgl_perawatan' => now()->toDateString(), 'status' => 'berlangsung', 'biaya_bengkel' => 300000]);
        $unit[0]->update(['status' => 'dalam_perawatan']);
        $selesai = $this->servisSelesai($unit[1], 46000, now()->addMonths(5)->toDateString());
        $selesai->update(['biaya_bengkel' => 850000, 'biaya_ditagih_ke_penyewa' => 900000]);
        DetailPerawatan::create(['id_perawatan' => $selesai->id_perawatan, 'komponen' => 'Rem']);
        $batal = Perawatan::create(['id_kendaraan' => $unit[2]->id_kendaraan, 'jenis_servis' => 'Cek', 'tingkat_servis' => 'ringan', 'tgl_perawatan' => now()->toDateString(), 'status' => 'dibatalkan', 'biaya_bengkel' => 999]);
        // Servis selesai kedua: bengkel 400000, ditagih 100000, perusahaan menanggung 300000
        $kedua = Perawatan::create(['id_kendaraan' => $unit[2]->id_kendaraan, 'jenis_servis' => 'Ganti ban', 'tingkat_servis' => 'ringan', 'tgl_perawatan' => now()->toDateString(),
            'status' => 'selesai', 'biaya_bengkel' => 400000, 'biaya_ditagih_ke_penyewa' => 100000, 'km_saat_ini' => 46000, 'km_berikutnya' => 51000, 'tgl_berikutnya' => now()->addMonths(5)->toDateString()]);

        $r = $this->panggil('GET', self::API)->assertOk();

        // Total ditanggung = jumlah nilai per servis (0 + 300000), bukan 1250000 - 1000000 = 250000
        $r->assertJsonPath('data.ringkasan', ['unit_dalam_servis' => 1, 'unit_tersedia' => 2, 'total_biaya_bengkel' => 1250000, 'total_ditagih' => 1000000, 'total_ditanggung_perusahaan' => 300000]);
        $this->assertSame([null], array_column($r->json('data.sedang_servis'), 'biaya_ditanggung_perusahaan'), 'Belum selesai: null');
        $this->assertSame([[$berlangsung->id_perawatan, "Brio {$unit[0]->plat_nomor}", $asal->nomor]],
            collect($r->json('data.sedang_servis'))->map(fn ($s) => [$s['id'], $s['unit'], $s['asal_pesanan']])->all());
        $riwayat = collect($r->json('data.riwayat'))->keyBy('id');
        $this->assertSame(['selesai', 0, ['Rem'], null], [$riwayat[$selesai->id_perawatan]['status'], $riwayat[$selesai->id_perawatan]['biaya_ditanggung_perusahaan'], $riwayat[$selesai->id_perawatan]['komponen'], $riwayat[$selesai->id_perawatan]['asal_pesanan']]);
        $this->assertSame('dibatalkan', $riwayat[$batal->id_perawatan]['status']);
        $this->assertNull($riwayat[$batal->id_perawatan]['biaya_ditanggung_perusahaan'], 'Servis yang tidak selesai: null');
        $this->assertSame(300000, $riwayat[$kedua->id_perawatan]['biaya_ditanggung_perusahaan']);
        $this->assertArrayNotHasKey('selisih_biaya', $riwayat[$selesai->id_perawatan]);
        $this->assertCount(3, $riwayat, 'Sedang servis tidak masuk riwayat');
    }

    public function test_servis_dari_pengembalian_bisa_diselesaikan_lewat_endpoint_servis(): void
    {
        [$budi] = $this->buatPelanggan();
        $p = $this->buatPesananBerjalan($budi, $this->buatTipe(1));
        $kondisi = array_fill_keys(['depan', 'belakang', 'sisi_kiri', 'sisi_kanan', 'atap', 'kaca_lampu', 'ban_velg', 'interior'], 'baik');

        $this->app['auth']->forgetGuards();
        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token, 'Content-Type' => 'multipart/form-data']);
        $this->call('POST', "/api/v1/admin/pemesanan/{$p->id_pemesanan}/pengembalian", [
            'tgl_kembali' => now()->toDateString(), 'jam_kembali' => '17:00', 'odometer_akhir' => 46000, 'bbm_akhir' => 'penuh', 'biaya_kekurangan_bbm' => 0,
            'kebersihan' => 'bersih', 'kondisi' => $kondisi, 'disetujui_penyewa' => 'true', 'unit_perlu_servis' => 'true',
            'servis' => ['tingkat' => 'sedang', 'keperluan' => 'Cek rem'],
        ], [], [], $server)->assertOk();

        $sedang = $this->panggil('GET', self::API)->json('data.sedang_servis');
        $this->assertSame($p->nomor, $sedang[0]['asal_pesanan']);

        $this->selesaiForm($sedang[0]['id'], $this->bodySelesai(['km_saat_ini' => 46100]))->assertOk();
        $this->assertSame('tersedia', $p->kendaraan->fresh()->status);
    }
}
