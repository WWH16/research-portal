<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an admin changes the storage account, the earlier one is retired rather than deleted: its access
     * stays, so the documents already stored there keep opening. The one row without retired_at is current.
     */
    public function up(): void
    {
        Schema::table('google_drive_connections', function (Blueprint $table) {
            $table->timestamp('retired_at')->nullable()->after('connected_by');
        });
    }

    public function down(): void
    {
        Schema::table('google_drive_connections', function (Blueprint $table) {
            $table->dropColumn('retired_at');
        });
    }
};
