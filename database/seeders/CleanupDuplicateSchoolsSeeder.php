<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\School;

class CleanupDuplicateSchoolsSeeder extends Seeder
{
    public function run(): void
    {
        $duplicateCodes = School::select('school_code')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('school_code')
            ->having('count', '>', 1)
            ->pluck('school_code');

        foreach ($duplicateCodes as $code) {
            $schools = School::where('school_code', $code)
                ->orderBy('id')
                ->get();

            $keep = $schools->shift();
            $deleteIds = $schools->pluck('id')->all();

            if (! empty($deleteIds)) {
                School::whereIn('id', $deleteIds)->delete();
            }
        }
    }
}
