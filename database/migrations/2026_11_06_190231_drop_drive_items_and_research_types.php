<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both are old features nothing uses any more: files live on Google Drive, and projects no longer pick a type.
     */
    public function up(): void
    {
        Schema::dropIfExists('drive_items');

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('research_type_id');
        });

        Schema::dropIfExists('research_types');
    }

    /**
     * Brings back the empty tables and column, not any rows they held.
     */
    public function down(): void
    {
        Schema::create('research_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->timestamps();
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->foreignId('research_type_id')->nullable()->after('user_id')->constrained();
        });

        Schema::create('drive_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('drive_items')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('name');
            $table->boolean('is_folder')->default(false);
            $table->string('stored_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();

            $table->unique(['parent_id', 'name']);
        });
    }
};
