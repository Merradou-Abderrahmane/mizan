<?php

namespace App\Models;

use Database\Factories\LevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Level extends Model
{
    /** @use HasFactory<LevelFactory> */
    use HasFactory;

    protected $fillable = ['referentiel_id', 'code', 'label', 'sort_order'];

    /**
     * @return BelongsTo<Referentiel, $this>
     */
    public function referentiel(): BelongsTo
    {
        return $this->belongsTo(Referentiel::class);
    }
}
