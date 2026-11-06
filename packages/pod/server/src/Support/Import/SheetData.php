<?php

namespace Fleetbase\Pod\Support\Import;

/**
 * The contents of one sheet: headers, and rows keyed by spreadsheet row number.
 */
final class SheetData
{
    /**
     * @param list<string>             $headers
     * @param array<int, list<mixed>> $rows    spreadsheet row number => cells
     */
    public function __construct(
        public readonly string $sheet,
        public readonly int $headerRow,
        public readonly array $headers,
        public readonly array $rows,
    ) {
    }

    /**
     * Rows as header => value arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function associativeRows(): array
    {
        $out = [];
        foreach ($this->rows as $number => $cells) {
            $row = [];
            foreach ($this->headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $cells[$index] ?? null;
            }
            $out[$number] = $row;
        }

        return $out;
    }
}
