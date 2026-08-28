<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Event;

class ConfiguredSchedule
{
    public static function isDisabled(?string $when): bool
    {
        $when = strtolower(trim((string) $when));

        return $when === '' || in_array($when, ['off', 'disabled', 'false', '0', 'none'], true);
    }

    /**
     * Apply a schedule preset or 5-field cron expression from config / .env.
     * Returns null when the task should not be registered (off / disabled).
     */
    public static function apply(Event $event, ?string $when): ?Event
    {
        $when = strtolower(trim((string) $when));

        if (self::isDisabled($when)) {
            return null;
        }

        if (preg_match('/^\S+\s+\S+\s+\S+\s+\S+\s+\S+/', $when) === 1) {
            return $event->cron($when);
        }

        return match ($when) {
            'every_minute' => $event->everyMinute(),
            'every_two_minutes' => $event->everyTwoMinutes(),
            'every_three_minutes' => $event->everyThreeMinutes(),
            'every_five_minutes' => $event->everyFiveMinutes(),
            'every_ten_minutes' => $event->everyTenMinutes(),
            'every_fifteen_minutes' => $event->everyFifteenMinutes(),
            'every_thirty_minutes' => $event->everyThirtyMinutes(),
            'hourly' => $event->hourly(),
            'daily' => $event->daily(),
            default => $event->hourly(),
        };
    }
}
