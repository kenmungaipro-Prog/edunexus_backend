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
    Schema::create('vehicles', function (Blueprint $table) {
        $table->id();
        $table->string('registration_number')->unique();
        $table->string('make')->nullable();
        $table->string('model')->nullable();
        $table->unsignedSmallInteger('capacity')->default(40);
        $table->enum('status', ['active', 'maintenance', 'inactive'])->default('active');
        // Live Tracking Data
        $table->decimal('last_lat', 10, 8)->nullable();
        $table->decimal('last_lng', 11, 8)->nullable();
        $table->unsignedSmallInteger('last_speed')->nullable();
        $table->timestamp('location_updated_at')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
