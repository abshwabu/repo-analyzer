<?php

namespace App\Services;

use App\Contracts\AiProviderInterface;
use App\Models\Repository;
use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\OpenAiProvider;
use InvalidArgumentException;

class AiSummaryService
{
    protected RepoContextExtractor $contextExtractor;

    public function __construct(RepoContextExtractor $contextExtractor)
    {
        $this->contextExtractor = $contextExtractor;
    }

    /**
     * Generate an AI summary for a repository using the specified provider and user API key.
     *
     * @param Repository $repository
     * @param string $providerName
     * @param string $apiKey
     * @param string|null $model
     * @return array{provider: string, model: string, summary: array{project_overview: string, architecture: string, getting_started: array}}
     */
    public function summarize(Repository $repository, string $providerName, string $apiKey, ?string $model = null): array
    {
        $provider = $this->resolveProvider($providerName);
        $context = $this->contextExtractor->extract($repository);

        $selectedModel = $model ?: $this->getDefaultModelForProvider($providerName);
        $summary = $provider->generateSummary($context, $apiKey, $selectedModel);

        return [
            'provider' => strtolower($providerName),
            'model' => $selectedModel,
            'summary' => $summary,
        ];
    }

    /**
     * Resolve AI provider implementation by name.
     */
    public function resolveProvider(string $providerName): AiProviderInterface
    {
        return match (strtolower(trim($providerName))) {
            'openai' => new OpenAiProvider(),
            'anthropic', 'claude' => new AnthropicProvider(),
            default => throw new InvalidArgumentException("Unsupported AI provider: '{$providerName}'. Supported providers: 'openai', 'anthropic'."),
        };
    }

    /**
     * Get default model for provider.
     */
    public function getDefaultModelForProvider(string $providerName): string
    {
        return match (strtolower(trim($providerName))) {
            'openai' => OpenAiProvider::DEFAULT_MODEL,
            'anthropic', 'claude' => AnthropicProvider::DEFAULT_MODEL,
            default => 'default',
        };
    }
}
