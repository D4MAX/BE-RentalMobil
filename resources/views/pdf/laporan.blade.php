<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan transaksi {{ $dari }} - {{ $sampai }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 4px 5px; text-align: left; vertical-align: top; }
        table.data th { background: #eee; border-bottom: 1px solid #999; }
        table.data td { border-bottom: 1px solid #ddd; }
        .kanan { text-align: right; }
        .ringkas td { padding: 2px 0; }
    </style>
</head>
<body>
    <h1>LAPORAN TRANSAKSI</h1>
    <div class="muted">
        Periode {{ $dari }} s.d. {{ $sampai }} (menurut tanggal mulai sewa)
        @if ($status) &middot; status {{ $status }} @endif
    </div>

    <table class="ringkas">
        <tr><td>Jumlah transaksi</td><td class="kanan"><strong>{{ $ringkasan['jumlah_transaksi'] }}</strong></td></tr>
        <tr><td>Jumlah dibatalkan</td><td class="kanan">{{ $ringkasan['jumlah_dibatalkan'] }}</td></tr>
        <tr><td>Total pendapatan (dikonfirmasi, berjalan, selesai)</td><td class="kanan"><strong>{{ $rupiah($ringkasan['total_pendapatan']) }}</strong></td></tr>
        @foreach ($ringkasan['per_status'] as $nama => $jumlah)
            <tr><td class="muted">&nbsp;&nbsp;{{ $nama }}</td><td class="kanan muted">{{ $jumlah }}</td></tr>
        @endforeach
    </table>

    <table class="data">
        <tr>
            <th>Tgl mulai</th><th>Nomor</th><th>Pelanggan</th><th>Unit</th><th>Status</th>
            <th class="kanan">Sewa</th><th class="kanan">Tagihan tambahan</th>
        </tr>
        @foreach ($data as $baris)
            <tr>
                <td>{{ $baris['tgl_mulai'] }}</td><td>{{ $baris['nomor'] }}</td><td>{{ $baris['pelanggan'] }}</td>
                <td>{{ $baris['unit'] }}</td><td>{{ $baris['status'] }}</td>
                <td class="kanan">{{ $rupiah($baris['sewa']) }}</td><td class="kanan">{{ $rupiah($baris['tagihan_tambahan']) }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
