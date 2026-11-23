<?php

/**
 * Pekerja untuk tes konkurensi: boot aplikasi di proses sendiri, tunggu detik mulai bersama, lalu kirim satu
 * permintaan HTTP ke kernel dan cetak {"status":..,"body":..} sebagai satu baris JSON.
 *
 * Pemakaian: php kirim_request.php <berkas-spesifikasi.json> <waktu-mulai-unix-float>
 * Spesifikasi: {"method":"POST","uri":"/api/v1/...","token":"..."|null,"body":{...},"files":{...}}
 * Bila "files" ada (peta bersarang nama => jalur berkas), permintaan dikirim sebagai multipart/form-data.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

[, $berkas, $mulai] = $argv;
$spek = json_decode(file_get_contents($berkas), true);

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Berkas unggahan pekerja ditulis ke direktori sementara dari tes, bukan ke storage/app/private proyek
if ($akarDisk = getenv('DISK_LOCAL_ROOT')) {
    config(['filesystems.disks.local.root' => $akarDisk]);
}

// Melebarkan jendela balapan: jeda tepat sebelum INSERT atau UPDATE pesanan, yaitu sesudah pemeriksaan
// ketersediaan di dalam transaksi. Tanpa kunci, dua permintaan sama-sama lolos pemeriksaan lalu sama-sama
// menyimpan; dengan kunci, yang kedua menunggu. Tanpa jeda ini permintaan selesai terlalu cepat sehingga tes
// tetap lulus walau kunci dihapus.
App\Models\Pemesanan::creating(function () {
    usleep(400_000);
});
App\Models\Pemesanan::updating(function () {
    usleep(400_000);
});

$multipart = ! empty($spek['files']);
$server = ['CONTENT_TYPE' => $multipart ? 'multipart/form-data' : 'application/json', 'HTTP_ACCEPT' => 'application/json'];
if (! empty($spek['token'])) {
    $server['HTTP_AUTHORIZATION'] = 'Bearer '.$spek['token'];
}
$ubahKeBerkas = function (array $peta) use (&$ubahKeBerkas): array {
    return array_map(
        fn ($v) => is_array($v) ? $ubahKeBerkas($v)
            : new Illuminate\Http\UploadedFile($v, basename($v).'.png', 'image/png', null, true),
        $peta
    );
};
$request = $multipart
    ? Request::create($spek['uri'], $spek['method'], $spek['body'] ?? [], [], $ubahKeBerkas($spek['files']), $server)
    : Request::create($spek['uri'], $spek['method'], [], [], [], $server, json_encode($spek['body'] ?? []));

// Boot sudah selesai; semua pekerja melepaskan permintaan pada saat yang sama
while (microtime(true) < (float) $mulai) {
    usleep(200);
}

$response = $kernel->handle($request);

echo "\n".json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)])."\n";
