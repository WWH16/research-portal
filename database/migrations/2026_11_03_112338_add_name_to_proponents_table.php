<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff and people outside the campus have no portal account, so a proponent row holds either a faculty account or
 * a typed full name. A named row grants nothing: no access, no notices, no count as faculty.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('proponents', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('name', 150)->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations. Refused while named rows exist, so a rollback never loses a proponent.
     */
    public function down(): void
    {
        if (DB::table('proponents')->whereNull('user_id')->exists()) {
            throw new RuntimeException('Some proponents have no portal account. Remove them from their projects before rolling back.');
        }

        Schema::table('proponents', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
