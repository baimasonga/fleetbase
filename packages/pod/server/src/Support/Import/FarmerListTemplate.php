<?php

namespace Fleetbase\Pod\Support\Import;

use Fleetbase\Pod\Support\AdminAreas\AdminAreaSet;
use Fleetbase\Pod\Support\Countries\CountryProfile;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The downloadable Farmer List Template (.xlsx) for a country profile
 * (AGRI-INPUTS.md §7.1). Its headers map to beneficiary fields exactly, so a
 * file made from it needs no column mapping.
 */
final class FarmerListTemplate
{
    public const VERSION = 'FBP-TEMPLATE-1';

    public const ROWS = 5000;

    /**
     * Template columns: field => [header, width, note].
     * Admin level headers come from the country profile.
     */
    public static function columns(CountryProfile $country): array
    {
        return [
            'farmer_ref'     => ['Farmer ID', 14, 'Your own reference, if you have one. Optional.'],
            'full_name'      => ['Full Name', 28, 'First and last name, e.g. Aminata Kamara.'],
            'sex'            => ['Sex', 9, 'Choose Male or Female.'],
            'age'            => ['Age', 7, 'Age in years. Leave empty if you fill in Year of Birth.'],
            'birth_year'     => ['Year of Birth', 13, 'e.g. 1985. Optional if Age is filled in.'],
            'phone'          => ['Phone Number', 16, "e.g. {$country->dialCode} 76 123456, or 076 123456. Optional."],
            'id_type'        => ['ID Type', 18, 'Choose from the list. Leave empty if the farmer has no ID.'],
            'id_number'      => ['ID Number', 18, 'Only the last 4 characters are kept in the system.'],
            'region'         => [$country->adminLevelLabel(1), 16, 'Choose from the list.'],
            'district'       => [$country->adminLevelLabel(2), 18, 'Required. Choose from the list.'],
            'chiefdom'       => [$country->adminLevelLabel(3), 22, 'Choose from the list.'],
            'section'        => [$country->adminLevelLabel(4), 20, 'Type the name as officially written.'],
            'village'        => ['Village', 20, 'The village or town where the farmer lives.'],
            'group'          => ['Farmer Group', 20, 'Group, cooperative or FBO. Optional.'],
            'latitude'       => ['Latitude', 12, 'GPS latitude in decimal degrees, e.g. 8.4844. Optional.'],
            'longitude'      => ['Longitude', 12, 'GPS longitude in decimal degrees, e.g. -13.2344. Optional.'],
            'household_size' => ['Household Size', 15, 'Number of people. Optional.'],
            'farm_size'      => ['Farm Size', 12, 'With the unit, e.g. 2 acres or 1.5 ha. Optional.'],
            'crop'           => ['Main Crop', 16, 'Optional.'],
            'notes'          => ['Notes', 30, 'Optional.'],
        ];
    }

    /**
     * field => header, for files made from this template.
     *
     * @return array<string, string>
     */
    public static function mapping(CountryProfile $country): array
    {
        $mapping = [];
        foreach (self::columns($country) as $field => [$header]) {
            $mapping[$field] = $header;
        }

        return $mapping;
    }

    /**
     * True when a sheet's headers are this template's headers, in order.
     *
     * @param list<string> $headers
     */
    public static function matches(CountryProfile $country, array $headers): bool
    {
        return array_values(self::mapping($country)) === array_slice($headers, 0, count(self::mapping($country)));
    }

    public function build(CountryProfile $country, AdminAreaSet $areas): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getProperties()
            ->setTitle('Farmer List Template - ' . $country->name)
            ->setSubject(self::VERSION . '|' . $country->code)
            ->setCreator('Fleetbase Proof of Delivery');

        $farmers = $book->getActiveSheet();
        $farmers->setTitle('Farmers');

        $lists = $book->createSheet();
        $lists->setTitle('Lists');
        $listRanges = $this->writeLists($lists, $country, $areas);
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $this->writeFarmers($farmers, $country, $listRanges);

