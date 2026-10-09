<?php

namespace App\Support;

use NumberFormatter;

class Money
{
    /** Format an amount with its currency symbol, e.g. "$1,250.00" or "₱500.00". */
    public static function format(float $amount, ?string $currency): string
    {
        $currency = $currency ?: 'USD';
        if (class_exists(NumberFormatter::class)) {
            $formatted = (new NumberFormatter('en', NumberFormatter::CURRENCY))->formatCurrency($amount, $currency);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return $currency.' '.number_format($amount, 2);
    }
}
