<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('secondary_parent_id')->nullable()->constrained('users')->nullOnDelete()->after('parent_id');
            $table->foreignId('emergency_contact_parent_id')->nullable()->constrained('users')->nullOnDelete()->after('secondary_parent_id');
        });

        Schema::table('parent_profiles', function (Blueprint $table) {
            $table->string('whatsapp_phone')->nullable()->after('phone');
            $table->boolean('preferred_whatsapp')->default(false)->after('whatsapp_phone');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_parent_id');
            $table->dropConstrainedForeignId('emergency_contact_parent_id');
        });

        Schema::table('parent_profiles', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_phone', 'preferred_whatsapp']);
        });
    }
};
