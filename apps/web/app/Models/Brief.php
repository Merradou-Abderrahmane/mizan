<?php

namespace App\Models;

use Database\Factories\BriefFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
