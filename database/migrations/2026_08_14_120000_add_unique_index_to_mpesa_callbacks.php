<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddUniqueIndexToMpesaCallbacks extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mpesa_callbacks', function (Blueprint $table) {
            $table->unique(['callback_type', 'mpesa_receipt_number'], 'mpesa_callbacks_callback_type_receipt_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mpesa_callbacks', function (Blueprint $table) {
            $table->dropUnique('mpesa_callbacks_callback_type_receipt_unique');
        });
    }
}
