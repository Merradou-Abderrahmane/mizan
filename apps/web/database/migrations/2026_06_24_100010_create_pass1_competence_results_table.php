<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pass 1 competence-level rollup + the single operator finalization
        // point. ai_rollup_status is hedged (model never asserts a bare verdict);
        // operator_status carries the operator's valide/non valide.
        Schema::create('pass1_competence_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competence_id')->constrained()->restrictOnDelete();
            $table->foreignId('level_id')->constrained()->restrictOnDelete();
            $table->string('ai_rollup_status')->default('à vérifier');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->json('probe_questions')->nullable();
            $table->json('raw_json')->nullable();
            $table->string('operator_status')->nullable();
            $table->text('operator_note')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'competence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass1_competence_results');
    }
};
