<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\DetailKendaraan;
use App\Models\Kendaraan;
use App\Models\Pemesanan;
use App\Services\KendaraanAdminService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** /admin/unit (Spek API bagian 8). */
class UnitAdminController extends Controller
{
    public function __construct(private KendaraanAdminService $kendaraan) {}

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate([
            'id_tipe' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:tersedia,disewa,dalam_perawatan,tidak_tersedia'],
        ]);

        // "disewa" dan "tersedia" dibedakan oleh pesanan berjalan yang memakai unit; keduanya berkondisi tersedia
        $dipakai = fn ($q) => $q->select(DB::raw(1))->from('pemesanan')
            ->whereColumn('pemesanan.id_kendaraan', 'kendaraan.id_kendaraan')->where('pemesanan.status', 'berjalan');

        $unit = Kendaraan::query()->with('detail', 'tipe:id_tipe,nama')
            ->when($d['id_tipe'] ?? null, fn ($q, $v) => $q->where('id_tipe', $v))
            ->when($d['status'] ?? null, fn ($q, $v) => match ($v) {
                'disewa' => $q->where('status', 'tersedia')->whereExists($dipakai),
                'tersedia' => $q->where('status', 'tersedia')->whereNotExists($dipakai),
                default => $q->where('status', $v),
            })
            ->orderBy('plat_nomor')->get();

        // Satu query untuk semua unit, bukan satu per unit
        $disewa = Pemesanan::query()->where('status', 'berjalan')->whereIn('id_kendaraan', $unit->modelKeys())->pluck('id_kendaraan')->flip();

