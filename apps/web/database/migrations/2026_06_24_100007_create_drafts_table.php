<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('criterion_id')->constrained('criteria')->restrictOnDelete();
            $table->string('ai_status')->default('à vérifier');
            $table->json('ai_raw_json')->nullable();
            $table->text('ai_reasoning')->nullable();
            $table->string('operator_status')->nullable();
            $table->text('operator_note')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drafts');
    }
};
