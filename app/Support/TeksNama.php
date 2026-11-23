<?php

namespace App\Support;

/** Perapian teks nama (tipe, merk) yang sederhana dan dapat diprediksi. */
class TeksNama
{
    /**
     * Rapikan: trim, spasi ganda menjadi satu, lalu huruf kapital di awal tiap kata dan sisanya huruf kecil.
     * Singkatan tidak dirusak dengan satu aturan: bagian kata (dipisah spasi atau tanda hubung) yang SELURUH hurufnya
     * kapital dan panjangnya paling banyak 3 huruf dibiarkan apa adanya (BMW, MG, CR-V, HR-V, RS). Kata kapital semua yang
     * lebih panjang (TOYOTA, BRIO) menjadi huruf judul. Konsekuensinya, nama yang aslinya bermerek campuran seperti
     * "McLaren" menjadi "Mclaren".
     */
    public static function judul(string $teks): string
    {
        $teks = trim((string) preg_replace('/\s+/u', ' ', $teks));

        $kata = array_map(function (string $kata) {
            return implode('-', array_map(function (string $bagian) {
                $huruf = (string) preg_replace('/[^\p{L}]/u', '', $bagian);
                if ($huruf === '' || (mb_strtoupper($huruf) === $huruf && mb_strlen($huruf) <= 3)) {
                    return $bagian; // angka saja atau singkatan pendek
                }

                return mb_strtoupper(mb_substr($bagian, 0, 1)).mb_strtolower(mb_substr($bagian, 1));
            }, explode('-', $kata)));
        }, explode(' ', $teks));

        return implode(' ', $kata);
    }
}
