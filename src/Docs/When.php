<?php

declare(strict_types=1);

namespace App\Docs;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Formats the stored timestamps for display. Revisions/`updated_at`
 * are written as UTC ISO-8601 (`gmdate('c')`); readers are Japanese,
 * so render them in JST. Parsing is defensive: a malformed value
 * degrades to its date-looking prefix rather than throwing inside a
 * page render.
 */
final class When
{
    private const TZ = 'Asia/Tokyo';

    /** ISO-8601 UTC → `YYYY-MM-DD` in JST. */
    public static function date(string $iso): string
    {
        return self::format($iso, 'Y-m-d', 10);
    }

    /** ISO-8601 UTC → `YYYY-MM-DD HH:MM` in JST. */
    public static function dateTime(string $iso): string
    {
        return self::format($iso, 'Y-m-d H:i', 16);
    }

    private static function format(string $iso, string $fmt, int $fallbackLen): string
    {
        try {
            return (new DateTimeImmutable($iso))
                ->setTimezone(new DateTimeZone(self::TZ))
                ->format($fmt);
        } catch (Throwable) {
            return \substr($iso, 0, $fallbackLen);
        }
    }
}
