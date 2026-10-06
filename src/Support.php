<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeInterface;

final class Support
{
    public static function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    public static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }

    public static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
        }
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        $lower = mb_strtolower($text);
        if ($text === '' || in_array($lower, ['nie dotyczy', 'n/d', '-', 'none'], true)) {
            return null;
        }
        $text = substr($text, 0, 10);
        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'Y.m.d'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $text);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed;
            }
        }
        return null;
    }

    public static function iso(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    public static function addMonths(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $monthIndex = ((int) $date->format('n')) - 1 + $months;
        $year = (int) $date->format('Y') + intdiv($monthIndex, 12);
        $month = $monthIndex % 12 + 1;
        $day = min((int) $date->format('j'), (int) cal_days_in_month(CAL_GREGORIAN, $month, $year));
        return $date->setDate($year, $month, $day);
    }

    public static function yearsSince(?DateTimeImmutable $date, ?DateTimeImmutable $asOf = null): ?float
    {
        if ($date === null) {
            return null;
        }
        $asOf ??= self::today();
        $days = ((int) $asOf->format('U') - (int) $date->format('U')) / 86400;
        return round($days / 365.25, 2);
    }

    public static function normNr(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $text = rtrim(trim((string) $value), '.');
        if (str_ends_with($text, '.0')) {
            $head = str_replace('-', '', substr($text, 0, -2));
            if ($head !== '' && ctype_digit($head)) {
                $text = substr($text, 0, -2);
            }
        }
        if (in_array(mb_strtolower($text), ['', '--', '-', 'none', 'nd', 'n/d', 'nie dotyczy'], true)) {
            return '';
        }
        return $text;
    }

    public static function fold(string $text): string
    {
        $text = strtr($text, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
        ]);
        $text = mb_strtolower($text);
        $parts = preg_split('/\s+/', trim($text)) ?: [];
        return implode(' ', array_filter($parts, static fn ($part) => $part !== ''));
    }

    public static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return trim((string) $value);
    }

    public static function latestDate(?DateTimeImmutable ...$dates): ?DateTimeImmutable
    {
        $present = array_values(array_filter($dates));
        if ($present === []) {
            return null;
        }
        usort($present, static fn (DateTimeImmutable $a, DateTimeImmutable $b) => $a <=> $b);
        return $present[array_key_last($present)];
    }
}
