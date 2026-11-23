<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\DokumenPdfService;
use App\Services\LaporanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /admin/laporan dan /admin/laporan/pdf (Spek API bagian 11). */
class LaporanController extends Controller
{
    public function __construct(private LaporanService $laporan) {}

    /** Data kosong tetap 200: tampilan memberi tahu "tidak ada data pada periode ini". */
    public function index(Request $request): JsonResponse
    {
        [$periode, $status] = $this->query($request);

        return response()->json(['data' => $this->laporan->susun($periode, $status)]);
    }

    /** Data kosong: 409 DATA_KOSONG (tombol PDF dinonaktifkan di tampilan). */
    public function pdf(Request $request, DokumenPdfService $dokumen)
    {
        [$periode, $status] = $this->query($request);
        $laporan = $this->laporan->susun($periode, $status);

        if (empty($laporan['data'])) {
            throw new ApiException('Tidak ada data pada periode ini', 'DATA_KOSONG');
        }

        return response($dokumen->laporan($laporan, $status), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="laporan-'.$laporan['periode']['dari'].'_'.$laporan['periode']['sampai'].'.pdf"',
        ]);
    }

    /** @return array{0: string, 1: ?string} */
    private function query(Request $request): array
    {
        $d = $request->validate([
            'periode' => ['nullable', 'in:'.implode(',', LaporanService::PERIODE)],
            'status' => ['nullable', 'in:menunggu_pembayaran,dikonfirmasi,berjalan,selesai,pengajuan_pembatalan,dibatalkan'],
        ]);

        return [(string) ($d['periode'] ?? '7'), $d['status'] ?? null];
    }
}
