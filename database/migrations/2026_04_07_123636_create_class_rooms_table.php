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
        Schema::create('class_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions');
            $table->string('name');                            // "Grade 10-A"
            $table->unsignedTinyInteger('grade');      // 1–12
            $table->string('section', 5);             // A, B, C
            $table->foreignId('class_teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->unsignedSmallInteger('capacity')->default(40);
            $table->string('room')->nullable();
            
            // Timetable Settings & Configurations
            $table->unsignedTinyInteger('total_periods')->default(8);
            $table->unsignedSmallInteger('period_duration_minutes')->default(60);
            $table->time('day_start_time')->default('08:00:00');
            $table->unsignedTinyInteger('break_after_period')->nullable(); // e.g., after period 3
            $table->unsignedSmallInteger('break_duration_minutes')->default(15);
            $table->unsignedTinyInteger('lunch_after_period')->nullable(); // e.g., after period 5
            $table->unsignedSmallInteger('lunch_duration_minutes')->default(45);

            $table->timestamps();
            $table->unique(['school_id', 'session_id', 'grade', 'section']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_rooms');
    }
};