<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\LogAktivitas;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** PATCH /admin/review/{id}: tampilkan atau sembunyikan review di katalog (bawaannya tersembunyi). */
class ReviewAdminController extends Controller
{
    public function update(Request $request, int $id): JsonResponse
    {
        $this->booleanDariTeks($request, ['tampil_di_katalog']);
        $request->validate(['tampil_di_katalog' => ['required', 'boolean']]);
        $tampil = $request->boolean('tampil_di_katalog');

        DB::transaction(function () use ($request, $id, $tampil) {
            $review = Review::query()->lockForUpdate()->find($id) ?? throw new ApiException('Review tidak ditemukan', 'TIDAK_DITEMUKAN', 404);
            $review->update(['tampil_di_katalog' => $tampil]);
            LogAktivitas::catat($tampil ? 'Review ditampilkan di katalog' : 'Review disembunyikan dari katalog', $request->user(), 'review', $id);
        });

        return response()->json(['data' => ['tampil_di_katalog' => $tampil]]);
    }
}
