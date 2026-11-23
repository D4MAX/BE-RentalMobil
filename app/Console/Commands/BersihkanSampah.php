<?php

namespace App\Console\Commands;

use App\Models\Kendaraan;
use App\Models\TipeKendaraan;
use App\Services\SampahService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Membuang PERMANEN tipe dan unit yang sudah di tempat sampah lebih dari config('rental.hari_sampah') hari (bawaan 30),
 * termasuk berkas foto dan detail terkait. Hanya menyentuh baris ber-deleted_at. Satu data yang gagal (mis. berkas hilang
 * atau masih dirujuk) dilaporkan dan dilewati; sisanya tetap diproses. Unit dibuang lebih dulu supaya tipenya ikut bersih.
 */
class BersihkanSampah extends Command
{
    protected $signature = 'sampah:bersihkan';

    protected $description = 'Buang permanen tipe dan unit di tempat sampah yang lebih lama dari rental.hari_sampah hari';

    public function handle(SampahService $sampah): int
    {
        $hari = SampahService::hariSampah();
        $batas = now()->subDays($hari);
        $unit = $tipe = $dilewati = $gagal = 0;

        Kendaraan::onlyTrashed()->where('deleted_at', '<', $batas)->orderBy('id_kendaraan')->pluck('id_kendaraan')
            ->each(function (int $id) use ($sampah, $batas, &$unit, &$dilewati, &$gagal) {
                try {
                    $sampah->buangUnitKedaluwarsa($id, $batas) ? $unit++ : $dilewati++;
                } catch (Throwable $e) {
                    $gagal++;
                    $this->warn("Unit {$id} dilewati: {$e->getMessage()}");
                    report($e);
                }
            });

        TipeKendaraan::onlyTrashed()->where('deleted_at', '<', $batas)->orderBy('id_tipe')->pluck('id_tipe')
            ->each(function (int $id) use ($sampah, $batas, &$tipe, &$dilewati, &$gagal) {
                try {
                    $sampah->buangTipeKedaluwarsa($id, $batas) === null ? $dilewati++ : $tipe++;
                } catch (Throwable $e) {
                    $gagal++;
                    $this->warn("Tipe {$id} dilewati: {$e->getMessage()}");
                    report($e);
                }
            });

        $this->info("Tempat sampah (batas {$hari} hari): unit dibuang {$unit}, tipe dibuang {$tipe}, dilewati {$dilewati}, gagal {$gagal}");

        return self::SUCCESS; // satu data bermasalah tidak menggagalkan jadwal harian
    }
}
