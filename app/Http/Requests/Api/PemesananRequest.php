<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class PemesananRequest extends ApiRequest
{
    public function rules(): array
    {
        $kunci = $this->input('jenis_sewa') === 'lepas_kunci';

        return [
            'id_tipe' => ['required', 'integer', Rule::exists('tipe_kendaraan', 'id_tipe')->whereNull('deleted_at')],
            'jenis_sewa' => ['required', 'in:dengan_sopir,lepas_kunci'],
            'tgl_mulai' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:'.now()->addDay()->toDateString(),
                'before_or_equal:'.now()->addDays(config('rental.maks_hari_ke_depan'))->toDateString(),
            ],
            'durasi' => ['required', 'integer', 'min:1', 'max:'.config('rental.maks_durasi')],
            'jam_serah' => [
                'required', 'date_format:H:i',
                Rule::when($kunci, ['after_or_equal:'.config('rental.jam_serah_mulai'), 'before_or_equal:'.config('rental.jam_serah_akhir')]),
            ],
            'metode_ambil' => ['required_if:jenis_sewa,lepas_kunci', 'nullable', 'in:ambil_di_kantor,diantar'],
            'lokasi_jemput' => [
                'required_if:jenis_sewa,dengan_sopir', 'required_if:metode_ambil,diantar', 'nullable', 'string', 'max:255',
            ],
            'tujuan' => ['required_if:jenis_sewa,dengan_sopir', 'nullable', 'string', 'max:1000'],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'transmisi' => ['required_if:jenis_sewa,lepas_kunci', 'nullable', 'in:manual,matic'],
            'setuju_syarat' => ['accepted'],
            'setuju_syarat_jaminan' => ['accepted_if:jenis_sewa,lepas_kunci'],
        ];
    }

    public function messages(): array
    {
        return [
            'jam_serah.after_or_equal' => 'Jam serah lepas kunci antara '.config('rental.jam_serah_mulai').' dan '.config('rental.jam_serah_akhir'),
            'jam_serah.before_or_equal' => 'Jam serah lepas kunci antara '.config('rental.jam_serah_mulai').' dan '.config('rental.jam_serah_akhir'),
            'setuju_syarat.accepted' => 'Syarat dan ketentuan harus disetujui',
            'setuju_syarat_jaminan.accepted_if' => 'Syarat jaminan harus disetujui untuk lepas kunci',
        ];
    }
}
