<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fees')) {
            $driver = DB::getDriverName();

            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE fees MODIFY payment_method VARCHAR(30) NOT NULL");
                DB::statement("ALTER TABLE fees MODIFY status VARCHAR(30) NOT NULL DEFAULT 'paid'");
            }

            Schema::table('fees', function (Blueprint $table) {
                if (! Schema::hasColumn('fees', 'reversed_at')) {
                    $table->timestamp('reversed_at')->nullable()->after('paid_at');
                }

                if (! Schema::hasColumn('fees', 'reversed_by')) {
                    $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('fees', 'reversal_reason')) {
                    $table->text('reversal_reason')->nullable()->after('reversed_by');
                }

                if (! Schema::hasColumn('fees', 'deleted_at')) {
                    $table->softDeletes();
                }

                $table->index(['payment_method', 'status'], 'fees_payment_status_index');
            });
        }

        if (Schema::hasTable('fee_types')) {
            Schema::table('fee_types', function (Blueprint $table) {
                if (! Schema::hasColumn('fee_types', 'school_id')) {
                    $table->foreignId('school_id')->nullable()->after('id')->constrained()->nullOnDelete();
                }

                if (! Schema::hasColumn('fee_types', 'description')) {
                    $table->text('description')->nullable()->after('frequency');
                }
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action', 50);
                $table->string('auditable_type');
                $table->unsignedBigInteger('auditable_id');
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'action']);
                $table->index(['auditable_type', 'auditable_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_logs')) {
            Schema::dropIfExists('audit_logs');
        }

        if (Schema::hasTable('fees')) {
            Schema::table('fees', function (Blueprint $table) {
                if (Schema::hasColumn('fees', 'reversed_by')) {
                    $table->dropConstrainedForeignId('reversed_by');
                }

                foreach (['reversed_at', 'reversal_reason', 'deleted_at'] as $column) {
                    if (Schema::hasColumn('fees', $column)) {
                        $table->dropColumn($column);
                    }
                }

                $table->dropIndex('fees_payment_status_index');
            });
        }

        if (Schema::hasTable('fee_types')) {
            Schema::table('fee_types', function (Blueprint $table) {
                if (Schema::hasColumn('fee_types', 'school_id')) {
                    $table->dropConstrainedForeignId('school_id');
                }

                if (Schema::hasColumn('fee_types', 'description')) {
                    $table->dropColumn('description');
                }
            });
        }
    }
};
