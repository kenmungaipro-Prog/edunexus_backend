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
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('class_rooms')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('invigilator_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->string('title');
            $table->date('exam_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('total_marks');
            $table->unsignedSmallInteger('passing_marks');
            $table->string('room')->nullable();
            $table->text('instructions')->nullable();
            $table->enum('status', ['scheduled', 'ongoing', 'completed', 'cancelled'])->default('scheduled');
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
