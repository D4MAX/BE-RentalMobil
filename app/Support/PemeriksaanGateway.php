<?php

namespace App\Support;

use RuntimeException;

/**
 * Pengaman boot untuk produksi. Gateway palsu menyetujui "pembayaran" tanpa uang; APP_DEBUG=true menampilkan jejak kode di
 * halaman galat; MAIL_MAILER=log menulis token reset dan verifikasi ke laravel.log. Tidak satu pun boleh hidup di produksi.
 */
final class PemeriksaanGateway
{
    /** Perintah artisan yang menjalankan aplikasi sungguhan (menerima uang atau memproses tugas); lainnya dibiarkan. */
    public const PERINTAH_DIPERIKSA = ['schedule:run', 'queue:work'];

    /**
     * Dipakai AppServiceProvider. Diperiksa untuk permintaan HTTP, schedule:run, dan queue:work saja; perintah artisan
     * lain (package:discover, migrate, config:cache, dan seterusnya) tidak boleh terhalang, supaya deploy tidak macet.
     *
     * @param  list<string>  $argv  argumen proses (argv[1] = nama perintah artisan)
     */
    public static function periksaUntukKonteks(string $lingkungan, ?string $gateway, bool $console, array $argv, bool $debug = false, ?string $mailer = null): void
    {
        if (self::perluDiperiksa($console, $argv)) {
            self::pastikanAman($lingkungan, $gateway, $debug, $mailer);
        }
    }

    /** @param  list<string>  $argv */
    public static function perluDiperiksa(bool $console, array $argv): bool
    {
        return ! $console || in_array($argv[1] ?? null, self::PERINTAH_DIPERIKSA, true);
    }

    /** Semua pelanggaran dilaporkan sekaligus dalam satu pesan, supaya tidak perlu memperbaiki satu per satu. */
    public static function pastikanAman(string $lingkungan, ?string $gateway, bool $debug = false, ?string $mailer = null): void
    {
        if ($lingkungan !== 'production') {
            return;
        }

        $masalah = [];
        if (($gateway ?? 'palsu') !== 'midtrans') {
            $masalah[] = 'PEMBAYARAN_GATEWAY bukan "midtrans" (nilai sekarang: "'.($gateway ?? 'palsu').'"; kosong dianggap palsu). '
                .'Isi PEMBAYARAN_GATEWAY=midtrans dan MIDTRANS_SERVER_KEY di .env produksi.';
        }
        if ($debug) {
            $masalah[] = 'APP_DEBUG=true menampilkan jejak kode di halaman galat. Isi APP_DEBUG=false di .env produksi.';
        }
        if ($mailer === 'log') {
            $masalah[] = 'MAIL_MAILER=log menulis token reset dan verifikasi ke laravel.log, bukan mengirim email. '
                .'Isi MAIL_MAILER=smtp beserta MAIL_HOST, MAIL_PORT, MAIL_USERNAME, dan MAIL_PASSWORD di .env produksi.';
        }

        if ($masalah) {
            throw new RuntimeException('Aplikasi menolak berjalan: APP_ENV=production tetapi '.implode(' | ', $masalah));
        }
    }
}
