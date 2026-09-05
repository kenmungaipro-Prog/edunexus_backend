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
        Schema::table('teachers', function (Blueprint $table) {
            $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('phone');
            $table->date('dob')->nullable()->after('gender');
            $table->string('nationality')->nullable()->after('dob');
            $table->string('employment_type')->nullable()->after('experience_yrs');
            $table->text('bio')->nullable()->after('salary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn(['gender', 'dob', 'nationality', 'employment_type', 'bio']);
        });
    }
};
