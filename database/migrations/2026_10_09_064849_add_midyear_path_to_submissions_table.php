<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Projects file a mid-year progress report between the detailed proposal and the terminal report.
     * Existing projects keep their status; one already completed isn't asked for the report.
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->string('midyear_path')->nullable()->after('detailed_path');
        });
    }

    /**
     * Projects at the mid-year stage go back to Detailed. Their uploaded reports stay on the disk.
     */
    public function down(): void
    {
        DB::table('submissions')->where('status', 'Mid-year')->update(['status' => 'Detailed']);

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('midyear_path');
        });
    }
};
