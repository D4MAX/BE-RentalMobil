<?php

namespace App\Http\Requests\Api;

use App\Exceptions\ApiException;
use App\Models\Pemesanan;
use Illuminate\Validation\Rule;

/**
 * POST /admin/pemesanan/{id}/pengembalian dan /pengembalian/preview (Spek API bagian 9). Hanya multipart/form-data,
 * dengan field diratakan agar mudah diisi di Swagger: kondisi[bagian], biaya[bagian], keterangan[bagian], foto[bagian],
 * biaya_tambahan[jenis], keterangan_tambahan[jenis]. validated() menyusunnya kembali menjadi struktur daftar
 * (kondisi[], biaya_tambahan[]) yang dipakai AdminPemesananService, sehingga hitungan dan penyimpanan tidak berubah.
 * Preview memakai body yang sama tetapi tidak menuntut foto dan persetujuan penyewa, karena hanya menghitung ringkasan.
 */
class PengembalianRequest extends ApiRequest
{
    public const BAGIAN = ['depan', 'belakang', 'sisi_kiri', 'sisi_kanan', 'atap', 'kaca_lampu', 'ban_velg', 'interior'];

    /** Tol dan parkir sengaja tidak ada: pada sewa dengan sopir keduanya dibayar langsung oleh penyewa saat perjalanan. */
    public const JENIS_TAMBAHAN = ['cuci', 'kelengkapan_hilang', 'biaya_antar', 'lainnya'];

    private const KONDISI = ['baik', 'lecet', 'penyok', 'retak', 'rusak'];

    protected function prepareForValidation(): void
    {
        if (! str_starts_with(strtolower((string) $this->header('Content-Type')), 'multipart/form-data')) {
            throw new ApiException('Endpoint ini hanya menerima multipart/form-data', 'FORMAT_TIDAK_DIDUKUNG', 415);
        }
        $this->booleanDariTeks(['disetujui_penyewa', 'unit_perlu_servis']);
    }

    public function preview(): bool
    {
        return str_ends_with($this->path(), '/preview');
    }

    /** Bagian ini tidak baik: biaya dan foto wajib. Bagian yang kosong dianggap baik (galat wajib diisi muncul di kondisi[bagian]). */
    private function tidakBaik(string $bagian): bool
    {
        $nilai = $this->input("kondisi.{$bagian}");

        return $nilai !== null && $nilai !== '' && $nilai !== 'baik';
    }

    public function rules(): array
    {
        $kunci = Pemesanan::query()->find($this->route('id'))?->jenis_sewa === 'lepas_kunci';

        $aturan = [
            'tgl_kembali' => ['required', 'date_format:Y-m-d'],
            'jam_kembali' => ['required', 'date_format:H:i'],

            'kondisi' => ['required', 'array'],
            'biaya' => ['nullable', 'array'],
            'keterangan' => ['nullable', 'array'],
            'foto' => ['nullable', 'array'],

            'biaya_tambahan' => ['nullable', 'array'],
            'keterangan_tambahan' => ['nullable', 'array'],

            'disetujui_penyewa' => [$this->preview() ? 'nullable' : 'accepted'],
            'catatan_admin' => ['nullable', 'string', 'max:2000'],
            'status_pembayaran_tagihan' => ['nullable', 'in:belum_dibayar,tunai,transfer'],

            'unit_perlu_servis' => ['nullable', 'boolean'],
            'servis.tingkat' => [Rule::requiredIf(fn () => $this->boolean('unit_perlu_servis')), 'nullable', 'in:ringan,sedang,perlu_bengkel'],
            'servis.keperluan' => [Rule::requiredIf(fn () => $this->boolean('unit_perlu_servis')), 'nullable', 'string', 'max:100'],

            // Khusus lepas kunci (dengan sopir sudah termasuk BBM dan tanpa jaminan)
            'odometer_akhir' => [$kunci ? 'required' : 'nullable', 'integer', 'min:0'],
            'bbm_akhir' => [$kunci ? 'required' : 'nullable', 'in:'.implode(',', SerahTerimaRequest::BBM)],
            'biaya_kekurangan_bbm' => [$kunci ? 'required' : 'nullable', 'integer', 'min:0'],
            'kebersihan' => [$kunci ? 'required' : 'nullable', 'in:bersih,kotor_biasa,sangat_kotor'],
        ];

        foreach (self::BAGIAN as $b) {
            $rusak = fn () => $this->tidakBaik($b);
            $aturan["kondisi.{$b}"] = ['required', 'in:'.implode(',', self::KONDISI)];
            $aturan["biaya.{$b}"] = [Rule::requiredIf($rusak), 'nullable', 'integer', 'min:0'];
            $aturan["keterangan.{$b}"] = ['nullable', 'string', 'max:255'];
            // nullable: kolom foto yang dikirim kosong (teks kosong) dianggap tidak dikirim, bukan diperiksa sebagai gambar
            $aturan["foto.{$b}"] = [Rule::requiredIf(fn () => $rusak() && ! $this->preview()), 'nullable', 'image', 'max:5120'];
        }
        foreach (self::JENIS_TAMBAHAN as $j) {
            $aturan["biaya_tambahan.{$j}"] = ['nullable', 'integer', 'min:0'];
            $aturan["keterangan_tambahan.{$j}"] = ['nullable', 'string', 'max:255'];
        }

        return $aturan;
    }

    public function messages(): array
    {
        $pesan = ['disetujui_penyewa.accepted' => 'Penyewa harus menyetujui hasil pemeriksaan'];
        foreach (self::BAGIAN as $b) {
            $nama = str_replace('_', ' ', $b);
            $pesan["kondisi.{$b}.required"] = "Kondisi bagian {$nama} wajib diisi";
            $pesan["kondisi.{$b}.in"] = "Kondisi bagian {$nama} harus salah satu dari: ".implode(', ', self::KONDISI);
            $pesan["biaya.{$b}.required"] = "Biaya perbaikan bagian {$nama} wajib diisi karena kondisinya tidak baik";
            $pesan["foto.{$b}.required"] = "Foto bagian {$nama} wajib diunggah karena kondisinya tidak baik";
            $pesan["foto.{$b}.image"] = "Foto bagian {$nama} harus berupa gambar";
            $pesan["foto.{$b}.max"] = "Foto bagian {$nama} maksimal 5 MB";
        }

        return $pesan;
    }

    /** Menyusun field datar menjadi struktur yang dipakai service: kondisi[] dan biaya_tambahan[]. */
    public function validated($key = null, $default = null)
    {
        $d = parent::validated();

        $kondisi = [];
        foreach (self::BAGIAN as $b) {
            $k = $d['kondisi'][$b];
            $kondisi[] = [
                'bagian' => $b, 'kondisi' => $k,
                'biaya' => $k === 'baik' ? 0 : (int) $d['biaya'][$b], // biaya bagian yang baik diabaikan
                'keterangan' => $d['keterangan'][$b] ?? null,
            ];
        }
        $d['kondisi'] = $kondisi;
        unset($d['biaya'], $d['keterangan']);

        $tambahan = [];
        foreach (self::JENIS_TAMBAHAN as $j) {
            $nominal = (int) ($d['biaya_tambahan'][$j] ?? 0);
            if ($nominal > 0) { // kosong atau 0 berarti tidak ada biaya jenis itu
                $tambahan[] = ['jenis' => $j, 'keterangan' => $d['keterangan_tambahan'][$j] ?? null, 'nominal' => $nominal];
            }
        }
        $d['biaya_tambahan'] = $tambahan;
        unset($d['keterangan_tambahan']);

        return $key === null ? $d : data_get($d, $key, $default);
    }
}
