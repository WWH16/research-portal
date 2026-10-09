<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a completed project was presented at the university's in-house review. Not every project
     * presents, so the Research Office ticks it on the Submissions list before printing a certificate.
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->boolean('presented')->default(false)->after('terminal_uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('presented');
        });
    }
};
