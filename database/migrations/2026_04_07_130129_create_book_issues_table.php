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
        Schema::create('book_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users');
            $table->foreignId('issued_by')->constrained('users');
            $table->timestamp('issued_at');
            $table->date('due_date');
            $table->timestamp('returned_at')->nullable();
            $table->enum('status', ['issued', 'returned', 'lost'])->default('issued');
            $table->decimal('fine_amount', 8, 2)->default(0);
            $table->timestamps();
            $table->index(['member_id', 'status']);
        });
        ;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_issues');
    }
};
