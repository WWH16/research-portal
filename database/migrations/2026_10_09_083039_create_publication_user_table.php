<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The portal faculty who wrote each publication. Any of them can edit it, and it shows on each profile.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('publication_user', function (Blueprint $table) {
            $table->foreignId('publication_id')->constrained()->cascadeOnDelete();
            // No cascade, like proponents: an author with publications can't be deleted, so no paper is left without one.
            $table->foreignId('user_id')->constrained();

            $table->primary(['publication_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('publication_user');
    }
};
