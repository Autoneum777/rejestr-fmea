<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

final class ExcelExport
{
    private const GREEN = 'A4C400';
    private const GREEN_DARK = '8A9300';
    private const BLUE = '008BC1';
    private const ORANGE = 'E86A10';
    private const GRAY = '7A7B7A';
    private const INK = '1B2430';

    private const STATUS = [
        'przeterminowane' => 'Przeterminowane',
        'ostrzezenie' => 'Miesiąc do końca',
        'aktualne' => 'Aktualne',
        'brak_terminu' => 'Brak terminu',
    ];

    private const TYPES = ['pelny' => 'Pełny', 'zdarzeniowy' => 'Zdarzeniowy'];

    private const DATES = [
        'warning_date', 'next_due_date', 'last_full_date', 'review_date', 'next_due_after',
        'current_fmea_date', 'data_rewizji', 'data_zatwierdzenia', 'data_anulowania', 'created_at',
    ];

    private const FMEA = [
        ['nr_fmea', 'Nr FMEA', 12],
        ['part_numbers', 'Wyroby gotowe (Part number)', 42],
        ['projekt', 'Projekt', 18],
        ['rodzaj_wyrobu', 'Rodzaj wyrobu', 26],
        ['osoba_wiodaca', 'Osoba wiodąca', 18],
        ['current_fmea_date', 'Data obecnego FMEA', 18],
        ['last_review_reason', 'Powód ost. rewizji', 34],
        ['next_due_date', 'Termin kolejnej rewizji', 20],
        ['status', 'Status', 18],
        ['last_full_date', 'Ostatni pełny FMEA', 18],
        ['years_since_full', 'Lata od pełnego FMEA', 19],
        ['uwagi', 'Uwagi', 30],
    ];

    private const FMEA_HISTORY = [
        ['nr_fmea', 'Nr FMEA', 12],
        ['projekt', 'Projekt', 18],
        ['review_date', 'Data przeglądu', 15],
        ['review_type', 'Typ przeglądu', 15],
        ['reason', 'Powód rewizji', 40],
        ['next_due_after', 'Termin po przeglądzie', 20],
        ['comment', 'Komentarz', 30],
    ];

    private const CP = [
        ['nr_planu', 'Plan kontroli nr', 22],
        ['nr_fmea', 'Numer FMEA', 14],
        ['osoba_wiodaca', 'Odpowiedzialny QE', 20],
        ['symbol_wyrobu', 'Symbol wyrobu', 22],
        ['model', 'Model samochodu', 22],
        ['nr_rysunku', 'Nr rysunku lub części', 24],
        ['linia_mes', 'Linia MES', 16],
        ['data_rewizji', 'Data rewizji', 16],
        ['next_due_date', 'Termin kolejnej rewizji', 22],
        ['status', 'Status', 18],
        ['nazwa_wyrobu', 'Nazwa wyrobu', 28],
        ['nr_zmiany', 'Nr zmiany', 16],
        ['data_zatwierdzenia', 'Data zatwierdzenia', 18],
        ['nr_rewizji', 'Nr rewizji', 14],
        ['uwagi', 'Uwagi', 28],
        ['data_anulowania', 'Data anulowania', 16],
    ];

    private const CP_HISTORY = [
        ['nr_planu', 'Plan kontroli nr', 22],
        ['nr_fmea', 'Numer FMEA', 14],
        ['review_date', 'Data rewizji', 16],
        ['review_type', 'Typ', 14],
        ['reason', 'Powód rewizji', 36],
        ['next_due_after', 'Termin po zapisie', 20],
    ];

    private const CP_CHANGES = [
        ['nr_planu', 'Plan kontroli nr', 22],
        ['created_at', 'Kiedy', 20],
        ['field_label', 'Pole', 28],
        ['old_value', 'Było', 28],
        ['new_value', 'Jest', 28],
    ];

    public function fmea(array $items, array $history, array $filters, array $settings): string
    {
        $book = new Spreadsheet();
        $fills = $this->fills($settings);
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Rejestr FMEA');
        $header = $this->brand($sheet, 'Rejestr FMEA', 'Wygenerowano: ' . date('d.m.Y H:i') . ' · pozycji: ' . count($items), count(self::FMEA));
        $this->table($sheet, $header, self::FMEA, $items, 'status', $fills);
        $sheet->getTabColor()->setRGB(self::GREEN);
        $historySheet = $book->createSheet();
        $historySheet->setTitle('Historia przeglądów');
        $historyHeader = $this->brand($historySheet, 'Historia przeglądów FMEA', 'Zapisów: ' . count($history) . ' · pełny przegląd przesuwa termin, zdarzeniowy nie', count(self::FMEA_HISTORY));
        $this->table($historySheet, $historyHeader, self::FMEA_HISTORY, $history, null, $fills);
        $historySheet->getTabColor()->setRGB(self::BLUE);
        $this->summary($book, $items, $filters, $fills);
        return $this->binary($book);
    }

