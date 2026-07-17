<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ClassRoom;

class CleanupDuplicateClassRoomsSeeder extends Seeder
{
    public function run(): void
    {
        $duplicateKeys = ClassRoom::select('school_id', 'session_id', 'grade', 'section')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('school_id', 'session_id', 'grade', 'section')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateKeys as $duplicate) {
            $rooms = ClassRoom::where('school_id', $duplicate->school_id)
                ->where('session_id', $duplicate->session_id)
                ->where('grade', $duplicate->grade)
                ->where('section', $duplicate->section)
                ->orderBy('id')
                ->get();

            $rooms->shift();
            $deleteIds = $rooms->pluck('id')->all();

            if (! empty($deleteIds)) {
                ClassRoom::whereIn('id', $deleteIds)->delete();
            }
        }
    }
}
