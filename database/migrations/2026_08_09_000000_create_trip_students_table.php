<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('transport_trip_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('boarding_stop_id')->nullable();
            $table->dateTime('boarding_time')->nullable();
            $table->unsignedBigInteger('dropoff_stop_id')->nullable();
            $table->dateTime('dropoff_time')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->foreign('transport_trip_id')->references('id')->on('transport_trips')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('boarding_stop_id')->references('id')->on('transport_stops')->nullOnDelete();
            $table->foreign('dropoff_stop_id')->references('id')->on('transport_stops')->nullOnDelete();
            $table->unique(['transport_trip_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_students');
    }
};
