<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepoCommit extends Model
{
    use HasFactory;

    protected $fillable = [
        'repository_id',
        'sha',
        'message',
        'author_name',
        'author_email',
        'committed_at',
        'additions',
        'deletions',
    ];

    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
            'additions' => 'integer',
            'deletions' => 'integer',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
