<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Nama field lama tetap diterima: bila field baru tidak dikirim, isinya diambil dari field lama.
     *
     * @param  array<string,string>  $peta  lama => baru
     */
    protected function aliasField(array $peta): void
    {
        foreach ($peta as $lama => $baru) {
            if (! $this->has($baru) && $this->has($lama)) {
                $this->merge([$baru => $this->input($lama)]);
            }
        }
    }

    /**
     * Form multipart (Swagger, HTML) mengirim boolean sebagai teks "true"/"false"; aturan `boolean` hanya menerima
     * true/false/1/0. Ubah teks itu menjadi boolean sebelum validasi. $kunci boleh bertitik ("jaminan.ktp_asli").
     *
     * @param  list<string>  $kunci
     */
    protected function booleanDariTeks(array $kunci): void
    {
        $data = $this->input();
        foreach ($kunci as $k) {
            $nilai = data_get($data, $k);
            if (is_string($nilai) && ($b = filter_var($nilai, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) !== null) {
                data_set($data, $k, $b);
            }
        }
        $this->merge($data);
    }
}
