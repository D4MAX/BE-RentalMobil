<?php

namespace Tests\Feature;

use App\Services\Pembayaran\MidtransGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Implementasi Midtrans asli, tanpa jaringan: hanya bentuk permintaan ke Snap sandbox yang diperiksa. */
class MidtransGatewayTest extends TestCase
{
    private const PEMBELI = ['nama' => 'Budi', 'email' => 'budi@mail.test', 'no_hp' => '0812345678'];

    public function test_mengirim_permintaan_snap_sandbox_dengan_basic_auth_dan_kedaluwarsa_sesuai_sisa_waktu(): void
    {
        config(['midtrans.server_key' => 'SB-Mid-server-contoh', 'midtrans.is_production' => false]);
        Http::fake(['app.sandbox.midtrans.com/*' => Http::response(['token' => 'tok-123', 'redirect_url' => 'x'], 201)]);

        $t = (new MidtransGateway)->buatTransaksi('PSN-0015-1', 900000, self::PEMBELI, 45);

        $this->assertSame(['PSN-0015-1', 'tok-123'], [$t->orderId, $t->snapToken]);
        Http::assertSent(function (Request $r) {
            return $r->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions'
                && $r->hasHeader('Authorization', 'Basic '.base64_encode('SB-Mid-server-contoh:'))
                && $r['transaction_details'] === ['order_id' => 'PSN-0015-1', 'gross_amount' => 900000]
                && $r['expiry'] === ['unit' => 'minutes', 'duration' => 45]
                && $r['customer_details']['email'] === 'budi@mail.test';
        });
    }

    public function test_galat_midtrans_atau_kunci_kosong_melempar_exception(): void
    {
        config(['midtrans.server_key' => 'SB-Mid-server-contoh']);
        Http::fake(['*' => Http::response(['error_messages' => ['x']], 401)]);
        try {
            (new MidtransGateway)->buatTransaksi('PSN-1-1', 1000, self::PEMBELI, 60);
            $this->fail('Seharusnya melempar exception');
        } catch (\Illuminate\Http\Client\RequestException) {
            $this->addToAssertionCount(1);
        }

        config(['midtrans.server_key' => null]);
        $this->expectException(\RuntimeException::class);
        (new MidtransGateway)->buatTransaksi('PSN-1-1', 1000, self::PEMBELI, 60);
    }

    public function test_batalkan_transaksi_memakai_endpoint_cancel_tanpa_body(): void
    {
        config(['midtrans.server_key' => 'SB-Mid-server-contoh', 'midtrans.api_url' => 'https://api.sandbox.midtrans.com']);
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response(['status_code' => '200', 'transaction_status' => 'cancel'], 200)]);

        (new MidtransGateway)->batalkanTransaksi('PSN-0015-1');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.sandbox.midtrans.com/v2/PSN-0015-1/cancel'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('SB-Mid-server-contoh:')));
    }

    public function test_penolakan_di_body_walau_http_200_dianggap_gagal(): void
    {
        config(['midtrans.server_key' => 'SB-Mid-server-contoh', 'midtrans.api_url' => 'https://api.sandbox.midtrans.com']);
        // Core API memakai status_code di body: 412 = status transaksi tak bisa diubah, 406 = refund ganda
        Http::fake(['*' => Http::response(['status_code' => '412', 'status_message' => 'Merchant cannot modify the status of the transaction'], 200)]);

        foreach ([
            fn () => (new MidtransGateway)->batalkanTransaksi('PSN-1-1'),
            fn () => (new MidtransGateway)->refund('PSN-1-1', 1000, 'x'),
        ] as $panggil) {
            try {
                $panggil();
                $this->fail('Seharusnya melempar exception');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('412', $e->getMessage());
            }
        }
    }

    public function test_refund_http_error_melempar_exception(): void
    {
        config(['midtrans.server_key' => 'SB-Mid-server-contoh']);
        Http::fake(['*' => Http::response(['status_code' => '406'], 406)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        (new MidtransGateway)->refund('PSN-1-1', 1000, 'x');
    }

    public function test_refund_memakai_endpoint_core_api_dengan_kunci_tetap(): void
    {
        config(['midtrans.server_key' => 'SB-Mid-server-contoh', 'midtrans.is_production' => false]);
        config(['midtrans.api_url' => 'https://api.sandbox.midtrans.com']);
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response(['status_code' => '200'], 200)]);

        (new MidtransGateway)->refund('PSN-0015-1', 900000, 'Unit tidak tersedia');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.sandbox.midtrans.com/v2/PSN-0015-1/refund'
            && $r['amount'] === 900000 && $r['refund_key'] === 'refund-PSN-0015-1');
    }
}
