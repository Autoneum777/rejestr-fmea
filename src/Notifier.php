<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;

final class Notifier
{
    public function __construct(private readonly Database $db)
    {
    }

    public function fmeaUpdated(array $fmea, string $reviewType, string $reason, DateTimeImmutable $reviewDate): array
    {
        $nr = Support::normNr($fmea['nr_fmea'] ?? '');
        if ($nr === '' || (int) ($fmea['aktywne'] ?? 1) !== 1) {
            return [];
        }
        $plans = $this->db->all(
            'SELECT * FROM control_plans WHERE nr_fmea = ? AND aktywne = 1 ORDER BY nr_planu',
            [$nr]
        );
        $users = $this->db->all('SELECT * FROM users ORDER BY id');
        $groups = [];
        foreach ($plans as $plan) {
            $responsible = trim((string) (($plan['qe'] ?? '') !== '' ? $plan['qe'] : ''));
            if ($responsible === '') {
                continue;
            }
            $names = preg_split('/\s*(?:\/|\n|;)\s*/u', $responsible) ?: [];
            foreach ($names as $name) {
                $name = trim($name);
                if ($name === '') {
                    continue;
                }
                $key = Support::fold($name);
                $groups[$key]['name'] = $name;
                $groups[$key]['plans'][] = (string) $plan['nr_planu'];
            }
        }
        $results = [];
        $nrFmea = (string) $fmea['nr_fmea'];
        foreach ($groups as $key => $group) {
            $lines = [];
            foreach ($group['plans'] as $nrPlanu) {
                $lines[] = "FMEA nr {$nrFmea} zostało zaktualizowane. Przejrzyj CP nr {$nrPlanu}, który jest z nim powiązany.";
            }
            $results[] = $this->deliver($users, $key, $group['name'], 'Aktualizacja FMEA ' . $nrFmea, implode("\n", $lines), $group['plans']);
        }
        return $results;
    }

    public function reminders(): array
    {
        $today = Support::today()->format('Y-m-d');
        $settings = $this->db->settings();
        if (($settings['reminders_ran_on'] ?? '') === $today) {
            return ['skipped' => true, 'queued' => 0];
        }
        $queued = 0;
        foreach ($this->db->all('SELECT * FROM fmea WHERE aktywne = 1 AND next_due_date IS NOT NULL') as $row) {
            $queued += $this->remindDocument('fmea', $this->db->enrich($row, $settings), (string) ($row['osoba_wiodaca'] ?? ''), (string) $row['nr_fmea']);
        }
        foreach ($this->db->all('SELECT * FROM control_plans WHERE aktywne = 1 AND next_due_date IS NOT NULL') as $row) {
            $queued += $this->remindDocument('cp', $this->db->enrich($row, $settings), (string) ($row['qe'] ?? ''), (string) $row['nr_planu']);
        }
        $this->db->exec(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            ['reminders_ran_on', $today]
        );
        return ['skipped' => false, 'queued' => $queued];
    }

    private function remindDocument(string $entityType, array $item, string $responsible, string $number): int
    {
        $kind = (string) ($item['status'] ?? '');
        if (!in_array($kind, ['ostrzezenie', 'przeterminowane'], true)) {
            return 0;
        }
        $due = Support::iso(Support::parseDate($item['next_due_date'] ?? null));
        if ($due === null || $number === '') {
            return 0;
        }
        if ($this->recentlyReminded($entityType, (int) $item['id'], $kind, $due)) {
            return 0;
        }
        $names = $this->personNames($responsible);
        if ($names === []) {
            return 0;
        }
        $date = Support::parseDate($due)?->format('d.m.Y') ?? $due;
        if ($entityType === 'fmea') {
            $subject = $kind === 'ostrzezenie' ? "FMEA nr {$number} kończy ważność" : "FMEA nr {$number} jest przeterminowane";
            $body = $kind === 'ostrzezenie'
                ? "FMEA nr {$number} za miesiąc kończy ważność ({$date}). Przygotuj się do pełnego przeglądu."
                : "FMEA nr {$number} jest przeterminowane. Data ważności: {$date}.";
        } else {
            $subject = $kind === 'ostrzezenie' ? "CP nr {$number} kończy ważność" : "CP nr {$number} jest przeterminowany";
            $body = $kind === 'ostrzezenie'
                ? "CP nr {$number} za miesiąc kończy ważność ({$date}). Przygotuj się do pełnego przeglądu."
                : "CP nr {$number} jest przeterminowany. Data ważności: {$date}.";
        }
        $users = $this->db->all('SELECT * FROM users ORDER BY id');
        $queued = 0;
        foreach ($names as $name) {
            $this->deliver($users, Support::fold($name), $name, $subject, $body, []);
            $queued++;
        }
        $this->db->exec(
            'INSERT INTO mail_reminders (entity_type, entity_id, kind, due_date, sent_at) VALUES (?, ?, ?, ?, ?)',
            [$entityType, (int) $item['id'], $kind, $due, Support::now()]
        );
        return $queued;
    }

