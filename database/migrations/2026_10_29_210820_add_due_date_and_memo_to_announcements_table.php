<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // A schedule of submissions has a deadline; a memo has its file, kept on the portal's Google Drive. An
            // announcement may be either, both or neither.
            $table->date('due_on')->nullable()->after('description');
            $table->string('attachment_path')->nullable()->after('due_on');
            $table->string('attachment_name')->nullable()->after('attachment_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['due_on', 'attachment_path', 'attachment_name']);
        });
    }
};
