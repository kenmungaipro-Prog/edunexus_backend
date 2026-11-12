<?php

use App\Models\AcademicSession;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->school = School::factory()->create();
    AcademicSession::factory()->create([
        'school_id' => $this->school->id,
        'is_current' => true,
    ]);
    $this->admin = User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);
});

function chartAccountPayload(array $overrides = []): array
{
    return array_merge([
        'account_code' => '1000',
        'account_name' => 'Cash',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'currency' => 'KES',
        'is_control_account' => false,
        'is_bank_account' => false,
        'is_active' => true,
    ], $overrides);
}

test('admin can create a sub-account under a main account of the same type', function () {
    $mainAccount = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
    ]));

    $this->actingAs($this->admin)
        ->postJson('/api/v1/accounting/accounts', chartAccountPayload([
            'account_code' => '1010',
            'account_name' => 'Petty Cash',
            'parent_account_id' => $mainAccount->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.parent_account_id', $mainAccount->id)
        ->assertJsonPath('data.parent.account_name', 'Cash');
});

test('sub-account parent must be in the same school and have the same account type', function () {
    $otherSchool = School::factory()->create();
    $foreignParent = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $otherSchool->id,
    ]));
    $liabilityParent = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '2000',
        'account_name' => 'Accounts Payable',
        'account_type' => 'liability',
        'normal_balance' => 'credit',
    ]));

    foreach ([$foreignParent, $liabilityParent] as $index => $parent) {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/accounts', chartAccountPayload([
                'account_code' => '10' . $index . '0',
                'parent_account_id' => $parent->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_account_id']);
    }
});

test('accounts can be nested three levels deep but not four', function () {
    $mainAccount = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
    ]));
    $subAccount = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '1010',
        'account_name' => 'Petty Cash',
        'parent_account_id' => $mainAccount->id,
    ]));

    $subSubAccountResponse = $this->actingAs($this->admin)
        ->postJson('/api/v1/accounting/accounts', chartAccountPayload([
            'account_code' => '1011',
            'account_name' => 'Cash Box',
            'parent_account_id' => $subAccount->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.parent_account_id', $subAccount->id);

    $subSubAccountId = $subSubAccountResponse->json('data.id');

    $this->actingAs($this->admin)
        ->postJson('/api/v1/accounting/accounts', chartAccountPayload([
            'account_code' => '1012',
            'account_name' => 'Cash Box Float',
            'parent_account_id' => $subSubAccountId,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_account_id']);
});

test('moving an account cannot make its descendants exceed three levels', function () {
    $mainAccount = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
    ]));
    $subAccount = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '1010',
        'parent_account_id' => $mainAccount->id,
    ]));
    $subSubAccount = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '1011',
        'parent_account_id' => $subAccount->id,
    ]));
    $otherMainAccount = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '1100',
        'account_name' => 'Bank',
    ]));
    $otherSubAccount = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '1110',
        'parent_account_id' => $otherMainAccount->id,
    ]));

    $this->actingAs($this->admin)
        ->putJson("/api/v1/accounting/accounts/{$mainAccount->id}", [
            'parent_account_id' => $otherSubAccount->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_account_id']);

    expect($subSubAccount->parent_account_id)->toBe($subAccount->id);
});

test('accounts cannot be moved under one of their descendants', function () {
    $parent = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
    ]));
    $child = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '1010',
        'parent_account_id' => $parent->id,
    ]));

    $this->actingAs($this->admin)
        ->putJson("/api/v1/accounting/accounts/{$parent->id}", [
            'parent_account_id' => $child->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_account_id']);
});

test('accounts can be reordered among siblings and the order persists', function () {
    $first = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '1000',
        'sort_order' => 0,
    ]));
    $second = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '1100',
        'account_name' => 'Bank',
        'sort_order' => 1,
    ]));
    $third = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '1200',
        'account_name' => 'Receivables',
        'sort_order' => 2,
    ]));

    $this->actingAs($this->admin)
        ->postJson('/api/v1/accounting/accounts/reorder', [
            'parent_account_id' => null,
            'account_type' => 'asset',
            'account_ids' => [$third->id, $first->id, $second->id],
        ])
        ->assertOk();

    expect(ChartOfAccount::orderBy('sort_order')->pluck('id')->all())
        ->toBe([$third->id, $first->id, $second->id]);
});

test('account reorder requires all and only the account siblings', function () {
    $first = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '1000',
    ]));
    $second = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '1100',
    ]));

    $this->actingAs($this->admin)
        ->postJson('/api/v1/accounting/accounts/reorder', [
            'parent_account_id' => null,
            'account_type' => 'asset',
            'account_ids' => [$first->id],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The account order is out of date. Refresh the chart and try again.');
});

