<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probe_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competence_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->json('context_payload')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probe_flags');
    }
};
