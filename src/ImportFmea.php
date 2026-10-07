<?php

declare(strict_types=1);

namespace App;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class ImportFmea
{
    public static function defaultPath(): string
    {
        return Database::dataDir() . DIRECTORY_SEPARATOR . 'Rejestr FMEA.xlsx';
    }

    public function import(?string $path = null, bool $replace = true): array
    {
        $path ??= self::defaultPath();
        if (!is_file($path)) {
            throw new \RuntimeException('Nie znaleziono pliku: ' . $path);
        }
        $db = Database::open();
        $db->init();
        $sheet = IOFactory::load($path)->getActiveSheet();
        $merged = ExcelBook::mergedMap($sheet);
        $groups = $this->groups($sheet, $merged);
        $stamp = Support::now();
        $inserted = 0;
        $reviews = 0;
        $register = new Register($db);
        $db->pdo->beginTransaction();
        if ($replace) {
            $db->pdo->exec('DELETE FROM reviews');
            $db->pdo->exec('DELETE FROM fmea');
            $db->pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('fmea', 'reviews')");
        }
        foreach ($groups as $group) {
            $rows = $group['reviews'];
            usort($rows, static fn (array $a, array $b) => $a['review_date'] <=> $b['review_date']);
            $group['osoba_wiodaca'] = $register->rememberResponsible((string) $group['osoba_wiodaca']);
            $last = $rows === [] ? null : $rows[array_key_last($rows)];
            $lastDate = $last['review_date'] ?? null;
            $nextDue = $lastDate ? Support::addMonths($lastDate, 12) : null;
            $warning = $lastDate ? Support::addMonths($lastDate, 11) : null;
            $db->exec(
                'INSERT INTO fmea (
                    nr_fmea, part_numbers, projekt, rodzaj_wyrobu, osoba_wiodaca,
                    last_full_date, next_due_date, warning_date,
                    last_review_date, last_review_type, last_review_reason,
                    aktywne, uwagi, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $group['nr_fmea'],
                    $group['part_numbers'],
                    $group['projekt'],
                    $group['rodzaj_wyrobu'],
                    $group['osoba_wiodaca'],
                    Support::iso($lastDate),
                    Support::iso($nextDue),
                    Support::iso($warning),
                    $last ? Support::iso($last['review_date']) : null,
                    $last['review_type'] ?? null,
                    $last['reason'] ?? null,
                    $group['aktywne'],
                    $group['uwagi'],
                    $stamp,
                    $stamp,
                ]
            );
            $id = $db->lastId();
            $inserted++;
            foreach ($rows as $review) {
                $db->exec(
                    'INSERT INTO reviews (
                        fmea_id, review_date, review_type, reason,
                        next_due_after, warning_after, comment, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $id,
                        Support::iso($review['review_date']),
                        $review['review_type'],
                        $review['reason'],
                        Support::iso($review['due']),
                        Support::iso($review['warning']),
                        $review['comment'],
                        $stamp,
                    ]
                );
                $reviews++;
            }
        }
        $db->pdo->commit();
        return ['fmea' => $inserted, 'reviews' => $reviews, 'users' => $register->addedUsers(), 'source' => $path];
    }

    private function groups(Worksheet $sheet, array $merged): array
    {
        $groups = [];
        $current = null;
        $highest = (int) $sheet->getHighestRow();
        for ($row = 2; $row <= $highest; $row++) {
            $rawNr = ExcelBook::value($sheet, $merged, $row, 1);
            $parts = ExcelBook::value($sheet, $merged, $row, 2);
            $projekt = ExcelBook::value($sheet, $merged, $row, 3);
            $rodzaj = ExcelBook::value($sheet, $merged, $row, 4);
            $osoba = ExcelBook::value($sheet, $merged, $row, 6);
            $revRaw = ExcelBook::value($sheet, $merged, $row, 8);
            $reason = ExcelBook::value($sheet, $merged, $row, 9);
            $warningRaw = ExcelBook::value($sheet, $merged, $row, 10);
            $dueRaw = ExcelBook::value($sheet, $merged, $row, 11);
            $uwagi = ExcelBook::value($sheet, $merged, $row, 15);
            $nr = ExcelBook::nonempty($rawNr) ? rtrim(trim((string) $rawNr), '.') : null;
            $isHeader = $nr !== null && !($current !== null && $current['nr_fmea'] === $nr);
            $hasReview = ExcelBook::nonempty($revRaw) || ExcelBook::nonempty($reason);
            $hasMaster = ExcelBook::nonempty($parts) || ExcelBook::nonempty($projekt) || ExcelBook::nonempty($rodzaj) || ExcelBook::nonempty($osoba);
            if (!$isHeader && !$hasReview && !$hasMaster && $nr === null) {
                continue;
            }
            if ($isHeader) {
                $current = [
                    'nr_fmea' => $nr,
                    'part_numbers' => ExcelBook::nonempty($parts) ? trim((string) $parts) : '',
                    'projekt' => ExcelBook::nonempty($projekt) ? trim((string) $projekt) : '',
                    'rodzaj_wyrobu' => ExcelBook::nonempty($rodzaj) ? trim((string) $rodzaj) : '',
                    'osoba_wiodaca' => ExcelBook::nonempty($osoba) ? rtrim(trim((string) $osoba), ',') : '',
                    'uwagi' => ExcelBook::nonempty($uwagi) ? trim((string) $uwagi) : '',
                    'aktywne' => 1,
                    'last_row' => $row,
                    'reviews' => [],
                ];
                $groups[] = $current;
            } elseif ($current === null) {
                continue;
            } else {
                if (ExcelBook::nonempty($parts)) {
                    $current['part_numbers'] = trim((string) $parts);
                }
                if (ExcelBook::nonempty($projekt)) {
                    $current['projekt'] = trim((string) $projekt);
                }
                if (ExcelBook::nonempty($rodzaj)) {
                    $current['rodzaj_wyrobu'] = trim((string) $rodzaj);
                }
                if (ExcelBook::nonempty($osoba)) {
                    $current['osoba_wiodaca'] = rtrim(trim((string) $osoba), ',');
                }
                if (ExcelBook::nonempty($uwagi) && $current['uwagi'] === '') {
                    $current['uwagi'] = trim((string) $uwagi);
                }
                $current['last_row'] = $row;
            }
            if ($hasReview && $current !== null) {
                $reviewDate = ExcelBook::parseDate($revRaw);
                if ($reviewDate === null) {
                    $groups[array_key_last($groups)] = $current;
                    continue;
                }
                $warning = ExcelBook::parseDate($warningRaw) ?? Support::addMonths($reviewDate, 11);
                $due = ExcelBook::parseDate($dueRaw) ?? Support::addMonths($reviewDate, 12);
                $current['reviews'][] = [
                    'review_date' => $reviewDate,
                    'review_type' => $this->classify($reason),
                    'reason' => ExcelBook::nonempty($reason) ? trim((string) $reason) : '',
                    'warning' => $warning,
                    'due' => $due,
                    'comment' => ExcelBook::nonempty($uwagi) ? trim((string) $uwagi) : '',
                ];
            }
            $groups[array_key_last($groups)] = $current;
        }
        foreach ($groups as &$group) {
            $last = $group['last_row'] ?? null;
            if (!$last) {
                continue;
            }
            $values = [
                ExcelBook::value($sheet, $merged, $last, 7),
                ExcelBook::value($sheet, $merged, $last, 8),
                ExcelBook::value($sheet, $merged, $last, 9),
                ExcelBook::value($sheet, $merged, $last, 14),
                ExcelBook::value($sheet, $merged, $last, 15),
            ];
            $serwis = false;
            foreach ($values as $value) {
                if (mb_strtolower(trim((string) $value)) === 'serwis') {
                    $serwis = true;
                    break;
                }
            }
            $gray = 0;
            for ($col = 1; $col <= 15; $col++) {
                if (ExcelBook::isGrayFill($sheet->getCell(Coordinate::stringFromColumnIndex($col) . $last))) {
                    $gray++;
                }
            }
            $group['aktywne'] = ($serwis || $gray >= 6) ? 0 : 1;
        }
        unset($group);
        return $groups;
    }

    private function classify(mixed $reason): string
    {
        $text = trim((string) ($reason ?? ''));
        if ($text === '' || preg_match('/^\s*(-|przegl[aą]d\s*roczny|przegl[aą]d\s*fmea)\s*$/iu', $text)) {
            return 'pelny';
        }
        return 'zdarzeniowy';
    }
}
