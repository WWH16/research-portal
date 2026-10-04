<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Failed sign-ins are no longer recorded or shown, so clear the ones already saved.
     */
    public function up(): void
    {
        DB::table('activity_logs')->where('action', 'auth.failed')->delete();
    }

    /**
     * The deleted entries can't be restored.
     */
    public function down(): void
    {
        //
    }
};
