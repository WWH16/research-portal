<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Run the action and fail if it takes more than the given number of database queries.
     */
    protected function assertQueriesAtMost(int $max, callable $action): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($max, count(DB::getQueryLog()));
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
