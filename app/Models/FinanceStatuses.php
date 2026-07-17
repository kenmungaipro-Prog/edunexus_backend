<?php

namespace App\Models;

/**
 * FinanceStatuses
 *
 * Centralized finance status constants and helper methods
 */
class FinanceStatuses
{
    // Invoice statuses
    public const INVOICE_DRAFT = 'draft';
    public const INVOICE_ISSUED = 'issued';
    public const INVOICE_PARTIALLY_PAID = 'partially_paid';
    public const INVOICE_PAID = 'paid';
    public const INVOICE_OVERDUE = 'overdue';
    public const INVOICE_CANCELLED = 'cancelled';
    public const INVOICE_CREDIT_NOTE = 'credit_note';

    // Payment statuses
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_SUCCESSFUL = 'successful';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REVERSED = 'reversed';
    public const PAYMENT_FULLY_ALLOCATED = 'fully_allocated';
    public const PAYMENT_PARTIALLY_ALLOCATED = 'partially_allocated';
    public const PAYMENT_REFUNDED = 'refunded';

    // Receipt statuses
    public const RECEIPT_ISSUED = 'issued';
    public const RECEIPT_VOIDED = 'voided';

    /**
     * Get all valid invoice statuses
     */
    public static function allInvoiceStatuses(): array
    {
        return [
            self::INVOICE_DRAFT,
            self::INVOICE_ISSUED,
            self::INVOICE_PARTIALLY_PAID,
            self::INVOICE_PAID,
            self::INVOICE_OVERDUE,
            self::INVOICE_CANCELLED,
            self::INVOICE_CREDIT_NOTE,
        ];
    }

    /**
     * Get invoice statuses that should be excluded from balance calculations
     */
    public static function invoiceExcludedFromBalance(): array
    {
        return [
            self::INVOICE_DRAFT,
            self::INVOICE_CANCELLED,
            self::INVOICE_CREDIT_NOTE,
        ];
    }

    /**
     * Get invoice statuses that can be paid against
     */
    public static function invoicePayableStatuses(): array
    {
        return [
            self::INVOICE_ISSUED,
            self::INVOICE_PARTIALLY_PAID,
            self::INVOICE_OVERDUE,
        ];
    }

    /**
     * Get all valid payment statuses
     */
    public static function allPaymentStatuses(): array
    {
        return [
            self::PAYMENT_PENDING,
            self::PAYMENT_SUCCESSFUL,
            self::PAYMENT_FAILED,
            self::PAYMENT_REVERSED,
            self::PAYMENT_FULLY_ALLOCATED,
            self::PAYMENT_PARTIALLY_ALLOCATED,
            self::PAYMENT_REFUNDED,
        ];
    }

    /**
     * Get payment statuses that indicate successful payment
     */
    public static function paymentSuccessStatuses(): array
    {
        return [
            self::PAYMENT_SUCCESSFUL,
            self::PAYMENT_FULLY_ALLOCATED,
            self::PAYMENT_PARTIALLY_ALLOCATED,
        ];
    }
}
