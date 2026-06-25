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
            $table->foreignId('criterion_id')->constrained('criteria')->restrictOnDelete();
            // Runner-oriented columns are nullable: Pass 1 evidence is an
            // LLM citation {file, line, note(=message)}; runner output itself
            // lives in runner_report_json on the Run, not here.
            $table->string('check_id')->nullable();
            $table->string('file_path')->nullable();
            $table->integer('line_number')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('kind')->nullable();
            $table->string('status')->nullable();
            $table->string('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence');
    }
};
