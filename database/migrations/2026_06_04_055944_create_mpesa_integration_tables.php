<?php

// Path: database/migrations/2026_06_04_000001_create_mpesa_integration_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Gateway Configurations (Allows multi-tenant Daraja credentials)
        if (!Schema::hasTable('payment_gateway_configs')) {
        Schema::create('payment_gateway_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('gateway_name', 50)->default('mpesa');
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');
            $table->string('shortcode', 20)->nullable();
            $table->string('passkey')->nullable();
            $table->string('consumer_key')->nullable();
            $table->string('consumer_secret')->nullable();
            $table->string('initiator_name')->nullable();
            $table->string('initiator_password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'gateway_name']);
        });
        }

        // 2. M-Pesa Raw Callbacks (Immutable log for audit and idempotency)
        if (!Schema::hasTable('mpesa_callbacks')) {
        Schema::create('mpesa_callbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('callback_type', 30); // e.g., 'C2B_VALIDATION', 'C2B_CONFIRMATION', 'STK_RESULT'
            $table->string('merchant_request_id')->nullable();
            $table->string('checkout_request_id')->nullable();
            $table->string('mpesa_receipt_number', 50)->nullable();
            $table->string('result_code', 10)->nullable();
            $table->string('result_desc')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->string('account_reference')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->json('raw_payload');
            $table->boolean('is_processed')->default(false);
            $table->text('processing_notes')->nullable();
            $table->timestamps();

            $table->index('checkout_request_id');
            $table->index('mpesa_receipt_number');
            $table->index(['school_id', 'is_processed']);
        });
        }

        // 3. Normalized Gateway Transactions (Tracks active requests like STK pushes)
        if (!Schema::hasTable('payment_gateway_transactions')) {
        Schema::create('payment_gateway_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway_name', 50)->default('mpesa');
            $table->string('transaction_type', 30); // e.g., 'STK_PUSH'
            $table->string('merchant_request_id')->nullable();
            $table->string('checkout_request_id')->nullable();
            $table->string('account_reference')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('phone_number', 20);
            $table->string('status', 30)->default('pending'); // pending, successful, failed, timeout
            $table->text('gateway_response')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'merchant_request_id'], 'pgt_school_merchant_unique');
            $table->unique(['school_id', 'checkout_request_id'], 'pgt_school_checkout_unique');
        });
        }

        // 4. Reconciliation Items (For unmatched C2B payments that go to Suspense)
        if (!Schema::hasTable('payment_reconciliation_items')) {
        Schema::create('payment_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mpesa_callback_id')->constrained('mpesa_callbacks')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('mpesa_receipt_number', 50);
            $table->string('account_reference')->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->string('status', 30)->default('unmatched'); // unmatched, resolved, refunded
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete(); // Linked once resolved
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'mpesa_receipt_number'], 'pri_school_mpesa_unique');
            $table->index(['school_id', 'status']);
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_items');
        Schema::dropIfExists('payment_gateway_transactions');
        Schema::dropIfExists('mpesa_callbacks');
        Schema::dropIfExists('payment_gateway_configs');
    }
};