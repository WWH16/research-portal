<?php

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    public function test_tests_run_only_on_a_database_named_for_testing(): void
    {
        foreach (['research_portal_test', 'portal_testing'] as $database) {
            TestCase::refuseRealDatabase($database);
        }

        foreach (['research_portal', 'test_portal', 'latest', ':memory:'] as $database) {
            $this->assertThrows(fn () => TestCase::refuseRealDatabase($database), RuntimeException::class, $database);
        }
    }
}
