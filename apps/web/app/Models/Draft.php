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
    ];

    protected $casts = [
        'ai_raw_json' => 'array',
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