        return response()->json(['data' => $unit->map(fn ($u) => $this->kendaraan->bentukUnit($u, $disewa->has($u->id_kendaraan)))->values()->all()]);
    }

    public function show(int $id): JsonResponse
    {
        $u = Kendaraan::query()->with('detail', 'tipe:id_tipe,nama')->find($id)
            ?? throw new ApiException('Unit tidak ditemukan', 'TIDAK_DITEMUKAN', 404);

        return response()->json(['data' => $this->kendaraan->bentukUnit($u)]);
    }

    public function store(Request $request): JsonResponse
    {
        $unit = $this->kendaraan->buatUnit($request->user(), $this->validasi($request));

        return response()->json(['data' => $this->kendaraan->bentukUnit($unit)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $unit = $this->kendaraan->ubahUnit($request->user(), $id, $this->validasi($request, $id));

        return response()->json(['data' => $this->kendaraan->bentukUnit($unit)]);
    }

    /** konfirmasi=true (query atau body) melanjutkan walau ada pesanan terdampak. */
    public function destroy(Request $request, int $id)
    {
        $this->kendaraan->hapusUnit($request->user(), $id, $request->boolean('konfirmasi'));

        return response()->noContent();
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $this->booleanDariTeks($request, ['konfirmasi']);
        $d = $request->validate([
            'status' => ['required', 'in:tidak_tersedia,tersedia'],
            'konfirmasi' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->kendaraan->ubahStatusUnit($request->user(), $id, $d['status'], $request->boolean('konfirmasi'))]);
    }

    /**
     * Nomor yang diisi harus unik di seluruh unit: dibandingkan tanpa spasi di tepi dan tanpa peka huruf besar kecil
     * (tidak bergantung pada collation kolom). Kosong tidak dihitung; saat ubah, unit itu sendiri diabaikan.
     * Format dan panjang sengaja tidak dipaksa.
     */
    private function unikDiDetail(string $kolom, string $nama, ?int $idUnit): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal) use ($kolom, $nama, $idUnit) {
            $nilai = mb_strtolower(trim((string) $nilai));
            if ($nilai === '') {
                return;
            }
            $dipakai = DetailKendaraan::query()
                ->whereRaw("LOWER(TRIM({$kolom})) = ?", [$nilai])
                ->whereHas('kendaraan') // unit di tempat sampah dijawab terpisah (UNIT_ADA_DI_SAMPAH, lihat KendaraanAdminService)
                ->when($idUnit, fn ($q) => $q->where('id_kendaraan', '!=', $idUnit))
                ->exists();
            if ($dipakai) {
                $gagal("{$nama} sudah dipakai unit lain.");
            }
        };
    }

    /**
     * Plat dirapikan sebelum divalidasi dan disimpan: huruf besar, spasi berlebih dibuang, dan bila polanya cocok
     * (1-2 huruf, 1-4 angka, 0-3 huruf) ditulis dengan satu spasi di antara bagiannya, mis. "n1234ab" menjadi "N 1234 AB".
     */
    private function rapikanPlat(mixed $plat): mixed
    {
        if (! is_string($plat)) {
            return $plat;
        }
        $p = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $plat)));

        return preg_match('/^([A-Z]{1,2})\s*(\d{1,4})\s*([A-Z]{0,3})$/', $p, $m) ? trim($m[1].' '.$m[2].' '.$m[3]) : $p;
    }

    /** Plat unik tanpa peka huruf besar kecil dan tanpa spasi (N1234AB sama dengan n 1234 ab); unit itu sendiri diabaikan saat ubah. */
    private function platUnik(?int $idUnit): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal) use ($idUnit) {
            $kunci = str_replace(' ', '', mb_strtoupper((string) $nilai));
            $dipakai = Kendaraan::query()
                ->whereRaw("REPLACE(UPPER(plat_nomor), ' ', '') = ?", [$kunci])
                ->when($idUnit, fn ($q) => $q->where('id_kendaraan', '!=', $idUnit))
                ->exists();
            if ($dipakai) {
                $gagal('Plat nomor sudah dipakai unit lain.');
            }
        };
    }

    private function validasi(Request $request, ?int $id = null): array
    {
        $request->merge(['plat_nomor' => $this->rapikanPlat($request->input('plat_nomor'))]);

        return $request->validate([
            'id_tipe' => ['required', 'integer', Rule::exists('tipe_kendaraan', 'id_tipe')->whereNull('deleted_at')],
            // Pola umum yang longgar: 1-2 huruf, 1-4 angka, 0-3 huruf (sesudah dirapikan, satu spasi antar bagian)
            'plat_nomor' => ['bail', 'required', 'string', 'max:15', 'regex:/^[A-Z]{1,2} \d{1,4}( [A-Z]{1,3})?$/', $this->platUnik($id)],
            'tahun' => ['required', 'integer', 'between:1990,'.(now()->year + 1)],
            'warna' => ['required', 'string', 'max:30'],
            'transmisi' => ['required', 'in:manual,matic'],
            'km_terakhir' => ['nullable', 'integer', 'min:0'],
            'detail' => ['nullable', 'array'],
            'detail.nama_pemilik' => ['nullable', 'string', 'max:255'],
            'detail.status_kepemilikan' => ['nullable', 'in:milik_perusahaan,titipan'],
            'detail.no_rangka' => ['bail', 'required', 'string', 'max:50', $this->unikDiDetail('no_rangka', 'No rangka', $id)],
            'detail.no_mesin' => ['bail', 'required', 'string', 'max:50', $this->unikDiDetail('no_mesin', 'No mesin', $id)],
            'detail.no_bpkb' => ['nullable', 'string', 'max:50', $this->unikDiDetail('no_bpkb', 'No BPKB', $id)],
            'detail.masa_berlaku_stnk' => ['nullable', 'date_format:Y-m-d'],
            'detail.catatan' => ['nullable', 'string', 'max:2000'],
        ], ['detail.no_rangka.required' => 'No rangka wajib diisi.', 'detail.no_mesin.required' => 'No mesin wajib diisi.', 'plat_nomor.regex' => 'Format plat nomor tidak dikenali. Gunakan 1-2 huruf, 1-4 angka, lalu 0-3 huruf, mis. N 1234 AB.']);
    }
}
