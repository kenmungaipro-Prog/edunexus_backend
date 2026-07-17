<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FeeType;

class FeeTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Tuition Fee',     'amount' => 25000.00, 'frequency' => 'annual'],
            ['name' => 'Transport Fee',   'amount' => 1200.00,  'frequency' => 'monthly'],
            ['name' => 'Examination Fee', 'amount' => 500.00,   'frequency' => 'term'],
            ['name' => 'Library Fee',     'amount' => 200.00,   'frequency' => 'annual'],
        ];

        foreach ($types as $type) {
            FeeType::updateOrCreate(
                ['name' => $type['name']],
                $type
            );
        }
    }
}
