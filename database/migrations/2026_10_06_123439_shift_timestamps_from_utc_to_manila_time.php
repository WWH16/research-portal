<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The app timezone moved from UTC to Asia/Manila. Laravel saves times in the app timezone, so rows written
 * before the switch hold UTC times that would now read 8 hours early. This moves them forward to Manila time.
 * Columns the database fills itself, such as failed_jobs.failed_at, are left alone.
 */
return new class extends Migration
{
    /** The columns Laravel wrote in the app timezone, keyed by table. */
    private const COLUMNS = [
        'departments' => ['created_at', 'updated_at'],
        'users' => ['email_verified_at', 'two_factor_confirmed_at', 'created_at', 'updated_at'],
        'password_reset_tokens' => ['created_at'],
        'passkeys' => ['last_used_at', 'created_at', 'updated_at'],
        'research_types' => ['created_at', 'updated_at'],
        'categories' => ['created_at', 'updated_at'],
        'submissions' => ['terminal_uploaded_at', 'created_at', 'updated_at'],
        'drive_items' => ['created_at', 'updated_at'],
        'announcements' => ['created_at', 'updated_at'],
        'activity_logs' => ['created_at', 'updated_at'],
        'proponents' => ['created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->shift('+8');
    }

    public function down(): void
    {
        $this->shift('-8');
    }

    /**
     * @param  '+8'|'-8'  $hours
     */
    private function shift(string $hours): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        DB::transaction(function () use ($hours, $sqlite) {
            foreach (self::COLUMNS as $table => $columns) {
                $values = [];
                foreach ($columns as $column) {
                    // Null columns stay null in both forms.
                    $values[$column] = DB::raw($sqlite
                        ? 'datetime('.$column.", '".$hours." hours')"
                        : $column.' + INTERVAL '.$hours.' HOUR');
                }
                DB::table($table)->update($values);
            }
        });
    }
};
