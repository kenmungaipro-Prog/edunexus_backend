<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0);
        });

        $accounts = DB::table('chart_of_accounts')
            ->orderBy('school_id')
            ->orderBy('account_type')
            ->orderBy('parent_account_id')
            ->orderBy('account_code')
            ->orderBy('id')
            ->get(['id', 'school_id', 'account_type', 'parent_account_id']);

        $groupOrders = [];
        foreach ($accounts as $account) {
            $group = $account->school_id . ':' . $account->account_type . ':' . ($account->parent_account_id ?? 'root');
            $order = $groupOrders[$group] ?? 0;
            DB::table('chart_of_accounts')
                ->where('id', $account->id)
                ->update(['sort_order' => $order]);
            $groupOrders[$group] = $order + 1;
        }
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
