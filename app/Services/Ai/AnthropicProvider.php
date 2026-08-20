<?php

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AnthropicProvider implements AiProviderInterface
{
    public const DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';

    /**
     * Generate structured summary using Anthropic (Claude) API.
     */
    public function generateSummary(array $context, string $apiKey, ?string $model = null): array
    {
        $selectedModel = $model ?: self::DEFAULT_MODEL;

        $systemPrompt = "You are a software architect and code analyzer. Analyze the provided repository context (metadata, tech stack, directory structure, manifests, and file excerpts) and generate a concise, structured JSON response with the following keys:\n"
            . "- project_overview: A 2-3 sentence summary of what this project does and its core value proposition.\n"
            . "- architecture: A 3-5 sentence breakdown of the codebase architecture, folder structure, and main modules.\n"
            . "- getting_started: An object containing prerequisites (array of strings), install_commands (array of strings), run_commands (array of strings), test_commands (array of strings), and instructions (concise summary string).\n"
            . "Output ONLY the raw valid JSON object without markdown formatting, code fences, or additional text.";

        $userPrompt = "Here is the repository context:\n\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $selectedModel,
                'max_tokens' => 1500,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.2,
            ]);

        if ($response->status() === 401) {
            throw new RuntimeException('Invalid or unauthorized Anthropic API key.');
        }

        if ($response->status() === 429) {
            throw new RuntimeException('Anthropic rate limit or quota exceeded.');
        }

        if (!$response->successful()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new RuntimeException("Anthropic API error [{$response->status()}]: {$error}");
        }

        $rawContent = $response->json('content.0.text');
        if (empty($rawContent)) {
            throw new RuntimeException('Empty response received from Anthropic.');
        }

        // Clean any code block backticks if present
        $cleanJson = trim($rawContent);
        if (str_starts_with($cleanJson, '```')) {
            $cleanJson = preg_replace('/^```(?:json)?\s*/i', '', $cleanJson);
            $cleanJson = preg_replace('/\s*```$/', '', $cleanJson);
        }

        try {
            $parsed = json_decode($cleanJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to parse JSON response from Anthropic: ' . $e->getMessage());
        }

        return $this->formatStructuredResult($parsed);
    }

    /**
     * Ensure result strictly adheres to expected schema.
     */
    protected function formatStructuredResult(array $data): array
    {
        $gettingStarted = $data['getting_started'] ?? [];
        if (is_string($gettingStarted)) {
            $gettingStarted = ['instructions' => $gettingStarted];
        }

        return [
            'project_overview' => (string) ($data['project_overview'] ?? 'Summary not available.'),
            'architecture' => (string) ($data['architecture'] ?? 'Architecture overview not available.'),
            'getting_started' => [
                'prerequisites' => (array) ($gettingStarted['prerequisites'] ?? []),
                'install_commands' => (array) ($gettingStarted['install_commands'] ?? []),
                'run_commands' => (array) ($gettingStarted['run_commands'] ?? []),
                'test_commands' => (array) ($gettingStarted['test_commands'] ?? []),
                'instructions' => (string) ($gettingStarted['instructions'] ?? 'Follow the install and run commands above.'),
            ],
        ];
    }
}
