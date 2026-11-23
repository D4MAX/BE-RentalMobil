<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([AdminSeeder::class, PengaturanSeeder::class]);

        // Data demo dipisah supaya tidak jalan di server asli
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
