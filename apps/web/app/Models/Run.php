<?php

namespace App\Models;

use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    protected $fillable = [
        'student_repo_id', 'brief_id', 'status',
        'runner_report_json', 'started_at', 'ended_at',
    ];

    protected $casts = [
        'runner_report_json' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<StudentRepo, $this>
     */
    public function studentRepo(): BelongsTo
    {
        return $this->belongsTo(StudentRepo::class);
    }

    /**
     * @return BelongsTo<Brief, $this>
     */
    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    /**
     * @return HasMany<Evidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    /**
     * @return HasMany<Draft, $this>
     */
    public function drafts(): HasMany
    {
        return $this->hasMany(Draft::class);
    }

    /**
     * @return HasMany<ProbeFlag, $this>
     */
    public function probeFlags(): HasMany
    {
        return $this->hasMany(ProbeFlag::class);
    }
}
