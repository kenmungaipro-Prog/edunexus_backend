<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->timestamp('scheduled_start')->nullable()->after('direction');
            $table->timestamp('actual_start')->nullable()->after('scheduled_start');
            $table->timestamp('actual_end')->nullable()->after('end_time');
        });
    }

    public function down(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->dropColumn(['scheduled_start', 'actual_start', 'actual_end']);
        });
    }
};
