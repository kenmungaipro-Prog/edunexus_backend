<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class CleanupDuplicateUsersSeeder extends Seeder
{
    public function run(): void
    {
        $duplicateEmails = User::select('email')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('email')
            ->having('count', '>', 1)
            ->pluck('email');

        foreach ($duplicateEmails as $email) {
            $users = User::where('email', $email)
                ->orderBy('id')
                ->get();

            $keep = $users->shift();
            $deleteIds = $users->pluck('id')->all();

            if (! empty($deleteIds)) {
                User::whereIn('id', $deleteIds)->delete();
            }
        }
    }
}
