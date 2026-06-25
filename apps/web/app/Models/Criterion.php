<?php

namespace App\Models;

use Database\Factories\CriterionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Criterion extends Model
{
    /** @use HasFactory<CriterionFactory> */
    use HasFactory;

    // Explicit: the plural of "Criterion" is irregular; pin the table name.
    protected $table = 'criteria';

    protected $fillable = ['competence_id', 'level_id', 'code', 'label', 'description', 'sort_order'];

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
}
