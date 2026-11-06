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
};
