<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_repo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brief_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->json('runner_report_json')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
