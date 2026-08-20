<?php

namespace Tests\Unit;

use App\Models\Repository;
use App\Models\RepoTechStack;
use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\OpenAiProvider;
use App\Services\AiSummaryService;
use App\Services\GithubIngestionService;
use App\Services\RepoContextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AiSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_openai_provider_generates_structured_summary(): void
    {
        $provider = new OpenAiProvider();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'project_overview' => 'A powerful repository analyzer tool built for developers.',
                                'architecture' => 'The backend is built with Laravel 11. The frontend uses Vue 3 and Vite.',
                                'getting_started' => [
                                    'prerequisites' => ['PHP 8.2+', 'Node.js 18+'],
                                    'install_commands' => ['composer install', 'npm install'],
                                    'run_commands' => ['php artisan serve', 'npm run dev'],
                                    'test_commands' => ['php artisan test'],
                                    'instructions' => 'Run the install commands and start both services.',
                                ],
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $provider->generateSummary(['sample' => 'context'], 'sk-test-openai-key');

        $this->assertEquals('A powerful repository analyzer tool built for developers.', $result['project_overview']);
        $this->assertStringContainsString('Laravel 11', $result['architecture']);
        $this->assertCount(2, $result['getting_started']['prerequisites']);
        $this->assertEquals(['composer install', 'npm install'], $result['getting_started']['install_commands']);
    }

    public function test_openai_provider_handles_invalid_api_key(): void
    {
        $provider = new OpenAiProvider();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Incorrect API key provided'],
            ], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid or unauthorized OpenAI API key.');

        $provider->generateSummary(['sample' => 'context'], 'sk-invalid-key');
    }

    public function test_anthropic_provider_generates_structured_summary(): void
    {
        $provider = new AnthropicProvider();

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "```json\n" . json_encode([
                            'project_overview' => 'Claude-analyzed repository overview.',
                            'architecture' => 'Modular service architecture.',
                            'getting_started' => [
                                'prerequisites' => ['Docker'],
                                'install_commands' => ['docker compose up -d'],
                                'run_commands' => ['docker compose logs -f'],
                                'test_commands' => ['docker compose exec app php artisan test'],
                                'instructions' => 'Run with docker compose.',
                            ],
                        ]) . "\n```",
                    ],
                ],
            ], 200),
        ]);

        $result = $provider->generateSummary(['sample' => 'context'], 'sk-ant-test-key');

        $this->assertEquals('Claude-analyzed repository overview.', $result['project_overview']);
        $this->assertEquals('Modular service architecture.', $result['architecture']);
        $this->assertEquals(['Docker'], $result['getting_started']['prerequisites']);
    }

    public function test_anthropic_provider_handles_unauthorized_key(): void
    {
        $provider = new AnthropicProvider();

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'error' => ['message' => 'Invalid API key'],
            ], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid or unauthorized Anthropic API key.');

        $provider->generateSummary(['sample' => 'context'], 'sk-ant-invalid-key');
    }

    public function test_repo_context_extractor_truncates_long_content(): void
    {
        $ingestionService = new GithubIngestionService();
        $extractor = new RepoContextExtractor($ingestionService);

        $longText = str_repeat('A', 5000);
        $truncated = $extractor->truncateText($longText, 2000);

        $this->assertLessThan(2100, mb_strlen($truncated));
        $this->assertStringContainsString('... [truncated]', $truncated);
    }

    public function test_ai_summary_service_resolves_providers(): void
    {
        $ingestionService = new GithubIngestionService();
        $extractor = new RepoContextExtractor($ingestionService);
        $service = new AiSummaryService($extractor);

        $this->assertInstanceOf(OpenAiProvider::class, $service->resolveProvider('openai'));
        $this->assertInstanceOf(AnthropicProvider::class, $service->resolveProvider('anthropic'));
        $this->assertInstanceOf(AnthropicProvider::class, $service->resolveProvider('claude'));

        $this->expectException(InvalidArgumentException::class);
        $service->resolveProvider('unsupported-provider');
    }
}
