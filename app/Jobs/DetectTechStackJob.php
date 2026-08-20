<?php

namespace App\Jobs;

use App\Models\Repository;
use App\Services\TechStackDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DetectTechStackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public int $repositoryId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $repositoryId)
    {
        $this->repositoryId = $repositoryId;
    }

    /**
     * Execute the job.
     */
    public function handle(TechStackDetectionService $service): void
    {
        $repository = Repository::find($this->repositoryId);

        if (!$repository) {
            Log::error("DetectTechStackJob: Repository ID {$this->repositoryId} not found.");
            return;
        }

        try {
            $service->detect($repository);

            $repository->update([
                'status' => 'completed',
                'error_message' => null,
                'last_analyzed_at' => now(),
            ]);

            Log::info("DetectTechStackJob: Successfully completed tech stack detection for {$repository->owner}/{$repository->name} (#{$repository->id}).");
        } catch (Throwable $e) {
            Log::error("DetectTechStackJob: Tech stack detection failed for repository #{$this->repositoryId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);

            $repository->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
