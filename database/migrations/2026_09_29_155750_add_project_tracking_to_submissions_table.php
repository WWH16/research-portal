<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a submission into a project record that collects its concept proposal, detailed
 * proposal and terminal report over time, filed under the year it was actually proposed.
 * The status follows the furthest document uploaded, and only the concept proposal is
 * reviewed: concept_passed records the Research Office's pass.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->renameColumn('file_path', 'concept_path');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->string('concept_path')->nullable()->change();
            $table->string('detailed_path')->nullable()->after('concept_path');
            $table->string('terminal_path')->nullable()->after('detailed_path');
            $table->timestamp('terminal_uploaded_at')->nullable()->after('terminal_path');
            $table->unsignedSmallInteger('year')->nullable()->after('title');
            $table->date('start_date')->nullable()->after('year');
            $table->date('target_date')->nullable()->after('start_date');
            $table->string('status', 20)->default('Concept')->change();
            $table->boolean('awaiting_review')->default(true)->after('status');
            $table->boolean('concept_passed')->default(false)->after('awaiting_review');
        });

        // Old reviews map onto the new stages: an approved proposal was a passed concept.
        foreach (DB::table('submissions')->get(['id', 'status', 'created_at']) as $row) {
            DB::table('submissions')->where('id', $row->id)->update([
                'year' => Carbon::parse($row->created_at)->year,
                'status' => 'Concept',
                'awaiting_review' => $row->status === 'Pending',
                'concept_passed' => $row->status === 'OK',
            ]);
        }

        Schema::table('submissions', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable(false)->change();
            $table->index(['year', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('submissions')->update([
            'status' => DB::raw("CASE WHEN concept_passed THEN 'OK' ELSE 'Pending' END"),
            'concept_path' => DB::raw("COALESCE(concept_path, detailed_path, terminal_path, '')"),
        ]);

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['year', 'status']);
            $table->dropColumn(['detailed_path', 'terminal_path', 'terminal_uploaded_at', 'year', 'start_date', 'target_date', 'awaiting_review', 'concept_passed']);
            $table->enum('status', ['Pending', 'For Revision', 'OK'])->default('Pending')->change();
            $table->string('concept_path')->nullable(false)->change();
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->renameColumn('concept_path', 'file_path');
        });
    }
};
