<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyzeRepositoryRequest;
use App\Jobs\DetectTechStackJob;
use App\Jobs\IngestGithubRepositoryJob;
use App\Models\Repository;
use App\Services\GithubIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Bus;

class RepositoryController extends Controller
{
    /**
     * Dispatch chained ingestion and tech stack detection jobs for a GitHub repository.
     */
    public function analyze(AnalyzeRepositoryRequest $request, GithubIngestionService $ingestionService): JsonResponse
    {
        $parsed = $ingestionService->parseUrl($request->validated('github_url'));

        // Find or create repository record
        $repository = Repository::firstOrCreate(
            ['github_url' => $parsed['normalized_url']],
            [
                'owner' => $parsed['owner'],
                'name' => $parsed['repo'],
                'default_branch' => 'main',
                'status' => 'pending',
            ]
        );

        // If repository was previously completed or failed, reset status to pending for new ingestion
        if ($repository->status !== 'processing') {
            $repository->update([
                'status' => 'pending',
                'error_message' => null,
            ]);
        }

        // Dispatch chained queued background jobs (Ingestion -> Tech Stack Detection)
        Bus::chain([
            new IngestGithubRepositoryJob($repository->id),
            new DetectTechStackJob($repository->id),
        ])->dispatch();

        return response()->json([
            'message' => 'Repository analysis queued successfully',
            'data' => [
                'repository_id' => $repository->id,
                'status' => $repository->status,
                'github_url' => $repository->github_url,
                'owner' => $repository->owner,
                'name' => $repository->name,
            ],
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Get the ingestion and analysis status of a repository including tech stack results.
     */
    public function status(int $id): JsonResponse
    {
        $repository = Repository::with(['techStack' => function ($query) {
            $query->orderBy('confidence', 'desc');
        }])->withCount(['commits', 'contributors', 'techStack'])->find($id);

        if (!$repository) {
            return response()->json([
                'message' => 'Repository not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => [
                'id' => $repository->id,
                'github_url' => $repository->github_url,
                'owner' => $repository->owner,
                'name' => $repository->name,
                'default_branch' => $repository->default_branch,
                'status' => $repository->status,
                'description' => $repository->description,
                'stars' => $repository->stars,
                'license' => $repository->license,
                'repo_created_at' => $repository->repo_created_at?->toIso8601String(),
                'last_analyzed_at' => $repository->last_analyzed_at?->toIso8601String(),
                'error_message' => $repository->error_message,
                'tech_stack' => $repository->techStack->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'category' => $item->category,
                        'name' => $item->name,
                        'confidence' => (float) $item->confidence,
                    ];
                }),
                'stats' => [
                    'commits_count' => $repository->commits_count,
                    'contributors_count' => $repository->contributors_count,
                    'tech_stack_count' => $repository->tech_stack_count,
                ],
            ],
        ]);
    }
}
