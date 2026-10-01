<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep entries when an account is deleted, with the actor's and subject's names saved so the
     * history still reads the same, and record what each action was about. IP addresses aren't kept.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('actor_name')->nullable()->after('user_id');
            $table->dropColumn('ip_address');
            // The action names what the subject is: a submission.* entry points at a submission, and so on.
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('properties')->nullable();

            $table->index('action');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['action']);
            $table->dropColumn(['actor_name', 'subject_id', 'subject_label', 'properties']);
            $table->string('ip_address', 45)->nullable()->after('user_id');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users');
        });
    }
};
