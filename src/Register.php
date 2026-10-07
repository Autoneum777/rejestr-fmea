<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;

final class Register
{
    private const FMEA_SEARCH = [
        'nr_fmea', 'part_numbers', 'projekt', 'rodzaj_wyrobu', 'osoba_wiodaca', 'last_review_reason',
    ];

    private const CP_TRACKED = [
        ['nr_planu', 'Plan kontroli nr'],
        ['nr_fmea', 'Numer FMEA'],
        ['qe', 'Odpowiedzialny QE'],
        ['symbol_wyrobu', 'Symbol wyrobu'],
        ['model', 'Model samochodu'],
        ['nr_rysunku', 'Nr rysunku lub części'],
        ['linia_mes', 'Linia MES'],
        ['nazwa_wyrobu', 'Nazwa wyrobu'],
        ['nr_zmiany', 'Nr zmiany'],
        ['data_zatwierdzenia', 'Data zatwierdzenia'],
        ['nr_rewizji', 'Nr rewizji'],
        ['uwagi', 'Uwagi'],
        ['data_anulowania', 'Data anulowania'],
        ['status_przegladu', 'Status przeglądu'],
        ['aktywne', 'Status aktywności'],
    ];

    private int $addedUsers = 0;

    /** @var list<array<string, mixed>>|null */
    private ?array $userCache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function addedUsers(): int
    {
        return $this->addedUsers;
    }

    public function rememberResponsible(string $raw): string
    {
        $raw = trim(preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\r"], "\n", $raw)) ?? '');
        if ($raw === '') {
            return '';
        }
        $parts = preg_split('/\s*(?:\/|\n|;)\s*/u', $raw) ?: [];
        $names = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $person = $this->splitPerson($part);
            if ($person === null) {
                $names[] = $part;
                continue;
            }
            $names[] = $this->ensureUser($person['imie'], $person['nazwisko']);
        }
        return implode(' / ', $names);
    }

    public function listFmea(string $q, string $status, string $aktywne): array
    {
        [$items, $active, $inactive, $settings] = $this->filteredFmea($q, $status, $aktywne);
        return [
            'items' => $items,
            'summary' => $this->summary($active, $inactive),
            'settings' => $settings,
        ];
    }

    public function getFmea(int $id): array
    {
        $row = $this->db->one('SELECT * FROM fmea WHERE id = ?', [$id]);
        if ($row === null) {
            Http::json(['error' => 'Nie znaleziono FMEA'], 404);
        }
        return [
            'item' => $this->db->enrich($row),
            'reviews' => $this->db->all(
                'SELECT * FROM reviews WHERE fmea_id = ? ORDER BY review_date DESC, id DESC',
                [$id]
            ),
            'control_plans' => $this->db->all(
                'SELECT id, nr_planu, symbol_wyrobu, model, next_due_date, aktywne
                 FROM control_plans WHERE nr_fmea = ? ORDER BY nr_planu',
                [Support::normNr($row['nr_fmea'])]
            ),
            'changes' => $this->changes('fmea', $id),
        ];
    }

    public function createFmea(array $data): array
    {
        $nr = Support::text($data['nr_fmea'] ?? '');
        if ($nr === '') {
            Http::json(['error' => 'Podaj numer FMEA'], 400);
        }
        $fmeaDate = Support::parseDate($data['fmea_date'] ?? null);
        if ($fmeaDate === null) {
            Http::json(['error' => 'Podaj datę FMEA'], 400);
        }
        $reason = Support::text($data['reason'] ?? '') ?: 'Wydanie FMEA';
        $nextDue = Support::addMonths($fmeaDate, 12);
        $warning = Support::addMonths($fmeaDate, 11);
        $stamp = Support::now();
        $this->db->pdo->beginTransaction();
        $this->db->exec(
            'INSERT INTO fmea (
                nr_fmea, part_numbers, projekt, rodzaj_wyrobu, osoba_wiodaca,
                last_full_date, next_due_date, warning_date,
                last_review_date, last_review_type, last_review_reason,
                aktywne, uwagi, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'pelny\', ?, 1, ?, ?, ?)',
            [
                $nr,
                Support::text($data['part_numbers'] ?? ''),
                Support::text($data['projekt'] ?? ''),
                Support::text($data['rodzaj_wyrobu'] ?? ''),
                $this->rememberResponsible(Support::text($data['osoba_wiodaca'] ?? '')),
                Support::iso($fmeaDate),
                Support::iso($nextDue),
                Support::iso($warning),
                Support::iso($fmeaDate),
                $reason,
                Support::text($data['uwagi'] ?? ''),
                $stamp,
                $stamp,
            ]
        );
        $id = $this->db->lastId();
        $this->db->exec(
            'INSERT INTO reviews (
                fmea_id, review_date, review_type, reason,
                next_due_after, warning_after, comment, created_at
            ) VALUES (?, ?, \'pelny\', ?, ?, ?, \'\', ?)',
            [$id, Support::iso($fmeaDate), $reason, Support::iso($nextDue), Support::iso($warning), $stamp]
        );
        $row = $this->db->one('SELECT * FROM fmea WHERE id = ?', [$id]);
        $this->db->pdo->commit();
        return ['item' => $this->db->enrich($row ?? [])];
    }

