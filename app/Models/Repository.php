<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Repository extends Model
{
    use HasFactory;

    protected $fillable = [
        'github_url',
        'owner',
        'name',
        'default_branch',
        'status',
        'error_message',
        'description',
        'stars',
        'license',
        'repo_created_at',
        'last_analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'stars' => 'integer',
            'repo_created_at' => 'datetime',
            'last_analyzed_at' => 'datetime',
        ];
    }

    public function techStack(): HasMany
    {
        return $this->hasMany(RepoTechStack::class);
    }

    public function commits(): HasMany
    {
        return $this->hasMany(RepoCommit::class);
    }

    public function contributors(): HasMany
    {
        return $this->hasMany(RepoContributor::class);
    }

    public function readme(): HasOne
    {
        return $this->hasOne(GeneratedReadme::class);
    }
}
