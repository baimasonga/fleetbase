<?php

namespace Fleetbase\Pod\Support\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;

/**
 * Reads .xlsx, .xls and .csv farmer lists into plain arrays.
 */
final class SpreadsheetReader
{
    public const EXTENSIONS = ['xlsx', 'xls', 'csv'];

    /**
     * @return list<string> sheet names (one, unnamed "Sheet1", for CSV)
     */
    public function sheetNames(string $path, ?string $extension = null): array
    {
        $reader = $this->reader($path, $extension);
        if ($reader instanceof Csv) {
            return ['Sheet1'];
        }

        return $reader->listWorksheetNames($path);
    }

    /**
     * @param string|null $sheet     sheet name; the first sheet when null
     * @param int         $headerRow 1-based row that holds the column headers
     */
    public function read(string $path, ?string $sheet = null, int $headerRow = 1, ?string $extension = null, int $maxRows = 50000): SheetData
    {
        $reader = $this->reader($path, $extension);
        $reader->setReadDataOnly(true);
        if ($sheet !== null && !$reader instanceof Csv) {
            $reader->setLoadSheetsOnly([$sheet]);
        }

        $book      = $reader->load($path);
        $worksheet = $sheet !== null && !$reader instanceof Csv ? $book->getSheetByName($sheet) : $book->getSheet(0);
        if ($worksheet === null) {
            throw new \InvalidArgumentException("Sheet [{$sheet}] not found.");
        }

        $highestRow = $worksheet->getHighestDataRow();
        if ($highestRow - $headerRow > $maxRows) {
            throw new \RuntimeException("The sheet has more than {$maxRows} rows. Split it into smaller files.");
        }

        $all = $worksheet->toArray(null, true, false, false);
        $book->disconnectWorksheets();

        $headers = array_map(fn ($h) => Cell::value($h), $all[$headerRow - 1] ?? []);
        // Drop trailing empty header cells.
        while ($headers && end($headers) === '') {
            array_pop($headers);
        }

        $rows = [];
        for ($i = $headerRow; $i < count($all); $i++) {
            $cells = array_slice($all[$i], 0, count($headers));
            if (!array_filter($cells, fn ($c) => Cell::value($c) !== '')) {
                continue; // blank row
            }
            // Keep the spreadsheet's own row number for messages ("row 14").
            $rows[$i + 1] = $cells;
        }

        return new SheetData($worksheet->getTitle(), $headerRow, $headers, $rows);
    }

    private function reader(string $path, ?string $extension): IReader
    {
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Only .xlsx, .xls and .csv files can be imported.');
        }

        $reader = IOFactory::createReader(match ($extension) {
            'xlsx'  => 'Xlsx',
            'xls'   => 'Xls',
            'csv'   => 'Csv',
        });

        if ($reader instanceof Csv) {
            $reader->setInputEncoding(Csv::GUESS_ENCODING);
        }

        return $reader;
    }
}