    public function updateFmea(int $id, array $data): array
    {
        $fields = [
            'nr_fmea' => Support::text($data['nr_fmea'] ?? ''),
            'part_numbers' => Support::text($data['part_numbers'] ?? ''),
            'projekt' => Support::text($data['projekt'] ?? ''),
            'rodzaj_wyrobu' => Support::text($data['rodzaj_wyrobu'] ?? ''),
            'osoba_wiodaca' => Support::text($data['osoba_wiodaca'] ?? ''),
            'uwagi' => Support::text($data['uwagi'] ?? ''),
        ];
        if ($fields['nr_fmea'] === '') {
            Http::json(['error' => 'Podaj numer FMEA'], 400);
        }
        $before = $this->db->one('SELECT * FROM fmea WHERE id = ?', [$id]);
        if ($before === null) {
            Http::json(['error' => 'Nie znaleziono FMEA'], 404);
        }
        $this->db->pdo->beginTransaction();
        $fields['osoba_wiodaca'] = $this->rememberResponsible($fields['osoba_wiodaca']);
        $this->db->logChanges('fmea', $id, $before, $fields, [
            ['nr_fmea', 'Nr FMEA'],
            ['part_numbers', 'Wyroby gotowe'],
            ['projekt', 'Projekt'],
            ['rodzaj_wyrobu', 'Rodzaj wyrobu'],
            ['osoba_wiodaca', 'Osoba wiodąca'],
            ['uwagi', 'Uwagi'],
        ]);
        $this->db->exec(
            'UPDATE fmea
             SET nr_fmea=?, part_numbers=?, projekt=?, rodzaj_wyrobu=?,
                 osoba_wiodaca=?, uwagi=?, updated_at=?
             WHERE id=?',
            [...array_values($fields), Support::now(), $id]
        );
        $row = $this->db->one('SELECT * FROM fmea WHERE id = ?', [$id]);
        $this->db->pdo->commit();
        return ['item' => $this->db->enrich($row ?? [])];
    }

    public function toggleFmea(int $id, array $data): array
    {
        $aktywne = empty($data['aktywne']) ? 0 : 1;
        $before = $this->db->one('SELECT * FROM fmea WHERE id = ?', [$id]);
        if ($before === null) {
            Http::json(['error' => 'Nie znaleziono FMEA'], 404);
        }
        $this->db->pdo->beginTransaction();
        $this->db->logChanges('fmea', $id, $before, ['aktywne' => $aktywne], [['aktywne', 'Status aktywności']]);
        if ($aktywne === 0 && (int) $before['aktywne']) {
            $linked = $this->db->all(
                'SELECT id, nr_planu FROM control_plans WHERE nr_fmea = ?',
                [Support::normNr($before['nr_fmea'])]
            );
            foreach ($linked as $plan) {
                $this->db->logChanges(
                    'cp',
                    (int) $plan['id'],
                    ['referencja' => 'aktywna'],
                    ['referencja' => 'dezaktywowana'],
                    [['referencja', 'Powiązane FMEA ' . $before['nr_fmea']]]
                );
            }
        }
        $this->db->exec('UPDATE fmea SET aktywne = ?, updated_at = ? WHERE id = ?', [$aktywne, Support::now(), $id]);
        $row = $this->db->one('SELECT * FROM fmea WHERE id = ?', [$id]);
        $this->db->pdo->commit();
        return ['item' => $this->db->enrich($row ?? [])];
    }

