<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\MidtransWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function midtrans(Request $request, MidtransWebhookService $webhook): JsonResponse
    {
        $d = $request->all();
        if (! $webhook->tandaTanganValid($d)) {
            throw new ApiException('Tanda tangan tidak cocok', 'TANDA_TANGAN_SALAH', 403);
        }

        $webhook->proses($d);

        return response()->json(['message' => 'OK']);
    }
}
