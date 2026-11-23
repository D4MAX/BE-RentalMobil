<?php

use Illuminate\Support\Facades\Schedule;

// Pesanan menunggu pembayaran yang lewat batas 1 jam (cadangan bila webhook expire terlambat)
Schedule::command('pemesanan:batalkan-kedaluwarsa')->everyMinute()->withoutOverlapping();

// Dikonfirmasi yang lewat tanggal kembali tanpa serah terima ditutup (tidak_diambil, tanpa refund). Tidak mendesak: idempoten.
Schedule::command('pemesanan:tutup-tidak-diambil')->dailyAt('00:10')->withoutOverlapping();

// Tempat sampah tipe dan unit: yang lebih lama dari rental.hari_sampah dibuang permanen (foto ikut terhapus). Idempoten.
Schedule::command('sampah:bersihkan')->dailyAt('02:00')->withoutOverlapping();

// Akun user yang tidak diverifikasi lebih dari 30 hari dihapus (PRD). Idempoten.
Schedule::command('akun:bersihkan-belum-verifikasi')->dailyAt('02:30')->withoutOverlapping();

// Refund Midtrans yang tertinggal karena proses mati sesudah commit diulang (refund_key tetap per order, aman diulang).
Schedule::command('refund:ulangi-tertunda')->everyTenMinutes()->withoutOverlapping();
