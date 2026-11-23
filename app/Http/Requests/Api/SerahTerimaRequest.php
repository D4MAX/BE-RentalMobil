<?php

namespace App\Http\Requests\Api;

use App\Exceptions\ApiException;
use App\Models\Pemesanan;
use Illuminate\Validation\Rule;

/**
 * POST /admin/pemesanan/{id}/serah-terima (Spek API bagian 9). Hanya multipart/form-data. Jaminan hanya untuk
 * lepas_kunci: tiap item jaminan fisik wajib berupa foto (jpg atau png, maks 2 MB) di foto_jaminan[item].
 */
class SerahTerimaRequest extends ApiRequest
{
    public const BBM = ['penuh', 'tiga_perempat', 'setengah', 'seperempat', 'hampir_kosong'];

    /** Nama item di foto_jaminan[...] dan sebutannya di pesan galat. */
    public const ITEM_FOTO = [
        'ktp_asli' => 'KTP asli',
        'sim_c_asli' => 'SIM C asli',
        'dokumen_tambahan' => 'dokumen tambahan',
        'motor_stnk' => 'motor dan STNK',
        'booking_transportasi' => 'bukti booking transportasi',
        'booking_hotel' => 'bukti booking hotel',
    ];

    protected function prepareForValidation(): void
    {
        if (! str_starts_with(strtolower((string) $this->header('Content-Type')), 'multipart/form-data')) {
            throw new ApiException('Endpoint ini hanya menerima multipart/form-data', 'FORMAT_TIDAK_DIDUKUNG', 415);
        }
    }

    public function rules(): array
    {
        $pesanan = Pemesanan::query()->find($this->route('id')); // tidak ada: 404 diberikan service
        $lepasKunci = $pesanan?->jenis_sewa === 'lepas_kunci';
        // KTP asli bisa berbeda dengan domisili pesanan; jaminan mengikuti domisili yang berlaku
        $domisili = $this->input('domisili_disesuaikan') ?: $pesanan?->domisili;

        $aturan = [
            'id_kendaraan' => ['required', 'integer', Rule::exists('kendaraan', 'id_kendaraan')->whereNull('deleted_at')],
            'catatan_kondisi' => ['nullable', 'string', 'max:1000'],
            'foto_kondisi' => ['nullable', 'array', 'max:10'],
            'foto_kondisi.*' => ['image', 'max:5120'],
            'domisili_disesuaikan' => ['nullable', 'in:malang_raya,luar_malang_raya'],
            'odometer_awal' => [$lepasKunci ? 'required' : 'nullable', 'integer', 'min:0'],
            'bbm_awal' => [$lepasKunci ? 'required' : 'nullable', 'in:'.implode(',', self::BBM)],
            'no_identitas' => [$lepasKunci ? 'required' : 'nullable', 'string', 'max:30'],
        ];

        if ($lepasKunci) {
            $foto = ['required', 'file', 'mimes:jpg,jpeg,png', 'max:2048'];
            $aturan += [
                'jaminan.dokumen_tambahan' => ['required', 'in:ktm,kk,npwp,bpjs,paspor'],
                'foto_jaminan.ktp_asli' => $foto,
                'foto_jaminan.sim_c_asli' => $foto,
                'foto_jaminan.dokumen_tambahan' => $foto,
            ];
            if ($domisili === 'malang_raya') {
                $aturan['foto_jaminan.motor_stnk'] = $foto;
            } else {
                $aturan['foto_jaminan.booking_transportasi'] = $foto;
                $aturan['foto_jaminan.booking_hotel'] = $foto;
            }
        }

        return $aturan;
    }

    public function messages(): array
    {
        $pesan = ['jaminan.dokumen_tambahan.required' => 'Jenis dokumen tambahan wajib dipilih'];
        foreach (self::ITEM_FOTO as $kunci => $nama) {
            $pesan["foto_jaminan.{$kunci}.required"] = "Foto {$nama} wajib diunggah";
            $pesan["foto_jaminan.{$kunci}.file"] = "Foto {$nama} harus berupa berkas";
            $pesan["foto_jaminan.{$kunci}.uploaded"] = "Foto {$nama} gagal diunggah (maksimal 2 MB)";
            $pesan["foto_jaminan.{$kunci}.mimes"] = "Foto {$nama} harus berformat jpg atau png";
            $pesan["foto_jaminan.{$kunci}.max"] = "Foto {$nama} maksimal 2 MB";
        }

        return $pesan;
    }
}
