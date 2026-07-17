# Finance Module Overview

This module manages school finance operations, including invoices, payments, allocations, receipts, and student balances.

## Key components

- `app/Models/Finance/Invoice.php`
- `app/Models/Finance/Payment.php`
- `app/Models/Finance/Receipt.php`
- `app/Models/Finance/StudentFinanceBalance.php`
- `app/Services/Finance/InvoiceGenerationService.php`
- `app/Services/Finance/PaymentAllocationService.php`
- `app/Services/Finance/StudentBalanceService.php`
- `app/Services/Finance/ReceiptService.php`
- `app/Models/Finance/FinanceStatuses.php`

## Improvements applied

- Centralized status constants in `FinanceStatuses` to reduce repeated magic strings and improve maintainability.
- `StudentBalanceService` now uses shared invoice/payment status sets when calculating student balances.
- `PaymentAllocationService` uses shared payable invoice statuses when allocating payments to invoices.
- `FinanceDashboardController` now returns overdue and due-soon balances in addition to totals and collection rate.
- `StudentFinanceController` summary now returns per-student overdue and due-soon balances.
- `InvoiceController` and `PaymentController` now use shared finance statuses when updating or validating state.
- Finance operations now write audit records for invoice creation, issuance, cancellation, generation, payment collection, allocation, and reversal.
- Added a dedicated payment allocation results API to inspect allocations for a payment without mutating state.

## API behavior notes

- `finance/dashboard/summary` now includes:
  - `total_invoiced`
  - `total_collected`
  - `outstanding_balance`
  - `today_collections`
  - `overdue_balance`
  - `due_soon_balance`
  - `collection_rate`

- `students/{student}/finance-summary` now returns:
  - `student`
  - `balance`
  - `overdue_balance`
  - `due_soon_balance`

## What you can add next

- explicit invoice allocation API by invoice ID
- refund and settlement flows for payments
- ledger posting and accounting journal integration
- event-driven audit logs for invoice/payment lifecycle changes
