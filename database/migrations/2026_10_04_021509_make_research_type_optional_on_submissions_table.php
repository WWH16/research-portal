<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proposals no longer pick a research type: the stage tracker and documents say where a project is.
     * Older projects keep the type they were filed with.
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->foreignId('research_type_id')->nullable()->change();
        });
    }

    /**
     * Only reversible while every project still has a research type.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->foreignId('research_type_id')->nullable(false)->change();
        });
    }
};
