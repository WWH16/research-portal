<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen users.role so it can hold the staff role.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'staff', 'faculty'])->default('faculty')->change();
        });
    }

    /**
     * Narrow users.role again. On MySQL this fails while staff rows exist. That is the safe failure: it changes
     * no one's role without asking.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'faculty'])->default('faculty')->change();
        });
    }
};
