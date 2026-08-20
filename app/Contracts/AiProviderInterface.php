<?php

namespace App\Contracts;

interface AiProviderInterface
{
    /**
     * Generate structured summary from repository context using user-supplied API key.
     *
     * @param array<string, mixed> $context
     * @param string $apiKey
     * @param string|null $model
     * @return array{project_overview: string, architecture: string, getting_started: array{prerequisites: array<string>, install_commands: array<string>, run_commands: array<string>, test_commands?: array<string>, instructions: string}}
     */
    public function generateSummary(array $context, string $apiKey, ?string $model = null): array;
}
