<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * A stopped scheduler or queue worker makes no error of its own, so each one keeps noting the time, and an old time
 * means it has stopped. Before the first note there is nothing to judge, so a new install shows no warning.
 */
class Heartbeat
{
    /**
     * Three missed beats, so one slow job or a deployment doesn't raise a warning.
     */
    private const STALE_AFTER_SECONDS = 15 * 60;

    public static function beat(): void
    {
        Cache::forever('heartbeat.scheduler', now()->timestamp);

        // Starts the worker's clock, so a worker that never starts counts as stopped too.
        Cache::add('heartbeat.worker', now()->timestamp);

        // ponytail: a stopped worker leaves one of these waiting per beat, about 288 rows a day; harmless, and they clear when it restarts.
        dispatch(fn () => Cache::forever('heartbeat.worker', now()->timestamp));
    }

    public static function issue(): ?string
    {
        return match (true) {
            self::isStale('heartbeat.scheduler') => 'The scheduler has stopped, so reminders and daily cleanups are not running.',
            self::isStale('heartbeat.worker') => 'The queue worker has stopped, so emails are not being sent.',
            default => null,
        };
    }

    private static function isStale(string $key): bool
    {
        $time = Cache::get($key);

        return $time !== null && now()->timestamp - $time > self::STALE_AFTER_SECONDS;
    }
}
