<?php

// ============================================================
// app/Providers/AppServiceProvider.php
// ============================================================
namespace App\Providers;

use App\Models\Student;
use App\Observers\StudentObserver;
use App\Policies\StudentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Observers
        Student::observe(StudentObserver::class);

        // Policies (auto-discovered, but explicit for clarity)
        Gate::policy(Student::class, StudentPolicy::class);

        // Sanctum token expiry (using default model since App\Models\PersonalAccessToken does not exist)
        // \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(
        //     \App\Models\PersonalAccessToken::class
        // );
    }
}
