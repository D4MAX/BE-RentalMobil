<?php

namespace App\Services\Pembayaran;

final readonly class TransaksiPembayaran
{
    public function __construct(public string $orderId, public string $snapToken) {}
}
