<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateReadmeRequest;
use App\Models\GeneratedReadme;
use App\Models\Repository;
use App\Services\ReadmeGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ReadmeController extends Controller
{
    /**
     * Generate a new README.md for the repository.
     */
    public function generate(GenerateReadmeRequest $request, int $id, ReadmeGeneratorService $generatorService): JsonResponse
    {
        $repository = Repository::with(['techStack'])->find($id);

        if (!$repository) {
            return response()->json([
                'message' => 'Repository not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $provider = $request->input('provider');
        $apiKey = $request->header('X-AI-API-Key') ?? $request->input('api_key');
        $model = $request->input('model');

        $generatedReadme = $generatorService->generate($repository, $provider, $apiKey, $model);

        return response()->json([
            'data' => [
                'repository_id' => $repository->id,
                'readme_id' => $generatedReadme->id,
                'content' => $generatedReadme->content,
                'generated_at' => $generatedReadme->generated_at?->toIso8601String(),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Get the latest generated README for a repository.
     */
    public function show(int $id): JsonResponse
    {
        $repository = Repository::find($id);

        if (!$repository) {
            return response()->json([
                'message' => 'Repository not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $readme = GeneratedReadme::where('repository_id', $id)
            ->latest('generated_at')
            ->first();

        if (!$readme) {
            return response()->json([
                'message' => 'No README has been generated for this repository yet.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => [
                'repository_id' => $repository->id,
                'readme_id' => $readme->id,
                'content' => $readme->content,
                'generated_at' => $readme->generated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Download the latest generated README.md as a file.
     */
    public function download(int $id): \Illuminate\Http\Response|JsonResponse
    {
        $repository = Repository::find($id);

        if (!$repository) {
            return response()->json([
                'message' => 'Repository not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $readme = GeneratedReadme::where('repository_id', $id)
            ->latest('generated_at')
            ->first();

        if (!$readme) {
            return response()->json([
                'message' => 'No README has been generated for this repository yet.',
            ], Response::HTTP_NOT_FOUND);
        }

        $filename = 'README.md';

        return response($readme->content, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
