<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateAiSummaryRequest;
use App\Models\Repository;
use App\Services\AiSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AiSummaryController extends Controller
{
    /**
     * Generate an AI summary for a repository.
     */
    public function summarize(GenerateAiSummaryRequest $request, int $id, AiSummaryService $summaryService): JsonResponse
    {
        $repository = Repository::with(['techStack'])->find($id);

        if (!$repository) {
            return response()->json([
                'message' => 'Repository not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $provider = $request->validated('provider');
        $apiKey = $request->header('X-AI-API-Key') ?? $request->input('api_key');
        $model = $request->input('model');

        try {
            $result = $summaryService->summarize($repository, $provider, $apiKey, $model);

            return response()->json([
                'data' => [
                    'repository_id' => $repository->id,
                    'provider' => $result['provider'],
                    'model' => $result['model'],
                    'summary' => $result['summary'],
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'unauthorized') || str_contains($e->getMessage(), 'Invalid')
                ? Response::HTTP_UNAUTHORIZED
                : (str_contains($e->getMessage(), 'rate limit') || str_contains($e->getMessage(), 'quota')
                    ? Response::HTTP_TOO_MANY_REQUESTS
                    : Response::HTTP_BAD_GATEWAY);

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'An unexpected error occurred while generating the summary.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
