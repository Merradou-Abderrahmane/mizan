<?php

namespace App\Models;

use Database\Factories\ProbeFlagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProbeFlag extends Model
{
    /** @use HasFactory<ProbeFlagFactory> */
    use HasFactory;

    protected $fillable = ['run_id', 'criterion_id', 'kind', 'context_payload', 'message'];

    protected $casts = [
        'context_payload' => 'array',
    ];

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * @return BelongsTo<Criterion, $this>
     */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(Criterion::class);
    }
}
