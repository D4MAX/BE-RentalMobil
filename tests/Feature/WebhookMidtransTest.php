<?php

namespace Tests\Feature;

use App\Models\LogAktivitas;
use App\Models\Pembayaran;
use App\Models\Pemesanan;
use App\Models\Pengaturan;
use App\Services\Pembayaran\PembayaranGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** POST /webhook/midtrans (Spek API bagian 7): tanda tangan sha512, idempoten, jatah dicek ulang. */
class WebhookMidtransTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private const URL = '/api/v1/webhook/midtrans';

    /** Notifikasi bertanda tangan benar menurut ServerKey di phpunit.xml. */
    private function notif(string $orderId, string $status, int $jumlah = 600000, array $ubah = [])
    {
        $d = $ubah + [
            'order_id' => $orderId, 'status_code' => '200', 'gross_amount' => number_format($jumlah, 2, '.', ''),
            'transaction_status' => $status, 'payment_type' => 'qris',
        ];
        $d += ['signature_key' => hash('sha512', $d['order_id'].$d['status_code'].$d['gross_amount'].config('midtrans.server_key'))];

        return $this->postJson(self::URL, $d);
    }

    /** @return array{0: Pemesanan, 1: string} pesanan menunggu (600000) dan order_id-nya */
    private function pesananMenunggu(array $ubah = [], int $unit = 1): array
    {
        [$p] = $this->buatPelanggan('p'.uniqid().'@mail.test');
        $t = $this->buatTipe($unit, 'T'.uniqid());
        $pemesanan = $this->buatPesanan($p, $t, $ubah);

        return [$pemesanan, $pemesanan->pembayaran->first()->order_id_midtrans];
    }

    private function bayarDi(Pemesanan $p): Pembayaran
    {
        return Pembayaran::where('id_pemesanan', $p->id_pemesanan)->orderBy('id_pembayaran')->first();
    }

    // ---- tanda tangan ----

    public function test_tanda_tangan_salah_kunci_kosong_atau_field_hilang_ditolak_403(): void
    {
        [$p, $order] = $this->pesananMenunggu();

        $this->notif($order, 'settlement', 600000, ['signature_key' => str_repeat('a', 128)])
            ->assertForbidden()->assertJsonPath('code', 'TANDA_TANGAN_SALAH');
        // Nominal diubah setelah ditandatangani
        $this->notif($order, 'settlement', 600000, ['signature_key' => hash('sha512', $order.'200'.'600000.00'.config('midtrans.server_key')), 'gross_amount' => '1.00'])
            ->assertForbidden();
        $this->postJson(self::URL, ['order_id' => $order, 'transaction_status' => 'settlement'])->assertForbidden();
        $this->postJson(self::URL, [])->assertForbidden();

        config(['midtrans.server_key' => '']);
        $this->notif($order, 'settlement')->assertForbidden()->assertJsonPath('code', 'TANDA_TANGAN_SALAH');

        $this->assertSame('menunggu_pembayaran', $p->fresh()->status, 'Notifikasi palsu tidak boleh mengubah apa pun');
    }

    // ---- fraud_status (kartu kredit) ----

    public function test_capture_kartu_challenge_tidak_mengonfirmasi_pesanan(): void
    {
        [$p, $order] = $this->pesananMenunggu();

        $this->notif($order, 'capture', 600000, ['payment_type' => 'credit_card', 'fraud_status' => 'challenge'])->assertOk();

        $this->assertSame('menunggu_pembayaran', $p->fresh()->status, 'Challenge: uang belum pasti masuk, jangan dikonfirmasi');
        $this->assertSame('menunggu', $this->bayarDi($p)->status);

        // Setelah merchant menyetujui, Midtrans mengirim settlement dan barulah pesanan dikonfirmasi
        $this->notif($order, 'settlement', 600000, ['payment_type' => 'credit_card'])->assertOk();
        $this->assertSame('dikonfirmasi', $p->fresh()->status);
    }

    public function test_capture_kartu_accept_mengonfirmasi_pesanan(): void
    {
        [$p, $order] = $this->pesananMenunggu();

        $this->notif($order, 'capture', 600000, ['payment_type' => 'credit_card', 'fraud_status' => 'accept'])->assertOk();

        $this->assertSame('dikonfirmasi', $p->fresh()->status);
        $this->assertSame('credit_card', $this->bayarDi($p)->metode);
    }

    // ---- refund dan chargeback dari sisi Midtrans ----

    public function test_notifikasi_refund_dari_refund_buatan_sistem_sendiri_tidak_dicatat_sebagai_tindak_lanjut(): void
    {
        [$p, $order] = $this->pesananMenunggu(['status' => 'dibatalkan', 'alasan_pembatalan' => 'kedaluwarsa', 'batas_pembayaran' => now()->subHour()]);
        $this->bayarDi($p)->update(['status' => 'gagal']);
        [$lain] = $this->buatPelanggan('lain@mail.test');
        $this->buatPesanan($lain, $p->tipe, ['status' => 'dikonfirmasi', 'tgl_mulai' => $p->tgl_mulai->toDateString()]);
        $this->notif($order, 'settlement', 600000, ['payment_type' => 'qris'])->assertOk(); // sistem me-refund sendiri

        $this->notif($order, 'refund')->assertOk(); // Midtrans memberi tahu refund yang baru kita minta

        $this->assertSame(0, LogAktivitas::where('aksi', 'like', 'Midtrans melaporkan%')->count());
    }

    public function test_notifikasi_refund_dan_chargeback_dicatat_tanpa_mengubah_status(): void
    {
        [$p, $order] = $this->pesananMenunggu();
        $this->notif($order, 'settlement')->assertOk();
        $bayar = $this->bayarDi($p);

        foreach (['refund', 'partial_refund', 'chargeback'] as $status) {
            $this->notif($order, $status)->assertOk();
            $this->assertDatabaseHas('log_aktivitas', [
                'objek' => 'pembayaran', 'id_objek' => $bayar->id_pembayaran, 'pelaku' => 'sistem',
                'aksi' => "Midtrans melaporkan {$status}, periksa di dashboard Midtrans dan tindak lanjuti manual",
            ]);
        }

        $this->assertSame('lunas', $this->bayarDi($p)->status, 'Status uang tidak diubah otomatis: admin yang memutuskan');
        $this->assertSame('dikonfirmasi', $p->fresh()->status);
    }

    // ---- settlement ----

    public function test_settlement_dalam_batas_mengonfirmasi_dan_idempoten(): void
    {
        [$p, $order] = $this->pesananMenunggu();

        $this->notif($order, 'settlement', 600000, ['payment_type' => 'qris'])->assertOk()->assertJsonPath('message', 'OK');

        $this->assertSame('dikonfirmasi', $p->fresh()->status);
        $bayar = $this->bayarDi($p);
        $this->assertSame(['lunas', 'qris'], [$bayar->status, $bayar->metode]);
        $this->assertNotNull($bayar->tgl_bayar);
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'pelaku' => 'sistem', 'aksi' => 'Pembayaran berhasil, status Dikonfirmasi']);

        // Dikirim lagi (juga sebagai capture): tidak mengubah apa pun
        $tglBayar = $bayar->tgl_bayar;
        $this->travel(5)->minutes();
        $this->notif($order, 'settlement')->assertOk();
        $this->notif($order, 'capture')->assertOk();
        $this->assertEquals($tglBayar, $this->bayarDi($p)->tgl_bayar);
        $this->assertSame(1, LogAktivitas::where('aksi', 'like', 'Pembayaran berhasil%')->count());
    }

    public function test_capture_sama_dengan_settlement_dan_metode_va_dicatat(): void
    {
        [$p, $order] = $this->pesananMenunggu();

        $this->notif($order, 'capture', 600000, ['payment_type' => 'bank_transfer'])->assertOk();

        $this->assertSame('dikonfirmasi', $p->fresh()->status);
        $this->assertSame('bank_transfer', $this->bayarDi($p)->metode);
    }

    public function test_pembayaran_dari_token_lama_dikenali_dan_percobaan_lain_menjadi_gagal(): void
    {
        [$p, $order1] = $this->pesananMenunggu();
        $dua = Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'sewa', 'jumlah' => 600000,
            'order_id_midtrans' => $p->nomor.'-2', 'status' => 'menunggu']);

        // User membayar lewat token pertama walau sudah meminta token kedua
        $this->notif($order1, 'settlement')->assertOk();

        $this->assertSame('dikonfirmasi', $p->fresh()->status);
        $this->assertSame('lunas', $this->bayarDi($p)->status);
        $this->assertSame('gagal', $dua->fresh()->status);
    }

    public function test_settlement_lewat_batas_sebelum_scheduler_jalan_tetap_dikonfirmasi_bila_jatah_ada(): void
    {
        [$p, $order] = $this->pesananMenunggu(['batas_pembayaran' => now()->subMinutes(2)]);

        $this->notif($order, 'settlement')->assertOk();

        $this->assertSame('dikonfirmasi', $p->fresh()->status);
        $this->assertSame('lunas', $this->bayarDi($p)->status);
    }

    public function test_settlement_terlambat_menghidupkan_pesanan_dibatalkan_bila_jatah_masih_ada(): void
    {
        [$p, $order] = $this->pesananMenunggu(['status' => 'dibatalkan', 'alasan_pembatalan' => 'kedaluwarsa', 'batas_pembayaran' => now()->subHour()]);
        $this->bayarDi($p)->update(['status' => 'gagal']);

        $this->notif($order, 'settlement')->assertOk();

        $p->refresh();
        $this->assertSame(['dikonfirmasi', null], [$p->status, $p->alasan_pembatalan]);
        $this->assertSame('lunas', $this->bayarDi($p)->status);
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'aksi' => 'Pembayaran berhasil, status Dikonfirmasi (pesanan dihidupkan kembali)']);
    }

    public function test_settlement_terlambat_saat_jatah_terpakai_direfund_qris_lewat_midtrans(): void
    {
        [$p, $order] = $this->pesananMenunggu(['status' => 'dibatalkan', 'alasan_pembatalan' => 'kedaluwarsa', 'batas_pembayaran' => now()->subHour()]);
        $this->bayarDi($p)->update(['status' => 'gagal']);
        // Unit satu-satunya sudah diambil pemesan lain pada tanggal yang sama
        [$lain] = $this->buatPelanggan('lain@mail.test');
        $this->buatPesanan($lain, $p->tipe, ['status' => 'dikonfirmasi', 'tgl_mulai' => $p->tgl_mulai->toDateString()]);

        $this->notif($order, 'settlement', 600000, ['payment_type' => 'qris'])->assertOk();

        $this->assertSame('dibatalkan', $p->fresh()->status, 'Tidak ada pesanan baru dan tidak dihidupkan');
        $bayar = $this->bayarDi($p);
        $this->assertSame(['dikembalikan', 'midtrans', true], [$bayar->status, $bayar->refund_cara, $bayar->refund_selesai]);
        $this->assertSame([['orderId' => $order, 'jumlah' => 600000, 'alasan' => 'Unit tidak tersedia saat pembayaran diterima']],
            app(PembayaranGateway::class)->refundDicatat);
        $this->assertSame(1, Pemesanan::where('id_pelanggan', $p->id_pelanggan)->count());

        // Notifikasi ulang tidak me-refund lagi
        $this->notif($order, 'settlement')->assertOk();
        $this->assertCount(1, app(PembayaranGateway::class)->refundDicatat);
    }

    public function test_refund_virtual_account_ditandai_untuk_transfer_admin(): void
    {
        [$p, $order] = $this->pesananMenunggu(['status' => 'dibatalkan', 'alasan_pembatalan' => 'kedaluwarsa', 'batas_pembayaran' => now()->subHour()]);
        $this->bayarDi($p)->update(['status' => 'gagal']);
        [$lain] = $this->buatPelanggan('lain@mail.test');
        $this->buatPesanan($lain, $p->tipe, ['status' => 'berjalan', 'tgl_mulai' => $p->tgl_mulai->toDateString()]);

        $this->notif($order, 'settlement', 600000, ['payment_type' => 'bank_transfer'])->assertOk();

        $bayar = $this->bayarDi($p);
        $this->assertSame(['lunas', 'transfer_admin', false], [$bayar->status, $bayar->refund_cara, $bayar->refund_selesai]);
        $this->assertSame([], app(PembayaranGateway::class)->refundDicatat, 'Virtual account ditransfer admin di luar sistem');
        $this->assertSame('dibatalkan', $p->fresh()->status);
    }

    public function test_refund_midtrans_gagal_dialihkan_ke_transfer_admin_tanpa_galat(): void
    {
        [$p, $order] = $this->pesananMenunggu(['status' => 'dibatalkan', 'alasan_pembatalan' => 'kedaluwarsa', 'batas_pembayaran' => now()->subHour()]);
        $this->bayarDi($p)->update(['status' => 'gagal']);
        [$lain] = $this->buatPelanggan('lain@mail.test');
        $this->buatPesanan($lain, $p->tipe, ['status' => 'dikonfirmasi', 'tgl_mulai' => $p->tgl_mulai->toDateString()]);
        app(PembayaranGateway::class)->gagalRefund = true;

        $this->notif($order, 'settlement', 600000, ['payment_type' => 'qris'])->assertOk()->assertJsonPath('message', 'OK');

        $bayar = $this->bayarDi($p);
        $this->assertSame(['lunas', 'transfer_admin', false], [$bayar->status, $bayar->refund_cara, $bayar->refund_selesai]);
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'pembayaran', 'id_objek' => $bayar->id_pembayaran, 'pelaku' => 'sistem',
            'aksi' => 'Refund otomatis lewat Midtrans gagal, dialihkan ke transfer admin']);
    }

    public function test_channel_tanpa_dukungan_refund_langsung_transfer_admin_tanpa_memanggil_midtrans(): void
    {
        foreach (['bank_transfer', 'echannel', 'cstore', 'channel_baru_tak_dikenal'] as $channel) {
            [$p, $order] = $this->pesananMenunggu(['status' => 'dibatalkan', 'alasan_pembatalan' => 'dibatalkan_pelanggan']);

            $this->notif($order, 'settlement', 600000, ['payment_type' => $channel])->assertOk();

            $bayar = $this->bayarDi($p);
            $this->assertSame(['lunas', 'transfer_admin', false], [$bayar->status, $bayar->refund_cara, $bayar->refund_selesai], $channel);
        }
        $this->assertSame([], app(PembayaranGateway::class)->refundDicatat);
    }

    public function test_cara_refund_menurut_daftar_channel_midtrans(): void
    {
        foreach (['qris', 'gopay', 'shopeepay', 'credit_card', 'dana', 'ovo', 'kredivo', 'akulaku'] as $c) {
            $this->assertSame('midtrans', Pembayaran::caraRefundUntuk($c), $c);
        }
        foreach (['bank_transfer', 'echannel', 'cstore', 'permata', '', null] as $c) {
            $this->assertSame('transfer_admin', Pembayaran::caraRefundUntuk($c), (string) $c);
        }
    }

    public function test_pesanan_dibatalkan_user_atau_sudah_dikonfirmasi_tidak_dihidupkan_dan_direfund(): void
    {
        // Dibatalkan user (bukan kedaluwarsa): uang masuk tetap dikembalikan walau jatah ada
        [$a, $orderA] = $this->pesananMenunggu(['status' => 'dibatalkan', 'alasan_pembatalan' => 'dibatalkan_pelanggan']);
        $this->notif($orderA, 'settlement')->assertOk();
        $this->assertSame('dibatalkan', $a->fresh()->status);
        $this->assertSame('dikembalikan', $this->bayarDi($a)->status);

        // Bayar dua kali: pesanan sudah dikonfirmasi lewat token pertama, token kedua direfund
        [$b, $orderB1] = $this->pesananMenunggu();
        $dua = Pembayaran::create(['id_pemesanan' => $b->id_pemesanan, 'jenis' => 'sewa', 'jumlah' => 600000,
            'order_id_midtrans' => $b->nomor.'-2', 'status' => 'menunggu']);
        $this->notif($orderB1, 'settlement')->assertOk();
        $this->notif($dua->order_id_midtrans, 'settlement')->assertOk();
        $this->assertSame('dikonfirmasi', $b->fresh()->status);
        $this->assertSame('dikembalikan', $dua->fresh()->status);
    }

    public function test_settlement_lewat_batas_saat_jatah_habis_membatalkan_pesanan_dan_refund(): void
    {
        [$p, $order] = $this->pesananMenunggu(['batas_pembayaran' => now()->subMinutes(2)]);
        [$lain] = $this->buatPelanggan('lain@mail.test');
        $this->buatPesanan($lain, $p->tipe, ['status' => 'dikonfirmasi', 'tgl_mulai' => $p->tgl_mulai->toDateString()]);

        $this->notif($order, 'settlement')->assertOk();

        $p->refresh();
        $this->assertSame(['dibatalkan', 'kedaluwarsa'], [$p->status, $p->alasan_pembatalan]);
        $this->assertSame('dikembalikan', $this->bayarDi($p)->status);
    }

    public function test_pesanan_dengan_sopir_terlambat_direfund_bila_sopir_habis(): void
    {
        Pengaturan::updateOrCreate(['kunci' => 'jumlah_sopir'], ['nilai' => '1']);
        [$p, $order] = $this->pesananMenunggu(['jenis_sewa' => 'dengan_sopir', 'transmisi' => null, 'metode_ambil' => null,
            'tujuan' => 'Batu', 'lokasi_jemput' => 'Stasiun', 'status' => 'dibatalkan', 'alasan_pembatalan' => 'kedaluwarsa',
            'batas_pembayaran' => now()->subHour()], unit: 2);
        $this->bayarDi($p)->update(['status' => 'gagal']);
        [$lain] = $this->buatPelanggan('lain@mail.test');
        $tipeLain = $this->buatTipe(2, 'Lain');
        $this->buatPesanan($lain, $tipeLain, ['jenis_sewa' => 'dengan_sopir', 'transmisi' => null, 'status' => 'dikonfirmasi',
            'tgl_mulai' => $p->tgl_mulai->toDateString()]);

        $this->notif($order, 'settlement')->assertOk();

        $this->assertSame('dibatalkan', $p->fresh()->status);
        $this->assertSame('dikembalikan', $this->bayarDi($p)->status);
    }

    public function test_nominal_tidak_cocok_diabaikan(): void
    {
        [$p, $order] = $this->pesananMenunggu();

        $this->notif($order, 'settlement', 100000)->assertOk();

        $this->assertSame('menunggu_pembayaran', $p->fresh()->status);
        $this->assertSame('menunggu', $this->bayarDi($p)->status);
    }

    // ---- status lain ----

    public function test_expire_membatalkan_pesanan_dan_melepas_unit_idempoten(): void
    {
        [$p, $order] = $this->pesananMenunggu();

        $this->notif($order, 'expire')->assertOk();

        $p->refresh();
        $this->assertSame(['dibatalkan', 'kedaluwarsa'], [$p->status, $p->alasan_pembatalan]);
        $this->assertSame('gagal', $this->bayarDi($p)->status);
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'pelaku' => 'sistem', 'aksi' => 'Pembayaran kedaluwarsa, pesanan dibatalkan']);

        $this->notif($order, 'expire')->assertOk();
        $this->assertSame(1, LogAktivitas::where('aksi', 'Pembayaran kedaluwarsa, pesanan dibatalkan')->count());

        // Unit lepas: pelanggan lain bisa memesannya
        [, $token] = $this->buatPelanggan('baru@mail.test');
        $this->withToken($token)->postJson('/api/v1/pemesanan', [
            'id_tipe' => $p->id_tipe, 'jenis_sewa' => 'lepas_kunci', 'transmisi' => 'manual', 'tgl_mulai' => $p->tgl_mulai->toDateString(),
            'durasi' => 2, 'jam_serah' => '09:00', 'metode_ambil' => 'ambil_di_kantor', 'setuju_syarat' => true, 'setuju_syarat_jaminan' => true,
        ])->assertCreated();
    }

    public function test_expire_tidak_membatalkan_pesanan_yang_sudah_dikonfirmasi(): void
    {
        [$p, $order] = $this->pesananMenunggu();
        $dua = Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'sewa', 'jumlah' => 600000,
            'order_id_midtrans' => $p->nomor.'-2', 'status' => 'menunggu']);
        $this->notif($dua->order_id_midtrans, 'settlement')->assertOk();

        $this->notif($order, 'expire')->assertOk(); // token pertama kedaluwarsa belakangan

        $this->assertSame('dikonfirmasi', $p->fresh()->status);
    }

    public function test_deny_dan_cancel_menggagalkan_pembayaran_tetapi_pesanan_menunggu_dan_pending_diam(): void
    {
        [$p, $order] = $this->pesananMenunggu();

        $this->notif($order, 'pending')->assertOk();
        $this->assertSame('menunggu', $this->bayarDi($p)->status);

        $this->notif($order, 'deny')->assertOk();
        $this->assertSame('gagal', $this->bayarDi($p)->status);
        $this->assertSame('menunggu_pembayaran', $p->fresh()->status);

        [$q, $orderQ] = $this->pesananMenunggu();
        $this->notif($orderQ, 'cancel')->assertOk();
        $this->assertSame(['gagal', 'menunggu_pembayaran'], [$this->bayarDi($q)->status, $q->fresh()->status]);
    }

    public function test_order_tak_dikenal_dan_status_tak_dikenal_tetap_200(): void
    {
        $this->notif('PSN-9999-1', 'settlement')->assertOk()->assertJsonPath('message', 'OK');

        [$p, $order] = $this->pesananMenunggu();
        $this->notif($order, 'refund')->assertOk();
        $this->assertSame('menunggu_pembayaran', $p->fresh()->status);
    }
}
