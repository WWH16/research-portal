<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admin saves the storage account first and connects it after, so the row exists before Google
     * hands over a token, and Disconnect clears the token while the chosen account stays.
     */
    public function up(): void
    {
        Schema::table('google_drive_connections', function (Blueprint $table) {
            $table->text('refresh_token')->nullable()->change();
            $table->string('folder_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('google_drive_connections', function (Blueprint $table) {
            $table->text('refresh_token')->nullable(false)->change();
            $table->string('folder_id')->nullable(false)->change();
        });
    }
};
