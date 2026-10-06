<?php

declare(strict_types=1);

namespace App;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class ImportControlPlans
{
    private const HEADERS = [
        'plan kontroli nr' => 'nr_planu',
        'numer fmea' => 'nr_fmea',
        'odpowiedzialny qe' => 'qe',
        'symbol wyrobu' => 'symbol_wyrobu',
        'model samochodu' => 'model',
        'nazwa wyrobu' => 'nazwa_wyrobu',
        'nr rysunku lub czesci' => 'nr_rysunku',
        'nr zmiany' => 'nr_zmiany',
        'data zatwierdzenia' => 'data_zatwierdzenia',
        'nr rewizji' => 'nr_rewizji',
        'data rewizji' => 'data_rewizji',
        'data przegladu' => 'data_przegladu',
        'status przegladu' => 'status_przegladu',
        'data anulowania' => 'data_anulowania',
        'uwagi' => 'uwagi',
        'linia mes' => 'linia_mes',
    ];

    private const FALLBACK = [
        'nr_planu' => 2,
        'nr_fmea' => 3,
        'qe' => 4,
        'symbol_wyrobu' => 5,
        'model' => 6,
        'nazwa_wyrobu' => 7,
        'nr_rysunku' => 8,
        'nr_zmiany' => 9,
        'data_zatwierdzenia' => 10,
        'nr_rewizji' => 11,
        'data_rewizji' => 12,
        'data_przegladu' => 15,
        'status_przegladu' => 14,
        'data_anulowania' => 16,
        'uwagi' => 17,
        'linia_mes' => 18,
    ];

    public static function defaultPath(): string
    {
        return dirname(Database::root()) . DIRECTORY_SEPARATOR . 'Rejestr PLANOW KONTROLI.xlsx';
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
        $columns = $this->columns($sheet);
        $stamp = Support::now();
        $inserted = 0;
        $linked = 0;
        $inactive = 0;
        $people = [];
        foreach ($db->all('SELECT nr_fmea, osoba_wiodaca FROM fmea') as $row) {
            $nr = Support::normNr($row['nr_fmea']);
            if ($nr !== '') {
                $people[$nr] = (string) ($row['osoba_wiodaca'] ?? '');
            }
        }
        $db->pdo->beginTransaction();
        if ($replace) {
            $db->pdo->exec('DELETE FROM cp_reviews');
            $db->pdo->exec('DELETE FROM control_plans');
            $db->pdo->exec("DELETE FROM change_log WHERE entity_type = 'cp'");
            $db->pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('control_plans', 'cp_reviews')");
        }
        $highest = (int) $sheet->getHighestRow();
        for ($row = 2; $row <= $highest; $row++) {
            $nrPlanu = ExcelBook::clean($this->read($sheet, $columns['nr_planu'], $row));
            if ($nrPlanu === '') {
                continue;
            }
            $cell = fn (string $key) => $this->read($sheet, $columns[$key], $row);
            $nrFmea = Support::normNr($cell('nr_fmea'));
            $qe = ExcelBook::clean($cell('qe'));
            $approved = ExcelBook::parseDate($cell('data_zatwierdzenia'));
            $revision = Support::latestDate(
                ExcelBook::parseDate($cell('data_rewizji')),
                ExcelBook::parseDate($cell('data_przegladu'))
            );
            $status = ExcelBook::clean($cell('status_przegladu'));
            $basis = $revision ?? $approved;
            $nextDue = $basis ? Support::addMonths($basis, 12) : null;
            $warning = $basis ? Support::addMonths($basis, 11) : null;
            $active = in_array(Support::fold($status), ['ok', 'weryfikacja opozniona'], true) ? 1 : 0;
            if ($nrFmea !== '' && isset($people[$nrFmea]) && $qe === '') {
                $qe = $people[$nrFmea];
            }
            if ($nrFmea !== '' && isset($people[$nrFmea])) {
                $linked++;
            }
            if ($active === 0) {
                $inactive++;
            }
            $db->exec(
                'INSERT INTO control_plans (
                    nr_planu, nr_fmea, qe, symbol_wyrobu, model, nazwa_wyrobu,
                    nr_rysunku, nr_zmiany, data_zatwierdzenia, nr_rewizji, data_rewizji,
                    status_przegladu, uwagi, linia_mes, data_anulowania,
                    last_full_date, next_due_date, warning_date,
                    last_review_date, last_review_type, last_review_reason,
                    aktywne, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $nrPlanu,
                    $nrFmea,
                    $qe,
                    ExcelBook::clean($cell('symbol_wyrobu')),
                    ExcelBook::clean($cell('model')),
                    ExcelBook::clean($cell('nazwa_wyrobu')),
                    ExcelBook::clean($cell('nr_rysunku')),
                    ExcelBook::clean($cell('nr_zmiany')),
                    Support::iso($approved),
                    ExcelBook::clean($cell('nr_rewizji')),
                    Support::iso($revision),
                    $status,
                    ExcelBook::clean($cell('uwagi')),
                    ExcelBook::clean($cell('linia_mes')),
                    Support::iso(ExcelBook::parseDate($cell('data_anulowania'))),
                    Support::iso($basis),
                    Support::iso($nextDue),
                    Support::iso($warning),
                    Support::iso($basis),
                    $basis ? 'pelny' : null,
                    $basis ? 'Import z Excela' : null,
                    $active,
                    $stamp,
                    $stamp,
                ]
            );
            if ($basis) {
                $db->exec(
                    'INSERT INTO cp_reviews (
                        plan_id, review_date, review_type, reason,
                        next_due_after, warning_after, comment, created_at
                    ) VALUES (?, ?, \'pelny\', ?, ?, ?, \'\', ?)',
                    [$db->lastId(), Support::iso($basis), 'Import z Excela', Support::iso($nextDue), Support::iso($warning), $stamp]
                );
            }
            $inserted++;
        }
        $db->pdo->commit();
        return ['plans' => $inserted, 'linked' => $linked, 'inactive' => $inactive, 'source' => $path];
    }

    private function columns(Worksheet $sheet): array
    {
        $found = [];
        $highest = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($col = 1; $col <= $highest; $col++) {
            $name = Support::fold(ExcelBook::clean($this->read($sheet, $col, 1)));
            if (isset(self::HEADERS[$name])) {
                $found[self::HEADERS[$name]] = $col;
            }
        }
        foreach (self::FALLBACK as $key => $index) {
            $found[$key] ??= $index;
        }
        return $found;
    }

    private function read(Worksheet $sheet, int $col, int $row): mixed
    {
        return ExcelBook::cellValue($sheet->getCell(Coordinate::stringFromColumnIndex($col) . $row));
    }
}
