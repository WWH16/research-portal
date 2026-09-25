<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('research_type_id')->constrained();
            $table->foreignId('category_id')->constrained();
            $table->foreignId('department_id')->constrained();
            $table->string('title');
            $table->text('abstract')->nullable();
            $table->string('designation', 100)->nullable();
            $table->string('file_path');
            $table->enum('status', ['Pending', 'For Revision', 'OK'])->default('Pending');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