        $instructions = $book->createSheet();
        $instructions->setTitle('Instructions');
        $this->writeInstructions($instructions, $country);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * @return array<string, string> list name => absolute range on the Lists sheet
     */
    private function writeLists(Worksheet $sheet, CountryProfile $country, AdminAreaSet $areas): array
    {
        $lists = [
            'sex'      => ['Male', 'Female'],
            'id_type'  => array_values($country->idTypeLabels()),
            'region'   => $this->names($areas->atLevel(1)),
            'district' => $this->names($areas->atLevel(2)),
            'chiefdom' => $this->names($areas->atLevel(3)),
        ];

        $ranges = [];
        $column = 'A';
        foreach ($lists as $name => $values) {
            $sheet->setCellValue($column . '1', $name);
            foreach ($values as $i => $value) {
                $sheet->setCellValueExplicit($column . ($i + 2), $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $last          = max(2, count($values) + 1);
            $ranges[$name] = "Lists!\${$column}\$2:\${$column}\${$last}";
            $column++;
        }

        // A marker so an uploaded file can be recognised as this template.
        $sheet->setCellValue($column . '1', self::VERSION . '|' . $country->code);

        return $ranges;
    }

    private function writeFarmers(Worksheet $sheet, CountryProfile $country, array $listRanges): void
    {
        $columns = self::columns($country);
        $letter  = 'A';
        $letters = [];

        foreach ($columns as $field => [$header, $width, $note]) {
            $letters[$field] = $letter;
            $sheet->setCellValue($letter . '1', $header);
            $sheet->getColumnDimension($letter)->setWidth($width);
            $sheet->getComment($letter . '1')->getText()->createTextRun($note);
            $letter++;
        }

        $lastLetter = chr(ord('A') + count($columns) - 1);
        $header     = $sheet->getStyle("A1:{$lastLetter}1");
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAD3');
        $sheet->freezePane('A2');

        $last = self::ROWS + 1;

        // Keep phone and ID numbers as text, so Excel doesn't drop leading zeros
        // or turn long numbers into 7.6E+07.
        foreach (['phone', 'id_number', 'farmer_ref'] as $field) {
            $sheet->getStyle("{$letters[$field]}2:{$letters[$field]}{$last}")
                ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        foreach (['sex', 'id_type', 'region', 'district', 'chiefdom'] as $field) {
            $this->listValidation($sheet, "{$letters[$field]}2:{$letters[$field]}{$last}", $listRanges[$field], $columns[$field][0], $field === 'district');
        }

        $this->numberValidation($sheet, "{$letters['age']}2:{$letters['age']}{$last}", DataValidation::TYPE_WHOLE, '10', '110', 'Age must be a whole number from 10 to 110.');
        $this->numberValidation($sheet, "{$letters['birth_year']}2:{$letters['birth_year']}{$last}", DataValidation::TYPE_WHOLE, '1915', (string) ((int) date('Y') - 10), 'Year of birth must be a 4-digit year.');
        $this->numberValidation($sheet, "{$letters['latitude']}2:{$letters['latitude']}{$last}", DataValidation::TYPE_DECIMAL, '-90', '90', 'Latitude must be between -90 and 90.');
        $this->numberValidation($sheet, "{$letters['longitude']}2:{$letters['longitude']}{$last}", DataValidation::TYPE_DECIMAL, '-180', '180', 'Longitude must be between -180 and 180.');
        $this->numberValidation($sheet, "{$letters['household_size']}2:{$letters['household_size']}{$last}", DataValidation::TYPE_WHOLE, '1', '100', 'Household size must be a whole number.');
    }

    private function listValidation(Worksheet $sheet, string $range, string $source, string $label, bool $required): void
    {
        $validation = new DataValidation();
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle($required ? DataValidation::STYLE_STOP : DataValidation::STYLE_WARNING)
            ->setAllowBlank(!$required)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle($label)
            ->setError("Choose a {$label} from the list.")
            ->setFormula1('=' . $source);
        $sheet->setDataValidation($range, $validation);
    }

    private function numberValidation(Worksheet $sheet, string $range, string $type, string $min, string $max, string $message): void
    {
        $validation = new DataValidation();
        $validation->setType($type)
            ->setOperator(DataValidation::OPERATOR_BETWEEN)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowErrorMessage(true)
            ->setError($message)
            ->setFormula1($min)
            ->setFormula2($max);
        $sheet->setDataValidation($range, $validation);
    }

    private function writeInstructions(Worksheet $sheet, CountryProfile $country): void
    {
        $lines = [
            'Farmer List Template - ' . $country->name,
            '',
            'How to fill in this file',
            '1. Put one farmer on each row of the "Farmers" sheet. Do not change the column headers.',
            '2. Full Name, Sex and ' . $country->adminLevelLabel(2) . ' are required. Fill in everything else you have.',
            '3. Choose Sex, ID Type, ' . $country->adminLevelLabel(1) . ', ' . $country->adminLevelLabel(2) . ' and ' . $country->adminLevelLabel(3) . ' from the drop-down lists.',
            '4. Type phone numbers with the leading 0 or ' . $country->dialCode . '. The column is set to text so the 0 is kept.',
            '5. Give Age or Year of Birth, not both.',
            '6. Leave ID Type and ID Number empty if the farmer has no ID. Only the last 4 characters of an ID number are kept.',
            '7. Save as .xlsx and upload it in the console: Programme > Import beneficiaries.',
            '',
            'Before the first distribution, every farmer on the list is verified in the field and given a programme card.',
            'Template version: ' . self::VERSION . ' (' . $country->code . ')',
        ];

        foreach ($lines as $i => $line) {
            $sheet->setCellValue('A' . ($i + 1), $line);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(110);
    }

    /**
     * @param list<\Fleetbase\Pod\Support\AdminAreas\AdminArea> $areas
     *
     * @return list<string>
     */
    private function names(array $areas): array
    {
        $names = array_map(fn ($area) => $area->name, $areas);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values(array_unique($names));
    }
}
