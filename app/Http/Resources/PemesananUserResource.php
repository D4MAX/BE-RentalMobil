<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pesanan untuk pemiliknya (Spek API bagian 6): tanpa catatan_admin, log_aktivitas, no_identitas, dan detail internal unit.
 * Dua bentuk: ringkas (daftar) dan lengkap (detail).
 */
class PemesananUserResource extends JsonResource
{
    private bool $lengkap = false;

    public static function detail($pemesanan): self
    {
        $r = new self($pemesanan);
        $r->lengkap = true;

        return $r;
    }

    public function toArray(Request $request): array
    {
        $ringkas = [
            'id' => $this->id_pemesanan,
            'nomor' => $this->nomor,
            'status' => $this->status,
            'tipe' => ['id' => $this->tipe->id_tipe, 'nama' => $this->tipe->nama],
            'tgl_mulai' => $this->tgl_mulai->toDateString(),
            'durasi' => $this->durasi,
            'total_biaya' => $this->total_biaya,
            'aksi_utama' => $this->aksiUtama(),
        ];
        if (! $this->lengkap) {
            return $ringkas;
        }

        $sewa = $this->pembayaran->where('jenis', 'sewa');
        // Yang sudah dibayar didahulukan; selain itu percobaan terbaru
        $bayar = $sewa->whereIn('status', ['lunas', 'dikembalikan'])->sortByDesc('id_pembayaran')->first()
            ?? $sewa->sortByDesc('id_pembayaran')->first();
        $tambahan = $this->pembayaran->where('jenis', 'tambahan')->sortByDesc('id_pembayaran')->first();

        return [
            'id' => $ringkas['id'],
            'nomor' => $ringkas['nomor'],
            'status' => $ringkas['status'],
            'tipe' => $ringkas['tipe'],
            'jenis_sewa' => $this->jenis_sewa,
            'transmisi' => $this->transmisi,
            'tgl_mulai' => $ringkas['tgl_mulai'],
            'durasi' => $this->durasi,
            'tgl_kembali' => $this->tgl_kembali->toDateString(),
            'jam_serah' => substr((string) $this->jam_serah, 0, 5),
            'metode_ambil' => $this->metode_ambil,
            'lokasi_jemput' => $this->lokasi_jemput,
            'tujuan' => $this->tujuan,
            'total_biaya' => $this->total_biaya,
            // Plat baru terlihat saat berjalan atau sesudahnya
            'plat_nomor' => in_array($this->status, ['berjalan', 'selesai'], true) ? $this->kendaraan?->plat_nomor : null,
            'pembayaran' => $bayar ? ['metode' => $bayar->metode, 'status' => $bayar->status, 'order_id' => $bayar->order_id_midtrans] : null,
            'tagihan_tambahan' => $tambahan ? ['jumlah' => $tambahan->jumlah, 'status' => $tambahan->status] : null,
            'jaminan' => null,            // diisi saat serah terima dan pengembalian dikerjakan
            'hasil_pemeriksaan' => null,  // idem
            'aksi' => $this->aksiUser(),
        ];
    }
}
