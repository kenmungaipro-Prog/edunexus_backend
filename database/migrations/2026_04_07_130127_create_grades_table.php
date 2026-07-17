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
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('class_rooms'); // <--- ADD THIS
            $table->foreignId('entered_by')->constrained('users');
            $table->decimal('marks_obtained', 6, 2);
            $table->unsignedSmallInteger('total_marks');
            $table->decimal('percentage', 5, 2);
            $table->string('letter_grade', 3);
            $table->enum('status', ['pass', 'fail']);
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['exam_id', 'student_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
