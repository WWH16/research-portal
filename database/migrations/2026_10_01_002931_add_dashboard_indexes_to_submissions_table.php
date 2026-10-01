<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index the columns the dashboard filters on every load: the filing date range and the review queue.
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('awaiting_review');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['awaiting_review']);
        });
    }
};
