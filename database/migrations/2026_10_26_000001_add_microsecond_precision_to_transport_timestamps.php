<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->timestamp('location_updated_at', 6)->nullable()->change();
        });

        Schema::table('vehicle_telemetry', function (Blueprint $table) {
            $table->timestamp('recorded_at', 6)->useCurrent()->change();
        });

        Schema::table('trip_locations', function (Blueprint $table) {
            $table->timestamp('recorded_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->timestamp('location_updated_at')->nullable()->change();
        });

        Schema::table('vehicle_telemetry', function (Blueprint $table) {
            $table->timestamp('recorded_at')->useCurrent()->change();
        });

        Schema::table('trip_locations', function (Blueprint $table) {
            $table->timestamp('recorded_at')->nullable()->change();
        });
    }
};
