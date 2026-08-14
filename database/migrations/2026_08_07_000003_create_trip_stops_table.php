<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_trip_id')->constrained('transport_trips')->cascadeOnDelete();
            $table->foreignId('transport_stop_id')->constrained('transport_stops')->cascadeOnDelete();
            $table->integer('sequence')->default(0);
            $table->string('name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('pickup_time')->nullable();
            $table->string('dropoff_time')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique(['transport_trip_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_stops');
    }
};
