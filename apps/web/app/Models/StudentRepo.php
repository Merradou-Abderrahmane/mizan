<?php

namespace App\Models;

use Database\Factories\StudentRepoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentRepo extends Model
{
    /** @use HasFactory<StudentRepoFactory> */
    use HasFactory;

    protected $fillable = ['name', 'clone_path', 'operator_persona'];

    protected $hidden = ['operator_persona'];

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }
}
