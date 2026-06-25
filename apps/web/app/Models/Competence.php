<?php

namespace App\Models;

use Database\Factories\CompetenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Competence extends Model
{
    /** @use HasFactory<CompetenceFactory> */
    use HasFactory;

    protected $fillable = ['referentiel_id', 'code', 'label', 'description', 'kind'];

    /**
     * @return BelongsTo<Referentiel, $this>
     */
    public function referentiel(): BelongsTo
    {
        return $this->belongsTo(Referentiel::class);
    }

    /**
     * Only code-inspectable competences are eligible for LLM Pass 1; soft-skill
     * (transversale) competences are operator-validated and never graded.
     *
     * @param  Builder<Competence>  $query
     */
    public function scopeTechnical(Builder $query): void
    {
        $query->where('kind', 'technique');
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
