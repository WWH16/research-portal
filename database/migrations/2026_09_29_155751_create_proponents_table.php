<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The proponents table of a proposal: who works on which study of a project, and in what role.
 * One faculty member can appear in several studies, so counts use distinct user IDs.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('proponents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->unsignedTinyInteger('study');
            $table->string('role', 20);
            $table->timestamps();

            $table->unique(['submission_id', 'study', 'user_id']);
        });

        // Every existing proposal was filed by one author, who led it.
        foreach (DB::table('submissions')->get(['id', 'user_id', 'created_at']) as $row) {
            DB::table('proponents')->insert([
                'submission_id' => $row->id,
                'user_id' => $row->user_id,
                'study' => 1,
                'role' => 'Leader',
                'created_at' => $row->created_at,
                'updated_at' => $row->created_at,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proponents');
    }
};
