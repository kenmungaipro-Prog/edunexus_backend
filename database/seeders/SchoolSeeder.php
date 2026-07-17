<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\School;


class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        School::updateOrCreate(
            ['school_code' => 'GIS001'],
            [
                'name'             => 'Greenwood International School',
                'email'            => 'admin@greenwood.edu.in',
                'phone'            => '+91 80 2345 6789',
                'address'          => '42, 5th Cross, Koramangala, Bengaluru - 560034',
                'principal'        => 'Dr. Ananya Krishnan',
                'established_year' => 1998,
                'board'            => 'CBSE',
                'affiliation_no'   => '830456',
            ]
        );
    }
}

