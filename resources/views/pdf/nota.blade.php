<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Nota {{ $nomor }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 5px 6px; text-align: left; vertical-align: top; }
        table.rincian th { background: #eee; border-bottom: 1px solid #999; }
        table.rincian td { border-bottom: 1px solid #ddd; }
        .kanan { text-align: right; }
        .total td { font-weight: bold; border-top: 2px solid #333; }
        .status { display: inline-block; padding: 2px 8px; border: 1px solid #333; font-weight: bold; text-transform: uppercase; }
    </style>
</head>
<body>
    <h1>NOTA SEWA MOBIL</h1>
    <div class="muted">
        {{ $kantor['alamat'] ?? '' }}
        @if (! empty($kantor['telepon'])) &middot; {{ $kantor['telepon'] }} @endif
        @if (! empty($kantor['email'])) &middot; {{ $kantor['email'] }} @endif
    </div>

    <table>
        <tr>
            <td><strong>Nomor</strong><br>{{ $nomor }}</td>
            <td><strong>Tanggal pesan</strong><br>{{ $tglPesan }}</td>
            <td><strong>Status pesanan</strong><br><span class="status">{{ $statusPesanan }}</span></td>
        </tr>
        <tr>
            <td><strong>Penyewa</strong><br>{{ $pelanggan }}</td>
            <td><strong>Mobil</strong><br>{{ $tipe }}</td>
            <td><strong>Jenis sewa</strong><br>{{ $jenisSewa }}</td>
        </tr>
        <tr>
            <td><strong>Periode</strong><br>{{ $tglMulai }} s.d. {{ $tglKembali }} ({{ $durasi }} hari)</td>
            <td><strong>Jam serah</strong><br>{{ $jamSerah }}</td>
            <td><strong>Lokasi</strong><br>{{ $lokasi }}</td>
        </tr>
    </table>

    <table class="rincian">
        <tr><th>Rincian</th><th class="kanan">Tarif per hari</th><th class="kanan">Hari</th><th class="kanan">Jumlah</th></tr>
        <tr>
            <td>{{ $jenisSewa }} {{ $tipe }}</td>
            <td class="kanan">{{ $rupiah($tarifPerHari) }}</td>
            <td class="kanan">{{ $durasi }}</td>
            <td class="kanan">{{ $rupiah($total) }}</td>
        </tr>
        <tr class="total"><td colspan="3">Total biaya sewa</td><td class="kanan">{{ $rupiah($total) }}</td></tr>
    </table>

    <table>
        <tr>
            <td><strong>Pembayaran</strong><br>{{ $pembayaran['status'] }}@if ($pembayaran['metode']) ({{ $pembayaran['metode'] }})@endif</td>
            <td><strong>Order ID</strong><br>{{ $pembayaran['order_id'] ?? '-' }}</td>
            <td><strong>Tanggal bayar</strong><br>{{ $pembayaran['tgl_bayar'] ?? '-' }}</td>
        </tr>
    </table>

    @if ($tagihanTambahan)
        <table>
            <tr>
                <td><strong>Tagihan tambahan</strong> (denda, BBM, kerusakan, biaya lain)<br>
                    {{ $rupiah($tagihanTambahan['jumlah']) }} &mdash; {{ $tagihanTambahan['status'] }}</td>
            </tr>
        </table>
    @endif

    <p class="muted">Nota ini dibuat otomatis oleh sistem dan sah tanpa tanda tangan.</p>
</body>
</html>
