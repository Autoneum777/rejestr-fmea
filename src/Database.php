<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public const SCHEMA = <<<'SQL'
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS fmea (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nr_fmea TEXT NOT NULL,
    part_numbers TEXT,
    projekt TEXT,
    rodzaj_wyrobu TEXT,
    osoba_wiodaca TEXT,
    last_full_date TEXT,
    next_due_date TEXT,
    warning_date TEXT,
    last_review_date TEXT,
    last_review_type TEXT,
    last_review_reason TEXT,
    aktywne INTEGER NOT NULL DEFAULT 1,
    uwagi TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fmea_id INTEGER NOT NULL,
    review_date TEXT NOT NULL,
    review_type TEXT NOT NULL CHECK (review_type IN ('pelny', 'zdarzeniowy')),
    reason TEXT,
    next_due_after TEXT,
    warning_after TEXT,
    comment TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (fmea_id) REFERENCES fmea(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_reviews_fmea ON reviews(fmea_id, review_date);
CREATE INDEX IF NOT EXISTS idx_fmea_nr ON fmea(nr_fmea);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS control_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nr_planu TEXT NOT NULL,
    nr_fmea TEXT,
    qe TEXT,
    symbol_wyrobu TEXT,
    model TEXT,
    nazwa_wyrobu TEXT,
    nr_rysunku TEXT,
    nr_zmiany TEXT,
    data_zatwierdzenia TEXT,
    nr_rewizji TEXT,
    data_rewizji TEXT,
    status_przegladu TEXT,
    uwagi TEXT,
    linia_mes TEXT,
    data_anulowania TEXT,
    last_full_date TEXT,
    next_due_date TEXT,
    warning_date TEXT,
    last_review_date TEXT,
    last_review_type TEXT,
    last_review_reason TEXT,
    aktywne INTEGER NOT NULL DEFAULT 1,
    wymaga_weryfikacji INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS cp_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plan_id INTEGER NOT NULL,
    review_date TEXT NOT NULL,
    review_type TEXT NOT NULL CHECK (review_type IN ('pelny', 'zdarzeniowy')),
    reason TEXT,
    next_due_after TEXT,
    warning_after TEXT,
    comment TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (plan_id) REFERENCES control_plans(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_cp_fmea ON control_plans(nr_fmea);
CREATE INDEX IF NOT EXISTS idx_cp_reviews ON cp_reviews(plan_id, review_date);

CREATE TABLE IF NOT EXISTS change_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NOT NULL,
    field_label TEXT NOT NULL,
    old_value TEXT,
    new_value TEXT,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_change_log ON change_log(entity_type, entity_id, id);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    imie TEXT NOT NULL,
    nazwisko TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT '',
    dzial TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mail_reminders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NOT NULL,
    kind TEXT NOT NULL,
    due_date TEXT NOT NULL,
    sent_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_mail_reminders ON mail_reminders(entity_type, entity_id, kind, due_date);

CREATE TABLE IF NOT EXISTS mail_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    email TEXT,
    recipient_name TEXT,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    status TEXT NOT NULL,
    error TEXT,
    created_at TEXT NOT NULL,
    sent_at TEXT
);
SQL;

    public const DEFAULT_SETTINGS = [
        'color_overdue' => '#F8D0CB',
        'color_warning' => '#FFF2CC',
        'color_verification' => '#D4E4F7',
        'warning_days' => '30',
    ];

    private static ?self $instance = null;

    public function __construct(public readonly PDO $pdo)
    {
    }

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function dataDir(): string
    {
        return self::root() . DIRECTORY_SEPARATOR . 'data';
    }

    public static function path(): string
    {
        $override = getenv('REJESTR_DB');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        return self::dataDir() . DIRECTORY_SEPARATOR . 'rejestr.db';
    }

    public static function open(): self
    {
        if (self::$instance instanceof self && getenv('REJESTR_DB') === false) {
            return self::$instance;
        }
        if (!is_dir(self::dataDir())) {
            mkdir(self::dataDir(), 0777, true);
        }
        $pdo = new PDO('sqlite:' . self::path());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $db = new self($pdo);
        if (getenv('REJESTR_DB') === false) {
            self::$instance = $db;
        }
        return $db;
    }

    public function init(): void
    {
        $this->pdo->exec(self::SCHEMA);
        $this->ensureColumn('control_plans', 'linia_mes', 'TEXT');
        $this->ensureColumn('control_plans', 'data_anulowania', 'TEXT');
        $this->ensureColumn('control_plans', 'wymaga_weryfikacji', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('users', 'dzial', "TEXT NOT NULL DEFAULT ''");
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
        foreach (self::DEFAULT_SETTINGS as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    }

    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'normalize'], $stmt->fetchAll());
    }

    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $this->normalize($row);
    }

    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function logChanges(string $entityType, int $entityId, array $before, array $after, array $fields): void
    {
        $stamp = Support::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO change_log (entity_type, entity_id, field_label, old_value, new_value, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($fields as [$key, $label]) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($key === 'aktywne') {
                $old = ((int) $old) ? 'Aktywny' : 'Nieaktywny';
                $new = ((int) $new) ? 'Aktywny' : 'Nieaktywny';
            }
            if ((string) ($old ?? '') === (string) ($new ?? '')) {
                continue;
            }
            $stmt->execute([$entityType, $entityId, $label, (string) ($old ?? ''), (string) ($new ?? ''), $stamp]);
        }
    }

    public function settings(): array
    {
        $this->init();
        $data = self::DEFAULT_SETTINGS;
        foreach ($this->all('SELECT key, value FROM settings') as $row) {
            $data[$row['key']] = $row['value'];
        }
        $days = (int) ($data['warning_days'] ?? 30);
        $data['warning_days'] = (string) max(1, $days > 0 ? $days : 30);
        return $data;
    }

    public function saveSettings(array $updates): array
    {
        $this->init();
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        foreach ($updates as $key => $value) {
            if (!array_key_exists($key, self::DEFAULT_SETTINGS)) {
                continue;
            }
            $text = trim((string) $value);
            if (str_starts_with($key, 'color_')) {
                if (!str_starts_with($text, '#')) {
                    $text = '#' . $text;
                }
                if (strlen($text) === 4) {
                    $text = '#' . $text[1] . $text[1] . $text[2] . $text[2] . $text[3] . $text[3];
                }
                if (strlen($text) !== 7) {
                    continue;
                }
                $text = strtoupper($text);
            }
            if ($key === 'warning_days') {
                if (!ctype_digit($text)) {
                    continue;
                }
                $text = (string) max(1, (int) $text);
            }
            $stmt->execute([$key, $text]);
        }
        return $this->settings();
    }

    public function enrich(array $item, ?array $settings = null): array
    {
        $settings ??= $this->settings();
        $warningDays = max(1, (int) ($settings['warning_days'] ?? 30));
        $lastFull = Support::parseDate($item['last_full_date'] ?? null);
        $lastReview = Support::parseDate($item['last_review_date'] ?? null);
        $due = Support::parseDate($item['next_due_date'] ?? null);
        $today = Support::today();
        if ($due !== null && $due < $today) {
            $status = 'przeterminowane';
        } elseif ($due !== null) {
            $days = (int) $today->diff($due)->format('%r%a');
            $status = ($days >= 0 && $days <= $warningDays) ? 'ostrzezenie' : 'aktualne';
        } else {
            $status = 'brak_terminu';
        }
        $item['days_since_full'] = Support::daysSince($lastFull, $today);
        $item['status'] = $status;
        $item['current_fmea_date'] = Support::iso($lastReview ?? $lastFull);
        $item['aktywne'] = (int) ($item['aktywne'] ?? 1);
        return $item;
    }

    private function ensureColumn(string $table, string $column, string $type): void
    {
        $existing = [];
        foreach ($this->pdo->query('PRAGMA table_info(' . $table . ')') as $row) {
            $existing[$row['name']] = true;
        }
        if (!isset($existing[$column])) {
            $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $type);
        }
    }

    private function normalize(array $row): array
    {
        foreach (['id', 'aktywne', 'wymaga_weryfikacji', 'fmea_id', 'plan_id', 'entity_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                $row[$key] = (int) $row[$key];
            }
        }
        return $row;
    }
}
