<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\School;
use Illuminate\Database\Seeder;

class ChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        $schools = School::all();

        foreach ($schools as $school) {
            $this->seedForSchool($school->id);
        }
    }

    private function seedForSchool(int $schoolId): void
    {
        $accounts = [
            // Assets (1000-1999)
            ['code' => '1000', 'name' => 'Current Assets', 'type' => 'asset', 'balance' => 'debit', 'parent' => null],
            ['code' => '1100', 'name' => 'Cash & Bank', 'type' => 'asset', 'balance' => 'debit', 'parent' => '1000', 'is_bank' => true],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'balance' => 'debit', 'parent' => '1000', 'is_control' => true, 'is_system' => true],
            ['code' => '1210', 'name' => 'Student Fee Receivables', 'type' => 'asset', 'balance' => 'debit', 'parent' => '1200', 'is_system' => true],
            
            // Liabilities (2000-2999)
            ['code' => '2000', 'name' => 'Current Liabilities', 'type' => 'liability', 'balance' => 'credit', 'parent' => null],
            ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'balance' => 'credit', 'parent' => '2000', 'is_control' => true],
            ['code' => '2200', 'name' => 'Statutory Payables (PAYE/NSSF/SHIF)', 'type' => 'liability', 'balance' => 'credit', 'parent' => '2000'],

            // Equity (3000-3999)
            ['code' => '3000', 'name' => 'Capital & Reserves', 'type' => 'equity', 'balance' => 'credit', 'parent' => null],
            ['code' => '3100', 'name' => 'Retained Earnings', 'type' => 'equity', 'balance' => 'credit', 'parent' => '3000', 'is_system' => true],

            // Revenue (4000-4999)
            ['code' => '4000', 'name' => 'Fee Revenue', 'type' => 'revenue', 'balance' => 'credit', 'parent' => null],
            ['code' => '4100', 'name' => 'Tuition Fees', 'type' => 'revenue', 'balance' => 'credit', 'parent' => '4000'],
            ['code' => '4200', 'name' => 'Transport Fees', 'type' => 'revenue', 'balance' => 'credit', 'parent' => '4000'],
            ['code' => '4300', 'name' => 'Boarding Fees', 'type' => 'revenue', 'balance' => 'credit', 'parent' => '4000'],
            ['code' => '4900', 'name' => 'Other Income', 'type' => 'revenue', 'balance' => 'credit', 'parent' => null],

            // Expenses (5000-5999)
            ['code' => '5000', 'name' => 'Operating Expenses', 'type' => 'expense', 'balance' => 'debit', 'parent' => null],
            ['code' => '5100', 'name' => 'Personnel Expenses (Salaries)', 'type' => 'expense', 'balance' => 'debit', 'parent' => '5000'],
            ['code' => '5200', 'name' => 'Administrative Expenses', 'type' => 'expense', 'balance' => 'debit', 'parent' => '5000'],
            ['code' => '5300', 'name' => 'Repairs & Maintenance', 'type' => 'expense', 'balance' => 'debit', 'parent' => '5000'],
            ['code' => '5400', 'name' => 'Utilities (Water/Electricity)', 'type' => 'expense', 'balance' => 'debit', 'parent' => '5000'],
        ];

        $createdAccounts = [];

        foreach ($accounts as $acc) {
            $parentId = null;
            if ($acc['parent'] && isset($createdAccounts[$acc['parent']])) {
                $parentId = $createdAccounts[$acc['parent']];
            }

            $account = ChartOfAccount::updateOrCreate(
                ['school_id' => $schoolId, 'account_code' => $acc['code']],
                [
                    'parent_account_id' => $parentId,
                    'account_name' => $acc['name'],
                    'account_type' => $acc['type'],
                    'normal_balance' => $acc['balance'],
                    'is_control_account' => $acc['is_control'] ?? false,
                    'is_bank_account' => $acc['is_bank'] ?? false,
                    'is_system' => $acc['is_system'] ?? false,
                    'is_active' => true,
                ]
            );

            $createdAccounts[$acc['code']] = $account->id;
        }
    }
}