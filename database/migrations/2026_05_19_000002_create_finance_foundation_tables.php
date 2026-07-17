<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 40);
            $table->text('description')->nullable();
            $table->decimal('default_amount', 15, 2)->default(0);
            $table->unsignedBigInteger('revenue_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->index(['school_id', 'is_active']);
        });

        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('class_rooms')->nullOnDelete();
            $table->string('name');
            $table->string('billing_period', 40)->default('term');
            $table->char('currency', 3)->default('KES');
            $table->string('status', 30)->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'session_id', 'class_id']);
            $table->index(['school_id', 'status']);
        });

        Schema::create('fee_structure_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_structure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_category_id')->constrained()->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('amount', 15, 2);
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_recurring')->default(true);
            $table->unsignedBigInteger('revenue_account_id')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('class_rooms')->nullOnDelete();
            $table->string('invoice_number');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('KES');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('waiver_total', 15, 2)->default(0);
            $table->decimal('penalty_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('status', 30)->default('draft');
            $table->boolean('posted_to_ledger')->default(false);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'invoice_number']);
            $table->index(['school_id', 'student_id']);
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'due_date']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->unsignedBigInteger('revenue_account_id')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('payment_number');
            $table->string('payment_method', 30);
            $table->string('payment_channel', 40)->nullable();
            $table->char('currency', 3)->default('KES');
            $table->decimal('amount', 15, 2);
            $table->dateTime('payment_date');
            $table->string('reference_number')->nullable();
            $table->string('external_transaction_id')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_phone')->nullable();
            $table->string('status', 30)->default('successful');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reconciled_at')->nullable();
            $table->boolean('posted_to_ledger')->default(false);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'payment_number']);
            $table->index(['school_id', 'student_id']);
            $table->index(['school_id', 'reference_number']);
            $table->index(['school_id', 'external_transaction_id']);
            $table->index(['school_id', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount_allocated', 15, 2);
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('allocated_at');
            $table->timestamps();

            $table->index(['payment_id', 'invoice_id']);
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number');
            $table->dateTime('receipt_date');
            $table->string('pdf_path')->nullable();
            $table->string('qr_code')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('issued');
            $table->timestamps();

            $table->unique(['school_id', 'receipt_number']);
            $table->index('payment_id');
        });

        Schema::create('student_finance_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3)->default('KES');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('invoiced_total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('credit_total', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            $table->dateTime('last_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'student_id', 'currency'], 'student_finance_balance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_finance_balances');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('fee_structure_items');
        Schema::dropIfExists('fee_structures');
        Schema::dropIfExists('fee_categories');
    }
};
