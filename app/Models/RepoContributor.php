<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepoContributor extends Model
{
    use HasFactory;

    protected $fillable = [
        'repository_id',
        'github_username',
        'commit_count',
        'first_commit_at',
        'last_commit_at',
    ];

    protected function casts(): array
    {
        return [
            'commit_count' => 'integer',
            'first_commit_at' => 'datetime',
            'last_commit_at' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