test('financial reports roll sub-account balances into parents without double counting totals', function () {
    $asset = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '1000',
        'account_name' => 'Cash',
        'account_type' => 'asset',
        'is_bank_account' => true,
    ]));
    $pettyCash = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'parent_account_id' => $asset->id,
        'account_code' => '1010',
        'account_name' => 'Petty Cash',
        'account_type' => 'asset',
        'is_bank_account' => true,
    ]));
    $revenue = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '4000',
        'account_name' => 'Tuition Revenue',
        'account_type' => 'revenue',
        'normal_balance' => 'credit',
    ]));
    $tuitionFees = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'parent_account_id' => $revenue->id,
        'account_code' => '4010',
        'account_name' => 'Tuition Fees',
        'account_type' => 'revenue',
        'normal_balance' => 'credit',
    ]));
    $expense = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'account_code' => '5000',
        'account_name' => 'Operating Expenses',
        'account_type' => 'expense',
    ]));
    $supplies = ChartOfAccount::create(chartAccountPayload([
        'school_id' => $this->school->id,
        'parent_account_id' => $expense->id,
        'account_code' => '5010',
        'account_name' => 'School Supplies',
        'account_type' => 'expense',
    ]));

    $entry = JournalEntry::create([
        'school_id' => $this->school->id,
        'entry_date' => '2026-06-01',
        'description' => 'Report roll-up test',
        'status' => 'posted',
        'created_by' => $this->admin->id,
    ]);

    foreach ([
        [$asset, 100, 0],
        [$pettyCash, 25, 0],
        [$revenue, 0, 100],
        [$tuitionFees, 0, 50],
        [$expense, 20, 0],
        [$supplies, 5, 0],
    ] as [$account, $debit, $credit]) {
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
        ]);
    }

    $trialBalanceResponse = $this->actingAs($this->admin)
        ->getJson('/api/v1/accounting/reports/trial-balance?from_date=2026-01-01&to_date=2026-12-31')
        ->assertOk()
        ->assertJsonPath('data.total_debit', 150)
        ->assertJsonPath('data.total_credit', 150)
        ->assertJsonPath('data.is_balanced', true);
    $trialBalanceAccounts = collect($trialBalanceResponse->json('data.accounts'))->keyBy('id');
    expect((float) $trialBalanceAccounts[$expense->id]['debit'])->toBe(25.0)
        ->and((float) $trialBalanceAccounts[$revenue->id]['credit'])->toBe(150.0)
        ->and($trialBalanceAccounts[$pettyCash->id]['parent_account_id'])->toBe($asset->id);

    $incomeStatementResponse = $this->actingAs($this->admin)
        ->getJson('/api/v1/accounting/reports/income-statement?from_date=2026-01-01&to_date=2026-12-31')
        ->assertOk()
        ->assertJsonPath('data.total_revenue', 150)
        ->assertJsonPath('data.total_expense', 25)
        ->assertJsonPath('data.net_income', 125);
    $incomeStatementRevenues = collect($incomeStatementResponse->json('data.revenues'))->keyBy('id');
    $incomeStatementExpenses = collect($incomeStatementResponse->json('data.expenses'))->keyBy('id');
    expect((float) $incomeStatementRevenues[$revenue->id]['amount'])->toBe(150.0)
        ->and((float) $incomeStatementRevenues[$tuitionFees->id]['amount'])->toBe(50.0)
        ->and((float) $incomeStatementExpenses[$expense->id]['amount'])->toBe(25.0)
        ->and((float) $incomeStatementExpenses[$supplies->id]['amount'])->toBe(5.0)
        ->and($incomeStatementExpenses[$supplies->id]['parent_account_id'])->toBe($expense->id);

    $balanceSheetResponse = $this->actingAs($this->admin)
        ->getJson('/api/v1/accounting/reports/balance-sheet?from_date=2026-01-01&to_date=2026-12-31')
        ->assertOk()
        ->assertJsonPath('data.total_assets', 125)
        ->assertJsonPath('data.net_income', 125);
    $balanceSheetAssets = collect($balanceSheetResponse->json('data.assets'))->keyBy('id');
    expect((float) $balanceSheetAssets[$asset->id]['amount'])->toBe(125.0)
        ->and((float) $balanceSheetAssets[$pettyCash->id]['amount'])->toBe(25.0)
        ->and($balanceSheetAssets[$pettyCash->id]['parent_account_id'])->toBe($asset->id);

    $cashFlowResponse = $this->actingAs($this->admin)
        ->getJson('/api/v1/accounting/reports/cash-flow?from_date=2026-01-01&to_date=2026-12-31')
        ->assertOk();
    $cashFlowAccounts = collect($cashFlowResponse->json('data.cash_accounts'))->keyBy('id');
    expect($cashFlowAccounts[$asset->id]['account_depth'])->toBe(0)
        ->and($cashFlowAccounts[$pettyCash->id]['account_depth'])->toBe(1)
        ->and($cashFlowAccounts[$pettyCash->id]['parent_account_id'])->toBe($asset->id);
});