    public function controlPlans(array $items, array $history, array $changes, array $filters, array $settings): string
    {
        $book = new Spreadsheet();
        $fills = $this->fills($settings);
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Plany kontroli');
        $header = $this->brand($sheet, 'Rejestr planów kontroli', 'Wygenerowano: ' . date('d.m.Y H:i') . ' · pozycji: ' . count($items), count(self::CP));
        $this->table($sheet, $header, self::CP, $items, 'status', $fills);
        $sheet->getTabColor()->setRGB(self::GREEN);
        $historySheet = $book->createSheet();
        $historySheet->setTitle('Historia rewizji');
        $historyHeader = $this->brand($historySheet, 'Historia rewizji', 'Zapisów: ' . count($history), count(self::CP_HISTORY));
        $this->table($historySheet, $historyHeader, self::CP_HISTORY, $history, null, $fills);
        $historySheet->getTabColor()->setRGB(self::BLUE);
        $changesSheet = $book->createSheet();
        $changesSheet->setTitle('Historia zmian');
        $changesHeader = $this->brand($changesSheet, 'Historia zmian opisów i statusów', 'Zapisów: ' . count($changes), count(self::CP_CHANGES));
        $this->table($changesSheet, $changesHeader, self::CP_CHANGES, $changes, null, $fills);
        $changesSheet->getTabColor()->setRGB(self::GRAY);
        $this->summary($book, $items, $filters, $fills);
        return $this->binary($book);
    }

    private function fills(array $settings): array
    {
        return [
            'przeterminowane' => $this->hex((string) ($settings['color_overdue'] ?? ''), 'F8D0CB'),
            'ostrzezenie' => $this->hex((string) ($settings['color_warning'] ?? ''), 'FFF2CC'),
            'aktualne' => 'E8F0C8',
            'brak_terminu' => 'EFEFEF',
        ];
    }

