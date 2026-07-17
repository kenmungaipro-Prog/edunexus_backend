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
    Schema::create('transport_routes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('school_id')->constrained()->cascadeOnDelete();
        $table->string('name');
        $table->foreignId('vehicle_id')->constrained('vehicles');
        $table->foreignId('driver_id')->constrained('drivers');
        $table->json('stops'); // Structure: [{name, pickup_time, drop_time}]
        $table->decimal('monthly_fee', 8, 2)->default(0);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transport_routes');
    }
};
