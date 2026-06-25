<?php

namespace App\Models;

use Database\Factories\CompetenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Competence extends Model
{
    /** @use HasFactory<CompetenceFactory> */
    use HasFactory;

    protected $fillable = ['referentiel_id', 'code', 'label', 'description'];

    /**
     * @return BelongsTo<Referentiel, $this>
     */
    public function referentiel(): BelongsTo
    {
        return $this->belongsTo(Referentiel::class);
    }

    /**
     * A competence spans all three progressive levels; the (competence, level)
     * association is carried by its criteria — there is no direct level FK.
     *
     * @return HasMany<Criterion, $this>
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(Criterion::class);
    }
}
