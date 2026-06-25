<?php

namespace App\Models;

use Database\Factories\BriefFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Brief extends Model
{
    /** @use HasFactory<BriefFactory> */
    use HasFactory;

    protected $fillable = ['title', 'description', 'referentiel_id', 'payload'];

    protected $casts = [
        'payload' => 'array',
    ];

    /**
     * @return BelongsTo<Referentiel, $this>
     */
    public function referentiel(): BelongsTo
    {
        return $this->belongsTo(Referentiel::class);
    }

    /**
     * The competences this brief assesses, each at a target level carried on the
     * pivot (`level_id`). Pass 1 iterates these filtered to kind = technique.
     *
     * @return BelongsToMany<Competence, $this>
     */
    public function competences(): BelongsToMany
    {
        return $this->belongsToMany(Competence::class)
            ->withPivot('level_id')
            ->withTimestamps();
    }
}