    private function brand(Worksheet $sheet, string $title, string $subtitle, int $span): int
    {
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->mergeCells([1, 1, max($span - 3, 2), 1]);
        $sheet->setCellValue([1, 1], $title);
        $sheet->getStyle([1, 1])->getFont()->setName('Segoe UI')->setSize(17)->setBold(true)->getColor()->setRGB(self::GREEN_DARK);
        $sheet->mergeCells([1, 2, max($span - 3, 2), 2]);
        $sheet->setCellValue([1, 2], $subtitle);
        $sheet->getStyle([1, 2])->getFont()->setName('Segoe UI')->setSize(10)->getColor()->setRGB(self::GRAY);
        $logo = Database::root() . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'logo-autoneum.png';
        if (is_file($logo)) {
            $size = getimagesize($logo) ?: [168, 42];
            $drawing = new Drawing();
            $drawing->setPath($logo);
            $drawing->setHeight(42);
            $drawing->setWidth((int) (42 * ($size[0] / max(1, $size[1]))));
            $drawing->setCoordinates(Coordinate::stringFromColumnIndex(max($span - 2, 3)) . '1');
            $drawing->setWorksheet($sheet);
        }
        for ($col = 1; $col <= $span; $col++) {
            $sheet->getStyle([$col, 3])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GREEN);
        }
        $sheet->getRowDimension(3)->setRowHeight(4);
        return 5;
    }

    private function table(Worksheet $sheet, int $headerRow, array $columns, array $rows, ?string $statusCol, array $fills): void
    {
        $thin = ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D7DCC4']];
        $border = ['borders' => ['allBorders' => $thin]];
        foreach ($columns as $index => [$key, $label, $width]) {
            $col = $index + 1;
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth($width);
            $cell = $sheet->getCell([$col, $headerRow]);
            $cell->setValue($label);
            $style = $sheet->getStyle([$col, $headerRow]);
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GREEN);
            $style->getFont()->setName('Segoe UI')->setSize(10)->setBold(true)->getColor()->setRGB('FFFFFF');
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $style->applyFromArray($border);
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(30);
        foreach ($rows as $offset => $item) {
            $row = $headerRow + 1 + $offset;
            $status = (string) ($item['status'] ?? '');
            $rowFill = in_array($status, ['przeterminowane', 'ostrzezenie'], true) ? $fills[$status] : null;
            foreach ($columns as $index => [$key]) {
                $col = $index + 1;
                $value = $item[$key] ?? '';
                if (in_array($key, self::DATES, true)) {
                    $parsed = Support::parseDate($value);
                    $value = $parsed ?? ($value ?: '');
                } elseif ($key === 'status') {
                    $value = self::STATUS[$value] ?? ($value ?: '');
                } elseif ($key === 'review_type') {
                    $value = self::TYPES[$value] ?? ($value ?: '');
                } elseif ($value === null) {
                    $value = '';
                }
                $sheet->setCellValue([$col, $row], $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : $value);
                $style = $sheet->getStyle([$col, $row]);
                $style->applyFromArray($border);
                $style->getFont()->setName('Segoe UI')->setSize(10)->getColor()->setRGB(self::INK);
                if ($value instanceof DateTimeInterface) {
                    $style->getNumberFormat()->setFormatCode('DD.MM.YYYY');
                    $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_TOP);
                } elseif (in_array($key, ['nr_fmea', 'years_since_full'], true)) {
                    $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_TOP);
                } else {
                    $style->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
                }
                if ($rowFill !== null) {
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rowFill);
                } elseif ($statusCol === $key && $status !== '') {
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fills[$status] ?? 'EFEFEF');
                    $style->getFont()->setBold(true);
                } elseif ($key === 'review_type') {
                    $style->getFont()->setBold(true)->getColor()->setRGB(($item['review_type'] ?? '') === 'pelny' ? self::GREEN_DARK : self::ORANGE);
                    $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                } elseif ($row % 2 === 0) {
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FAFBF3');
                }
            }
        }
        $last = max($headerRow + count($rows), $headerRow);
        $sheet->setAutoFilter([1, $headerRow, count($columns), $last]);
        $sheet->freezePane([1, $headerRow + 1]);
    }

    private function summary(Spreadsheet $book, array $items, array $filters, array $fills): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('Podsumowanie');
        $row = $this->brand($sheet, 'Rejestr FMEA — podsumowanie', 'Wygenerowano: ' . date('d.m.Y H:i'), 4);
        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(16);
        $counts = [
            ['Pozycji w zestawieniu', count($items), null],
            ['Przeterminowane', count(array_filter($items, static fn ($item) => ($item['status'] ?? '') === 'przeterminowane')), 'przeterminowane'],
            ['Miesiąc do końca', count(array_filter($items, static fn ($item) => ($item['status'] ?? '') === 'ostrzezenie')), 'ostrzezenie'],
            ['Aktualne', count(array_filter($items, static fn ($item) => ($item['status'] ?? '') === 'aktualne')), 'aktualne'],
            ['Bez terminu', count(array_filter($items, static fn ($item) => ($item['status'] ?? '') === 'brak_terminu')), 'brak_terminu'],
        ];
        foreach ($counts as [$label, $value, $status]) {
            $sheet->setCellValue([1, $row], $label);
            $sheet->getStyle([1, $row])->getFont()->setName('Segoe UI')->setSize(10)->setBold(true)->getColor()->setRGB(self::INK);
            $sheet->getStyle([1, $row])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F6D9');
            $sheet->setCellValue([2, $row], $value);
            $sheet->getStyle([2, $row])->getFont()->setName('Segoe UI')->setSize(11)->setBold(true)->getColor()->setRGB(self::INK);
            $sheet->getStyle([2, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            if ($status !== null) {
                $sheet->getStyle([2, $row])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fills[$status]);
            }
            $row++;
        }
        $row++;
        $sheet->setCellValue([1, $row], 'Zastosowane filtry');
        $sheet->getStyle([1, $row])->getFont()->setBold(true)->getColor()->setRGB(self::GREEN_DARK);
        $row++;
        foreach ([['Szukana fraza', $filters['q'] ?: '—'], ['Status', self::STATUS[$filters['status'] ?? ''] ?? 'Wszystkie']] as [$label, $value]) {
            $sheet->setCellValue([1, $row], $label);
            $sheet->getStyle([1, $row])->getFont()->setName('Segoe UI')->setSize(10)->getColor()->setRGB(self::GRAY);
            $sheet->setCellValue([2, $row], $value);
            $sheet->getStyle([2, $row])->getFont()->setName('Segoe UI')->setSize(10)->getColor()->setRGB(self::INK);
            $row++;
        }
        $sheet->getTabColor()->setRGB(self::GRAY);
    }

    private function hex(string $value, string $fallback): string
    {
        $text = strtoupper(ltrim(trim($value), '#'));
        if (strlen($text) === 3) {
            $text = $text[0] . $text[0] . $text[1] . $text[1] . $text[2] . $text[2];
        }
        return strlen($text) === 6 ? $text : $fallback;
    }

    private function binary(Spreadsheet $book): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rejestr');
        if ($tmp === false) {
            throw new \RuntimeException('Nie udało się utworzyć pliku eksportu');
        }
        (new Xlsx($book))->save($tmp);
        $binary = (string) file_get_contents($tmp);
        unlink($tmp);
        return $binary;
    }
}