    private function recentlyReminded(string $entityType, int $id, string $kind, string $due): bool
    {
        $row = $this->db->one(
            'SELECT sent_at FROM mail_reminders
             WHERE entity_type = ? AND entity_id = ? AND kind = ? AND due_date = ?
             ORDER BY sent_at DESC LIMIT 1',
            [$entityType, $id, $kind, $due]
        );
        if ($row === null) {
            return false;
        }
        if ($kind === 'ostrzezenie') {
            return true;
        }
        $sent = Support::parseDate($row['sent_at']);
        if ($sent === null) {
            return true;
        }
        return (int) $sent->diff(Support::today())->format('%r%a') < 7;
    }

    /** @return list<string> */
    private function personNames(string $raw): array
    {
        $names = [];
        foreach (preg_split('/\s*(?:\/|\n|;)\s*/u', trim($raw)) ?: [] as $name) {
            $name = trim($name);
            if ($name !== '' && Support::fold($name) !== 'glowny') {
                $names[] = $name;
            }
        }
        return $names;
    }

    private function deliver(array $users, string $foldedName, string $name, string $subject, string $body, array $plans): array
    {
        $user = $this->matchUser($users, $foldedName);
        $email = trim((string) ($user['email'] ?? ''));
        $status = 'oczekuje';
        $error = null;
        if ($user === null) {
            $status = 'brak_adresu';
            $error = 'Brak użytkownika pasującego do osoby: ' . $name;
        } elseif ($email === '') {
            $status = 'brak_email';
            $error = 'Brak adresu e-mail';
        } else {
            $error = (new Mailer())->send($email, $subject, $body);
            $status = $error === null ? 'wyslane' : 'oczekuje';
        }
        $stamp = Support::now();
        $this->db->exec(
            'INSERT INTO mail_outbox (
                user_id, email, recipient_name, subject, body, status, error, created_at, sent_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $user['id'] ?? null,
                $email !== '' ? $email : null,
                $name,
                $subject,
                $body,
                $status,
                $error,
                $stamp,
                $status === 'wyslane' ? $stamp : null,
            ]
        );
        return [
            'name' => $name,
            'email' => $email,
            'plans' => $plans,
            'status' => $status,
            'error' => $error,
        ];
    }

    private function matchUser(array $users, string $foldedName): ?array
    {
        foreach ($users as $user) {
            foreach ($this->aliases($user) as $alias) {
                if ($alias === $foldedName) {
                    return $user;
                }
            }
        }
        return null;
    }

    private function aliases(array $user): array
    {
        $first = trim((string) $user['imie']);
        $last = trim((string) $user['nazwisko']);
        $dotted = str_ends_with($first, '.') ? $first : (mb_substr($first, 0, 1) . '.');
        return array_values(array_unique(array_filter([
            Support::fold($first . ' ' . $last),
            Support::fold($last . ' ' . $first),
            Support::fold($dotted . ' ' . $last),
            Support::fold($dotted . $last),
            Support::fold(mb_substr($first, 0, 1) . ' ' . $last),
        ])));
    }
}
