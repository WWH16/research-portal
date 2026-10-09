<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A faculty member's published paper. Its portal authors are in publication_user and the papers
 * that cite it in citations; counts come from those rows, never a stored number.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            // Optional link to a completed project. Deleting the project keeps the publication.
            $table->foreignId('submission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            // As printed in the paper: "Rivera, M. A.; Soriano, M."
            $table->string('authors', 1000);
            $table->string('journal');
            $table->string('volume', 50)->nullable();
            $table->string('issue', 50)->nullable();
            $table->string('pages', 50)->nullable();
            $table->date('published_on')->index();
            $table->text('description')->nullable();
            // The normalized DOI URL or other link; one record per paper across the portal.
            $table->string('link', 500)->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('publications');
    }
};
