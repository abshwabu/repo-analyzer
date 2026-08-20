<?php

namespace App\Jobs;

use App\Exceptions\RateLimitExceededException;
use App\Models\Repository;
use App\Services\GithubIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class IngestGithubRepositoryJob implements ShouldQueue
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
    public function handle(GithubIngestionService $service): void
    {
        $repository = Repository::find($this->repositoryId);

        if (!$repository) {
            Log::error("IngestGithubRepositoryJob: Repository ID {$this->repositoryId} not found.");
            return;
        }

        try {
            $repository->update([
                'status' => 'processing',
                'error_message' => null,
            ]);

            $service->ingest($repository);

            Log::info("IngestGithubRepositoryJob: Successfully ingested repository {$repository->owner}/{$repository->name} (#{$repository->id}).");
        } catch (RateLimitExceededException $e) {
            $retryAfter = $e->getRetryAfterSeconds();
            Log::warning("IngestGithubRepositoryJob: Rate limit hit for repository #{$this->repositoryId}. Releasing job for {$retryAfter} seconds.");

            $repository->update([
                'status' => 'pending',
                'error_message' => 'Rate limit reached, retrying in ' . $retryAfter . ' seconds',
            ]);

            $this->release($retryAfter);
        } catch (Throwable $e) {
            Log::error("IngestGithubRepositoryJob: Ingestion failed for repository #{$this->repositoryId}: " . $e->getMessage(), [
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
