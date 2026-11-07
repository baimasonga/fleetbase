<?php

namespace Fleetbase\Pod\Support\Import;

use Fleetbase\Pod\Support\Text\Text as BaseText;

/**
 * Spreadsheet cell values as clean strings.
 */
final class Cell
{
    /**
     * A cell value as a trimmed string. Whole numbers stay whole ("76123456", not "76123456.0").
     */
    public static function value(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return BaseText::squish((string) $value);
    }
}
