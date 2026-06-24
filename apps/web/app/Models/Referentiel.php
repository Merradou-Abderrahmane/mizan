<?php

namespace App\Models;

use Database\Factories\ReferentielFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referentiel extends Model
{
    /** @use HasFactory<ReferentielFactory> */
    use HasFactory;

    protected $fillable = ['title', 'description'];

    /**
     * @return HasMany<Level, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(Level::class);
    }

    /**
     * @return HasMany<Competence, $this>
     */
    public function competences(): HasMany
    {
        return $this->hasMany(Competence::class);
    }

    /**
     * @return HasMany<Brief, $this>
     */
    public function briefs(): HasMany
    {
        return $this->hasMany(Brief::class);
    }
}
