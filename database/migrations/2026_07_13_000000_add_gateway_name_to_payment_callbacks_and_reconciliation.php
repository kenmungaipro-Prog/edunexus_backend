<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_callbacks', function (Blueprint $table) {
            if (!Schema::hasColumn('mpesa_callbacks', 'gateway_name')) {
                $table->string('gateway_name', 50)->default('mpesa')->after('callback_type');
            }
        });

        Schema::table('payment_reconciliation_items', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_reconciliation_items', 'gateway_name')) {
                $table->string('gateway_name', 50)->default('mpesa')->after('school_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_reconciliation_items', function (Blueprint $table) {
            if (Schema::hasColumn('payment_reconciliation_items', 'gateway_name')) {
                $table->dropColumn('gateway_name');
            }
        });

        Schema::table('mpesa_callbacks', function (Blueprint $table) {
            if (Schema::hasColumn('mpesa_callbacks', 'gateway_name')) {
                $table->dropColumn('gateway_name');
            }
        });
    }
};
