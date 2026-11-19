<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed de la aplicacion (ver TaapSeeder: admin + providers de ejemplo).
     */
    public function run(): void
    {
        $this->call(TaapSeeder::class);
    }
}
