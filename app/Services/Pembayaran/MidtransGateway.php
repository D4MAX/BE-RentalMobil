<?php

namespace App\Services\Pembayaran;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Midtrans lewat REST (sandbox atau produksi menurut config/midtrans.php).
 *
 * Bentuk permintaan sudah dicocokkan dengan dokumentasi resmi Midtrans (dibaca 2026-11-13):
 *  - Snap:   POST {snap_url}                       https://docs.midtrans.com/reference/create-snap-transaction (token)
 *  - Refund: POST {api_url}/v2/{order_id}/refund   body refund_key, amount, reason; https://docs.midtrans.com/reference/refund-transaction
 *  - Cancel: POST {api_url}/v2/{order_id}/cancel   tanpa body;                      https://docs.midtrans.com/reference/cancel-transaction
 * BELUM DICOBA ke sandbox sungguhan (tidak ada kunci saat ini). Yang tidak terdokumentasi dan karena itu dijaga:
 * kode status ada di body ("status_code") dan bisa berbeda dari kode HTTP, jadi body ikut diperiksa; perilaku cancel
 * untuk order yang belum punya transaksi tidak dijelaskan, jadi galatnya dianggap wajar (pemanggil best effort).
 */
class MidtransGateway implements PembayaranGateway
{
    public function buatTransaksi(string $orderId, int $jumlah, array $pembeli, int $kedaluwarsaMenit): TransaksiPembayaran
    {
        $respons = $this->http()
            ->post(config('midtrans.snap_url'), [
                'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $jumlah],
                'customer_details' => [
                    'first_name' => $pembeli['nama'],
                    'email' => $pembeli['email'],
                    'phone' => $pembeli['no_hp'],
                ],
                'expiry' => ['unit' => 'minutes', 'duration' => max(1, $kedaluwarsaMenit)],
            ])
            ->throw();

        $token = $respons->json('token');
        if (! $token) {
            throw new RuntimeException('Respons Midtrans tidak memuat token');
        }

        return new TransaksiPembayaran($orderId, $token);
    }

    /**
     * Refund penuh. refund_key tetap per order: mengulang kunci yang sama adalah percobaan ulang yang aman
     * (Midtrans menolak refund ganda dengan 406, dan hanya menerima ulang kunci sama dalam 7 hari).
     * Channel yang didukung ada di Pembayaran::CHANNEL_REFUND_MIDTRANS; virtual account dan gerai tidak ada di sana.
     */
    public function refund(string $orderId, int $jumlah, string $alasan): void
    {
        $this->periksa($this->http()->post($this->urlOrder($orderId, 'refund'), [
            'refund_key' => 'refund-'.$orderId,
            'amount' => $jumlah,
            'reason' => mb_substr($alasan, 0, 255),
        ]));
    }

    public function batalkanTransaksi(string $orderId): void
    {
        $this->periksa($this->http()->post($this->urlOrder($orderId, 'cancel')));
    }

    private function urlOrder(string $orderId, string $aksi): string
    {
        return rtrim(config('midtrans.api_url'), '/').'/v2/'.rawurlencode($orderId).'/'.$aksi;
    }

    /** Core API melaporkan hasil di body: 200 berhasil; 406 refund ganda, 412 status tak bisa diubah, 414 nominal salah. */
    private function periksa(Response $respons): void
    {
        $respons->throw();

        $kode = (string) $respons->json('status_code', '200');
        if (! in_array($kode, ['200', '201', '202'], true)) {
            throw new RuntimeException("Midtrans menolak ({$kode}): ".$respons->json('status_message', 'tanpa pesan'));
        }
    }

    private function http(): PendingRequest
    {
        $serverKey = config('midtrans.server_key');
        if (! $serverKey) {
            throw new RuntimeException('MIDTRANS_SERVER_KEY belum diisi');
        }

        return Http::withBasicAuth($serverKey, '')->acceptJson()->asJson()->timeout(config('midtrans.timeout'));
    }
}
