<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Only one storage account may be current. The flag is 1 on the row without retired_at and empty on retired
     * ones, and a unique index allows any number of empty values, so two admins saving at once can't make two.
     */
    public function up(): void
    {
        Schema::table('google_drive_connections', function (Blueprint $table) {
            $table->boolean('is_current')->nullable()->virtualAs('case when retired_at is null then 1 end')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('google_drive_connections', function (Blueprint $table) {
            $table->dropUnique(['is_current']);
            $table->dropColumn('is_current');
        });
    }
};
