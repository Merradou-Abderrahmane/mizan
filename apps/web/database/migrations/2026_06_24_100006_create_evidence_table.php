<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competence_id')->constrained()->restrictOnDelete();
            $table->string('check_id');
            $table->string('file_path')->nullable();
            $table->integer('line_number')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('kind');
            $table->string('status');
            $table->string('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence');
    }
};
