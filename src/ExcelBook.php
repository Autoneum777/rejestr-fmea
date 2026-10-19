<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class ExcelBook
{
    public static function mergedMap(Worksheet $sheet): array
    {
        $map = [];
        foreach ($sheet->getMergeCells() as $range) {
            [[$startCol, $startRow], [$endCol, $endRow]] = Coordinate::rangeBoundaries($range);
            for ($row = $startRow; $row <= $endRow; $row++) {
                for ($col = $startCol; $col <= $endCol; $col++) {
                    $map[$row][$col] = [$startRow, $startCol];
                }
            }
        }
        return $map;
    }

    public static function value(Worksheet $sheet, array $merged, int $row, int $col): mixed
    {
        if (isset($merged[$row][$col])) {
            [$row, $col] = $merged[$row][$col];
        }
        return self::cellValue($sheet->getCell(Coordinate::stringFromColumnIndex($col) . $row));
    }

    public static function cellValue(Cell $cell): mixed
    {
        $value = $cell->getValue();
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if (is_string($value) && str_starts_with($value, '=')) {
            $cached = $cell->getOldCalculatedValue();
            if ($cached !== null && $cached !== '') {
                $value = $cached;
            }
        }
        if (is_numeric($value) && Date::isDateTime($cell)) {
            return Date::excelToDateTimeObject((float) $value);
        }
        return $value;
    }

    public static function nonempty(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        return !(is_string($value) && trim($value) === '');
    }

    public static function text(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if ($value === null || is_array($value)) {
            return '';
        }
        return trim((string) $value);
    }

    public static function clean(mixed $value): string
    {
        $text = self::text($value);
        if (in_array(mb_strtolower($text), ['--', '-', 'none', 'nd'], true)) {
            return '';
        }
        return $text;
    }

    public static function isGrayFill(Cell $cell): bool
    {
        $fill = $cell->getStyle()->getFill();
        if (!in_array($fill->getFillType(), ['solid', 'gray125', 'gray0625'], true)) {
            return false;
        }
        $hex = strtoupper(substr((string) $fill->getStartColor()->getARGB(), -6));
        if ($hex === '' || in_array($hex, ['000000', 'FFFFFF'], true) || !ctype_xdigit($hex) || strlen($hex) !== 6) {
            return false;
        }
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));
        return abs($red - $green) < 30 && abs($green - $blue) < 30 && $red >= 70 && $red <= 210;
    }

    public static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
        }
        return Support::parseDate($value);
    }
}
