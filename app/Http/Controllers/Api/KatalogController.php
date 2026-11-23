<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\KatalogRequest;
use App\Http\Resources\TipeKatalogResource;
use App\Models\Pengaturan;
use App\Models\Review;
use App\Models\TipeKendaraan;
use App\Services\KetersediaanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class KatalogController extends Controller
{
    public function __construct(private KetersediaanService $ketersediaan) {}

    public function index(KatalogRequest $request): JsonResponse
    {
        $mulai = $request->input('tgl_mulai');
        $akhir = CarbonImmutable::parse($mulai)->addDays((int) $request->input('durasi') - 1)->toDateString();
        $sisa = $this->ketersediaan->sisaUnit($mulai, $akhir);

        $tipe = TipeKendaraan::query()
            ->with('foto')
            ->whereIn('id_tipe', array_keys(array_filter($sisa)))
            ->when($request->filled('kursi'), fn ($q) => $q->where('kursi', $request->integer('kursi')))
            ->when($request->filled('transmisi'), fn ($q) => $q->whereHas('kendaraan',
                fn ($k) => $k->where('transmisi', $request->input('transmisi'))->where('status', 'tersedia')))
            ->orderBy('nama')
            ->get();

        $rating = $this->ratingRata($tipe->pluck('id_tipe')->all());
        $tipe->each(function ($t) use ($sisa, $rating) {
            $t->sisa_unit = $sisa[$t->id_tipe];
            $t->rating_rata = $rating[$t->id_tipe] ?? null;
        });

        return response()->json(['data' => TipeKatalogResource::collection($tipe)->resolve()]);
    }

    public function show(KatalogRequest $request, int $id): JsonResponse
    {
        $tipe = TipeKendaraan::with('foto')->findOrFail($id);

        if ($request->filled('tgl_mulai') && $request->filled('durasi')) {
            $akhir = CarbonImmutable::parse($request->input('tgl_mulai'))
                ->addDays($request->integer('durasi') - 1)->toDateString();
            $tipe->sisa_unit = $this->ketersediaan->sisaUnit($request->input('tgl_mulai'), $akhir, $id)[$id] ?? 0;
        }
        $tipe->rating_rata = $this->ratingRata([$id])[$id] ?? null;

        $review = Review::query()
            ->where('tampil_di_katalog', true)
            ->whereHas('pemesanan', fn ($q) => $q->where('id_tipe', $id))
            ->with('pemesanan.pelanggan:id_pelanggan,nama')
            ->latest('tgl_review')
            ->get()
            ->map(fn ($r) => [
                'rating' => $r->rating,
                'komentar' => $r->komentar,
                'nama' => $r->pemesanan->pelanggan->nama,
                'tgl_review' => $r->tgl_review->toDateString(),
            ]);

        return response()->json(['data' => (new TipeKatalogResource($tipe))->resolve() + ['review' => $review]]);
    }

    public function info(): JsonResponse
    {
        $ambil = fn (string $k, ?string $d = null) => Pengaturan::ambil($k, $d);

        return response()->json(['data' => [
            'jam_operasional' => $ambil('jam_operasional', '07.00-21.00'),
            'alamat' => $ambil('alamat'),
            'telepon' => $ambil('telepon'),
            'email' => $ambil('email'),
            'batas_antar_gratis' => $ambil('batas_antar_gratis', 'Tlogomas'),
        ]]);
    }

    /** @return array<int,float> */
    private function ratingRata(array $idTipe): array
    {
        return Review::query()
            ->join('pemesanan', 'pemesanan.id_pemesanan', '=', 'review.id_pemesanan')
            ->whereIn('pemesanan.id_tipe', $idTipe)
            ->groupBy('pemesanan.id_tipe')
            ->selectRaw('pemesanan.id_tipe as id_tipe, avg(review.rating) as rata')
            ->pluck('rata', 'id_tipe')
            ->all();
    }
}
