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
        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('class_rooms')->cascadeOnDelete();
            
            // Made nullable for breaks, lunch, or custom events that don't map to a subject/teacher
            $table->foreignId('subject_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained()->cascadeOnDelete();
            
            $table->string('slot_type')->default('class'); // class, break, lunch, event
            $table->string('title')->nullable();           // e.g., "Recess", "Lunch Break", "Assembly"
            
            $table->unsignedTinyInteger('day_of_week');    // 1=Mon … 6=Sat
            $table->unsignedTinyInteger('period_number');  // 1–8
            $table->time('start_time');
            $table->time('end_time');
            $table->string('room')->nullable();
            
            $table->timestamps();
            
            $table->unique(['class_id', 'day_of_week', 'period_number']);
            
            // Note: MySQL allows multiple NULL values in a unique index, 
            // so non-class slots (breaks/events with null teacher_id) won't trigger false duplicate errors.
            $table->unique(['teacher_id', 'day_of_week', 'period_number']); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timetable_slots');
    }
};