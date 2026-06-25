<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Assessment scope: which competences a brief assesses, and at what
        // target level each (different competences may be assessed at different
        // levels in one brief).
        Schema::create('brief_competence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brief_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['brief_id', 'competence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brief_competence');
    }
};
