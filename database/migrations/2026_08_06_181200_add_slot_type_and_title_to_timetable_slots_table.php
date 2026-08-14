<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('timetable_slots', function (Blueprint $table) {
            if (! Schema::hasColumn('timetable_slots', 'slot_type')) {
                $table->string('slot_type')->default('class')->after('teacher_id');
            }

            if (! Schema::hasColumn('timetable_slots', 'title')) {
                $table->string('title')->nullable()->after('slot_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timetable_slots', function (Blueprint $table) {
            if (Schema::hasColumn('timetable_slots', 'title')) {
                $table->dropColumn('title');
            }

            if (Schema::hasColumn('timetable_slots', 'slot_type')) {
                $table->dropColumn('slot_type');
            }
        });
    }
};
