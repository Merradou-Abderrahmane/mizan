<?php

namespace App\Models;

use Database\Factories\DraftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Draft extends Model
{
    /** @use HasFactory<DraftFactory> */
    use HasFactory;

    protected $fillable = [
        'run_id', 'criterion_id',
        'ai_status', 'ai_raw_json', 'ai_reasoning',
        'operator_status', 'operator_note', 'finalized_at',
    ];

    protected $casts = [
        'ai_raw_json' => 'array',
        'finalized_at' => 'datetime',
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

    /**
     * R1: The LLM never emits a final verdict. The operator finalizes, always.
     * Returns the operator's finalized value ONLY when finalized_at is non-null,
     * otherwise null — so an un-finalized draft can never be read as a verdict.
     */
    public function finalVerdict(): ?string
    {
        if ($this->finalized_at === null) {
            return null;
        }

        return $this->operator_status;
    }
}