    public function deleteFmea(int $id, array $data): array
    {
        $confirm = Support::text($data['confirm'] ?? '');
        $row = $this->db->one('SELECT * FROM fmea WHERE id = ?', [$id]);
        if ($row === null) {
            Http::json(['error' => 'Nie znaleziono FMEA'], 404);
        }
        $nr = trim((string) $row['nr_fmea']);
        if ($confirm !== $nr) {
            Http::json(['error' => 'Aby usunąć, wpisz numer FMEA: ' . $nr], 400);
        }
        $this->db->pdo->beginTransaction();
        $this->db->exec('DELETE FROM reviews WHERE fmea_id = ?', [$id]);
        $this->db->exec('DELETE FROM fmea WHERE id = ?', [$id]);
        $this->db->pdo->commit();
        return ['ok' => true, 'nr_fmea' => $nr];
    }

    public function addFmeaReview(int $id, array $data): array
    {
        [$reviewType, $reviewDate, $reason] = $this->reviewInput($data, 'Podaj datę przeglądu FMEA');
        $comment = Support::text($data['comment'] ?? '');
        $item = $this->db->one('SELECT * FROM fmea WHERE id = ?', [$id]);
        if ($item === null) {
            Http::json(['error' => 'Nie znaleziono FMEA'], 404);
        }
        [$lastFull, $nextDue, $warning] = $this->deadlines($reviewType, $reviewDate, $item);
        $stamp = Support::now();
        $this->db->pdo->beginTransaction();
        $this->db->exec(
            'INSERT INTO reviews (
                fmea_id, review_date, review_type, reason,
                next_due_after, warning_after, comment, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, Support::iso($reviewDate), $reviewType, $reason, Support::iso($nextDue), Support::iso($warning), $comment, $stamp]
        );
        $this->db->exec(
            'UPDATE fmea
             SET last_full_date=?, next_due_date=?, warning_date=?,
                 last_review_date=?, last_review_type=?, last_review_reason=?, updated_at=?
             WHERE id=?',
            [Support::iso($lastFull), Support::iso($nextDue), Support::iso($warning), Support::iso($reviewDate), $reviewType, $reason, $stamp, $id]
        );
        $row = $this->db->one('SELECT * FROM fmea WHERE id = ?', [$id]);
        $reviews = $this->db->all('SELECT * FROM reviews WHERE fmea_id = ? ORDER BY review_date DESC, id DESC', [$id]);
        $this->db->pdo->commit();
        $notifications = (new Notifier($this->db))->fmeaUpdated($row ?? [], $reviewType, $reason, $reviewDate);
        return ['item' => $this->db->enrich($row ?? []), 'reviews' => $reviews, 'notifications' => $notifications];
    }

    public function listUsers(): array
    {
        return [
            'items' => $this->db->all('SELECT * FROM users ORDER BY nazwisko, imie'),
            'mail' => (new Mailer())->status(),
        ];
    }

    public function saveUser(?int $id, array $data): array
    {
        $imie = Support::text($data['imie'] ?? '');
        $nazwisko = Support::text($data['nazwisko'] ?? '');
        $email = Support::text($data['email'] ?? '');
        $dzial = strtoupper(Support::text($data['dzial'] ?? ''));
        if ($imie === '' || $nazwisko === '') {
            Http::json(['error' => 'Podaj imię i nazwisko'], 400);
        }
        if (!in_array($dzial, ['QD', 'PED'], true)) {
            Http::json(['error' => 'Wybierz dział: QD albo PED'], 400);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Http::json(['error' => 'Podaj prawidłowy adres e-mail'], 400);
        }
        $duplicate = $this->findUser($imie, $nazwisko);
        if ($duplicate !== null && (int) $duplicate['id'] !== (int) $id) {
            Http::json(['error' => 'Użytkownik o tym imieniu i nazwisku już jest'], 400);
        }
        $stamp = Support::now();
        if ($id === null) {
            $this->db->exec(
                'INSERT INTO users (imie, nazwisko, email, dzial, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$imie, $nazwisko, $email, $dzial, $stamp, $stamp]
            );
            $id = $this->db->lastId();
        } else {
            $existing = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
            if ($existing === null) {
                Http::json(['error' => 'Nie znaleziono użytkownika'], 404);
            }
            $this->db->exec(
                'UPDATE users SET imie = ?, nazwisko = ?, email = ?, dzial = ?, updated_at = ? WHERE id = ?',
                [$imie, $nazwisko, $email, $dzial, $stamp, $id]
            );
        }
        $this->userCache = null;
        return ['item' => $this->db->one('SELECT * FROM users WHERE id = ?', [$id])];
    }

    public function deleteUser(int $id): array
    {
        $existing = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($existing === null) {
            Http::json(['error' => 'Nie znaleziono użytkownika'], 404);
        }
        $this->db->exec('DELETE FROM users WHERE id = ?', [$id]);
        return ['ok' => true];
    }

    public function assignResponsible(string $kind, array $data): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($data['ids'] ?? [])))));
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [(int) ($data['user_id'] ?? 0)]);
        if ($ids === []) {
            Http::json(['error' => 'Zaznacz co najmniej jedną pozycję'], 400);
        }
        if ($user === null) {
            Http::json(['error' => 'Wybierz użytkownika'], 400);
        }
        $name = trim($user['imie'] . ' ' . $user['nazwisko']);
        $updated = 0;
        $this->db->pdo->beginTransaction();
        foreach ($ids as $id) {
            if ($kind === 'fmea') {
                $before = $this->db->one('SELECT * FROM fmea WHERE id = ?', [$id]);
                if ($before === null) {
                    continue;
                }
                $this->db->logChanges('fmea', $id, $before, ['osoba_wiodaca' => $name], [['osoba_wiodaca', 'Osoba wiodąca']]);
                $this->db->exec('UPDATE fmea SET osoba_wiodaca = ?, updated_at = ? WHERE id = ?', [$name, Support::now(), $id]);
                $updated++;
            } else {
                $before = $this->db->one('SELECT * FROM control_plans WHERE id = ?', [$id]);
                if ($before === null) {
                    continue;
                }
                $this->db->logChanges('cp', $id, $before, ['qe' => $name], [['qe', 'Odpowiedzialny QE']]);
                $this->db->exec('UPDATE control_plans SET qe = ?, updated_at = ? WHERE id = ?', [$name, Support::now(), $id]);
                $updated++;
            }
        }
        $this->db->pdo->commit();
        return ['updated' => $updated, 'name' => $name];
    }

    public function exportFmea(string $q, string $status, string $aktywne): string
    {
        [$items, , , $settings] = $this->filteredFmea($q, $status, $aktywne);
        $ids = array_column($items, 'id');
        $history = [];
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $history = $this->db->all(
                "SELECT f.nr_fmea, f.projekt, r.review_date, r.review_type, r.reason, r.next_due_after, r.comment
                 FROM reviews r JOIN fmea f ON f.id = r.fmea_id
                 WHERE r.fmea_id IN ($marks)
                 ORDER BY CAST(f.nr_fmea AS INTEGER), f.nr_fmea, r.review_date DESC",
                $ids
            );
        }
        return (new ExcelExport())->fmea($items, $history, ['q' => $q, 'status' => $status], $settings);
    }

    public function listCp(string $q, string $status, string $aktywne): array
    {
        [$items, $active, $inactive, $settings] = $this->filteredPlans($q, $status, $aktywne);
        $summary = $this->summary($active, $inactive);
        $summary['powiazane'] = count(array_filter($active, static fn (array $item) => !empty($item['fmea_linked'])));
        return ['items' => $items, 'summary' => $summary, 'settings' => $settings];
    }

    public function getCp(int $id): array
    {
        $people = $this->fmeaDirectory();
        $row = $this->db->one('SELECT * FROM control_plans WHERE id = ?', [$id]);
        if ($row === null) {
            Http::json(['error' => 'Nie znaleziono planu kontroli'], 404);
        }
        return [
            'item' => $this->cpPayload($row, $people),
            'reviews' => $this->db->all(
                'SELECT * FROM cp_reviews WHERE plan_id = ? ORDER BY review_date DESC, id DESC',
                [$id]
            ),
            'changes' => $this->changes('cp', $id),
        ];
    }

    public function createCp(array $data): array
    {
        $nr = Support::text($data['nr_planu'] ?? '');
        if ($nr === '') {
            Http::json(['error' => 'Podaj numer planu kontroli'], 400);
        }
        $reviewDate = Support::parseDate($data['data_rewizji'] ?? null);
        if ($reviewDate === null) {
            Http::json(['error' => 'Podaj datę rewizji'], 400);
        }
        $reason = Support::text($data['reason'] ?? '');
        if ($reason === '') {
            Http::json(['error' => 'Podaj powód rewizji'], 400);
        }
        $status = Support::text($data['status_przegladu'] ?? '') ?: 'OK';
        $nextDue = Support::addMonths($reviewDate, 12);
        $warning = Support::addMonths($reviewDate, 11);
        $stamp = Support::now();
        $aktywne = $this->activeStatus($status) ? 1 : 0;
        $this->db->pdo->beginTransaction();
        $this->db->exec(
            'INSERT INTO control_plans (
                nr_planu, nr_fmea, qe, symbol_wyrobu, model, nazwa_wyrobu,
                nr_rysunku, nr_zmiany, data_zatwierdzenia, nr_rewizji, data_rewizji,
                status_przegladu, uwagi, linia_mes, data_anulowania,
                last_full_date, next_due_date, warning_date,
                last_review_date, last_review_type, last_review_reason,
                aktywne, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pelny\', ?, ?, ?, ?)',
            [
                $nr,
                Support::normNr($data['nr_fmea'] ?? ''),
                $this->rememberResponsible(Support::text($data['qe'] ?? '')),
                Support::text($data['symbol_wyrobu'] ?? ''),
                Support::text($data['model'] ?? ''),
                Support::text($data['nazwa_wyrobu'] ?? ''),
                Support::text($data['nr_rysunku'] ?? ''),
                Support::text($data['nr_zmiany'] ?? ''),
                Support::iso(Support::parseDate($data['data_zatwierdzenia'] ?? null)),
                Support::text($data['nr_rewizji'] ?? ''),
                Support::iso($reviewDate),
                $status,
                Support::text($data['uwagi'] ?? ''),
                Support::text($data['linia_mes'] ?? ''),
                Support::iso(Support::parseDate($data['data_anulowania'] ?? null)),
                Support::iso($reviewDate),
                Support::iso($nextDue),
                Support::iso($warning),
                Support::iso($reviewDate),
                $reason,
                $aktywne,
                $stamp,
                $stamp,
            ]
        );
        $id = $this->db->lastId();
        $this->db->exec(
            'INSERT INTO cp_reviews (
                plan_id, review_date, review_type, reason,
                next_due_after, warning_after, comment, created_at
            ) VALUES (?, ?, \'pelny\', ?, ?, ?, \'\', ?)',
            [$id, Support::iso($reviewDate), $reason, Support::iso($nextDue), Support::iso($warning), $stamp]
        );
        $this->db->logChanges('cp', $id, [], ['nr_planu' => $nr], [['nr_planu', 'Utworzenie planu']]);
        $row = $this->db->one('SELECT * FROM control_plans WHERE id = ?', [$id]);
        $this->db->pdo->commit();
        return ['item' => $this->cpPayload($row ?? [], $this->fmeaDirectory())];
    }

    public function updateCp(int $id, array $data): array
    {
        $fields = [
            'nr_planu' => Support::text($data['nr_planu'] ?? ''),
            'nr_fmea' => Support::normNr($data['nr_fmea'] ?? ''),
            'qe' => Support::text($data['qe'] ?? ''),
            'symbol_wyrobu' => Support::text($data['symbol_wyrobu'] ?? ''),
            'model' => Support::text($data['model'] ?? ''),
            'nr_rysunku' => Support::text($data['nr_rysunku'] ?? ''),
            'linia_mes' => Support::text($data['linia_mes'] ?? ''),
            'nazwa_wyrobu' => Support::text($data['nazwa_wyrobu'] ?? ''),
            'nr_zmiany' => Support::text($data['nr_zmiany'] ?? ''),
            'data_zatwierdzenia' => Support::iso(Support::parseDate($data['data_zatwierdzenia'] ?? null)) ?? '',
            'nr_rewizji' => Support::text($data['nr_rewizji'] ?? ''),
            'uwagi' => Support::text($data['uwagi'] ?? ''),
            'data_anulowania' => Support::iso(Support::parseDate($data['data_anulowania'] ?? null)) ?? '',
            'status_przegladu' => Support::text($data['status_przegladu'] ?? ''),
        ];
        if ($fields['nr_planu'] === '') {
            Http::json(['error' => 'Podaj numer planu kontroli'], 400);
        }
        $fields['aktywne'] = $this->activeStatus($fields['status_przegladu']) ? 1 : 0;
        $before = $this->db->one('SELECT * FROM control_plans WHERE id = ?', [$id]);
        if ($before === null) {
            Http::json(['error' => 'Nie znaleziono planu kontroli'], 404);
        }
        $this->db->pdo->beginTransaction();
        $fields['qe'] = $this->rememberResponsible($fields['qe']);
        $this->db->logChanges('cp', $id, $before, $fields, self::CP_TRACKED);
        $this->db->exec(
            'UPDATE control_plans
             SET nr_planu=?, nr_fmea=?, qe=?, symbol_wyrobu=?, model=?, nr_rysunku=?,
                 linia_mes=?, nazwa_wyrobu=?, nr_zmiany=?, data_zatwierdzenia=?,
                 nr_rewizji=?, uwagi=?, data_anulowania=?, status_przegladu=?,
                 aktywne=?, updated_at=?
             WHERE id=?',
            [
                $fields['nr_planu'], $fields['nr_fmea'], $fields['qe'], $fields['symbol_wyrobu'],
                $fields['model'], $fields['nr_rysunku'], $fields['linia_mes'], $fields['nazwa_wyrobu'],
                $fields['nr_zmiany'], $fields['data_zatwierdzenia'] ?: null, $fields['nr_rewizji'],
                $fields['uwagi'], $fields['data_anulowania'] ?: null, $fields['status_przegladu'],
                $fields['aktywne'], Support::now(), $id,
            ]
        );
        $row = $this->db->one('SELECT * FROM control_plans WHERE id = ?', [$id]);
        $this->db->pdo->commit();
        return ['item' => $this->cpPayload($row ?? [], $this->fmeaDirectory())];
    }

    public function addCpReview(int $id, array $data): array
    {
        [$reviewType, $reviewDate, $reason] = $this->reviewInput($data, 'Podaj datę rewizji');
        $item = $this->db->one('SELECT * FROM control_plans WHERE id = ?', [$id]);
        if ($item === null) {
            Http::json(['error' => 'Nie znaleziono planu kontroli'], 404);
        }
        [$lastFull, $nextDue, $warning] = $this->deadlines($reviewType, $reviewDate, $item);
        $stamp = Support::now();
        $this->db->pdo->beginTransaction();
        $this->db->exec(
            'INSERT INTO cp_reviews (
                plan_id, review_date, review_type, reason,
                next_due_after, warning_after, comment, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, \'\', ?)',
            [$id, Support::iso($reviewDate), $reviewType, $reason, Support::iso($nextDue), Support::iso($warning), $stamp]
        );
        $this->db->exec(
            'UPDATE control_plans
             SET data_rewizji = ?, last_full_date = ?, next_due_date = ?, warning_date = ?,
                 last_review_date = ?, last_review_type = ?, last_review_reason = ?, updated_at = ?
             WHERE id = ?',
            [Support::iso($reviewDate), Support::iso($lastFull), Support::iso($nextDue), Support::iso($warning), Support::iso($reviewDate), $reviewType, $reason, $stamp, $id]
        );
        $row = $this->db->one('SELECT * FROM control_plans WHERE id = ?', [$id]);
        $this->db->pdo->commit();
        return ['item' => $this->cpPayload($row ?? [], $this->fmeaDirectory())];
    }

    public function exportCp(string $q, string $status, string $aktywne): string
    {
        [$items, , , $settings] = $this->filteredPlans($q, $status, $aktywne);
        $ids = array_column($items, 'id');
        $history = [];
        $changes = [];
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $history = $this->db->all(
                "SELECT p.nr_planu, p.nr_fmea, r.review_date, r.review_type, r.reason, r.next_due_after
                 FROM cp_reviews r JOIN control_plans p ON p.id = r.plan_id
                 WHERE r.plan_id IN ($marks)
                 ORDER BY p.nr_planu, r.review_date DESC",
                $ids
            );
            $changes = $this->db->all(
                "SELECT p.nr_planu, c.field_label, c.old_value, c.new_value, c.created_at
                 FROM change_log c JOIN control_plans p ON p.id = c.entity_id
                 WHERE c.entity_type = 'cp' AND c.entity_id IN ($marks)
                 ORDER BY c.id DESC",
                $ids
            );
        }
        return (new ExcelExport())->controlPlans($items, $history, $changes, ['q' => $q, 'status' => $status], $settings);
    }

    private function filteredFmea(string $q, string $status, string $aktywne): array
    {
        $settings = $this->db->settings();
        $items = array_map(
            fn (array $row) => $this->db->enrich($row, $settings),
            $this->db->all('SELECT * FROM fmea ORDER BY CAST(nr_fmea AS INTEGER), nr_fmea')
        );
        if ($q !== '') {
            $items = array_values(array_filter($items, fn (array $item) => str_contains($this->haystack($item, self::FMEA_SEARCH), $q)));
        }
        return [...$this->splitActivity($items, $status, $aktywne), $settings];
    }

    private function filteredPlans(string $q, string $status, string $aktywne): array
    {
        $settings = $this->db->settings();
        $people = $this->fmeaDirectory();
        $items = array_map(
            fn (array $row) => $this->cpPayload($row, $people, $settings),
            $this->db->all('SELECT * FROM control_plans ORDER BY nr_planu COLLATE NOCASE')
        );
        if ($q !== '') {
            $items = array_values(array_filter(
                $items,
                fn (array $item) => str_contains($this->haystack($item, ['nr_planu', 'nr_fmea', 'osoba_wiodaca', 'symbol_wyrobu', 'model', 'nazwa_wyrobu']), $q)
            ));
        }
        return [...$this->splitActivity($items, $status, $aktywne), $settings];
    }

    private function splitActivity(array $items, string $status, string $aktywne): array
    {
        $active = array_values(array_filter($items, static fn (array $item) => (int) ($item['aktywne'] ?? 1) === 1));
        $inactive = array_values(array_filter($items, static fn (array $item) => (int) ($item['aktywne'] ?? 1) !== 1));
        if ($aktywne === '0') {
            $items = $inactive;
        } elseif ($aktywne !== 'all') {
            $items = $active;
        }
        if ($status !== '') {
            $items = array_values(array_filter($items, static fn (array $item) => $item['status'] === $status));
        }
        return [$items, $active, $inactive];
    }

    private function summary(array $active, array $inactive): array
    {
        return [
            'total' => count($active),
            'przeterminowane' => count(array_filter($active, static fn (array $item) => $item['status'] === 'przeterminowane')),
            'ostrzezenie' => count(array_filter($active, static fn (array $item) => $item['status'] === 'ostrzezenie')),
            'aktualne' => count(array_filter($active, static fn (array $item) => $item['status'] === 'aktualne')),
            'nieaktywne' => count($inactive),
        ];
    }

    private function haystack(array $item, array $keys): string
    {
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = (string) ($item[$key] ?? '');
        }
        return mb_strtolower(implode(' ', $parts));
    }

    private function fmeaDirectory(): array
    {
        $people = [];
        foreach ($this->db->all('SELECT nr_fmea, osoba_wiodaca, projekt FROM fmea') as $row) {
            $nr = Support::normNr($row['nr_fmea']);
            if ($nr === '') {
                continue;
            }
            $people[$nr] = [
                'osoba_wiodaca' => $row['osoba_wiodaca'] ?: '',
                'projekt' => $row['projekt'] ?: '',
            ];
        }
        return $people;
    }

    private function cpPayload(array $row, array $people, ?array $settings = null): array
    {
        $item = $this->db->enrich($row, $settings);
        $linked = $people[Support::normNr($item['nr_fmea'] ?? '')] ?? null;
        $item['fmea_linked'] = $linked !== null;
        $item['fmea_projekt'] = $linked['projekt'] ?? '';
        $item['osoba_wiodaca'] = ($item['qe'] ?? '') !== '' ? $item['qe'] : ($linked['osoba_wiodaca'] ?? '');
        return $item;
    }

    private function changes(string $type, int $id): array
    {
        return $this->db->all(
            'SELECT field_label, old_value, new_value, created_at
             FROM change_log WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC',
            [$type, $id]
        );
    }

    private function reviewInput(array $data, string $dateError): array
    {
        $type = Support::text($data['review_type'] ?? '');
        if (!in_array($type, ['pelny', 'zdarzeniowy'], true)) {
            Http::json(['error' => 'Wybierz typ: przegląd pełny albo zdarzeniowy'], 400);
        }
        $date = Support::parseDate($data['review_date'] ?? null);
        if ($date === null) {
            Http::json(['error' => $dateError], 400);
        }
        $reason = Support::text($data['reason'] ?? '');
        if ($reason === '') {
            Http::json(['error' => 'Podaj powód rewizji'], 400);
        }
        return [$type, $date, $reason];
    }

    /** @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable, 2: ?DateTimeImmutable} */
    private function deadlines(string $type, DateTimeImmutable $reviewDate, array $item): array
    {
        if ($type === 'pelny') {
            return [$reviewDate, Support::addMonths($reviewDate, 12), Support::addMonths($reviewDate, 11)];
        }
        return [
            Support::parseDate($item['last_full_date'] ?? null),
            Support::parseDate($item['next_due_date'] ?? null),
            Support::parseDate($item['warning_date'] ?? null),
        ];
    }

    private function activeStatus(string $status): bool
    {
        return in_array(Support::fold($status), ['ok', 'weryfikacja opozniona'], true);
    }

    /** @return array{imie: string, nazwisko: string}|null */
    private function splitPerson(string $part): ?array
    {
        $part = trim(preg_replace('/\s+/u', ' ', $part) ?? '');
        if ($part === '' || Support::fold($part) === 'glowny') {
            return null;
        }
        if (!str_contains($part, ' ')) {
            $part = preg_replace('/\.(?=\p{L})/u', '. ', $part, 1) ?? $part;
        }
        if (!preg_match('/^(\S+)\s+(.+)$/u', $part, $match)) {
            return null;
        }
        $imie = $match[1];
        $nazwisko = trim($match[2]);
        if (!preg_match('/\p{L}/u', $imie) || $nazwisko === '' || !preg_match('/\p{L}/u', $nazwisko)) {
            return null;
        }
        return ['imie' => $imie, 'nazwisko' => $nazwisko];
    }

    private function ensureUser(string $imie, string $nazwisko): string
    {
        $existing = $this->findUser($imie, $nazwisko);
        if ($existing !== null) {
            return trim($existing['imie'] . ' ' . $existing['nazwisko']);
        }
        $stamp = Support::now();
        $this->db->exec(
            'INSERT INTO users (imie, nazwisko, email, dzial, created_at, updated_at) VALUES (?, ?, \'\', \'\', ?, ?)',
            [$imie, $nazwisko, $stamp, $stamp]
        );
        $this->addedUsers++;
        $this->userCache[] = [
            'id' => $this->db->lastId(),
            'imie' => $imie,
            'nazwisko' => $nazwisko,
            'email' => '',
            'dzial' => '',
        ];
        return trim($imie . ' ' . $nazwisko);
    }

    private function findUser(string $imie, string $nazwisko): ?array
    {
        $key = Support::fold($imie . ' ' . $nazwisko);
        foreach ($this->users() as $user) {
            if (Support::fold($user['imie'] . ' ' . $user['nazwisko']) === $key) {
                return $user;
            }
        }
        return null;
    }

    /** @return list<array<string, mixed>> */
    private function users(): array
    {
        if ($this->userCache === null) {
            $this->userCache = $this->db->all('SELECT * FROM users');
        }
        return $this->userCache;
    }
}
