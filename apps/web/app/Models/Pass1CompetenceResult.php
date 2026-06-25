<?php

namespace App\Models;

use Database\Factories\Pass1CompetenceResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pass1CompetenceResult extends Model
{
    /** @use HasFactory<Pass1CompetenceResultFactory> */
    use HasFactory;

    protected $table = 'pass1_competence_results';

    protected $fillable = [
        'run_id', 'competence_id', 'level_id',
        'ai_rollup_status', 'confidence', 'probe_questions', 'raw_json',
        'operator_status', 'operator_note', 'finalized_at',
    ];

    protected $casts = [
        'confidence' => 'float',
        'probe_questions' => 'array',
        'raw_json' => 'array',
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
     * @return BelongsTo<Competence, $this>
     */
    public function competence(): BelongsTo
    {
        return $this->belongsTo(Competence::class);
    }

    /**
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * R1: the single finalization point for a competence. Returns the operator's
     * finalized value ONLY when finalized_at is non-null, otherwise null — so the
     * AI rollup can never be read as a final verdict. The operator finalizes,
     * always.
     */
    public function finalVerdict(): ?string
    {
        if ($this->finalized_at === null) {
            return null;
        }

        return $this->operator_status;
    }
}
