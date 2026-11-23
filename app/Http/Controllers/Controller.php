<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Form (Swagger, HTML) mengirim boolean sebagai teks "true" atau "false"; aturan `boolean` hanya mengenal true, false, 1, 0.
     * Ubah teks itu menjadi boolean sebelum validasi. Nilai lain dibiarkan supaya validasi tetap menolaknya.
     *
     * @param  list<string>  $kunci
     */
    protected function booleanDariTeks(Request $request, array $kunci): void
    {
        foreach ($kunci as $k) {
            $nilai = $request->input($k);
            if (is_string($nilai) && ($b = filter_var($nilai, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) !== null) {
                $request->merge([$k => $b]);
            }
        }
    }
}
