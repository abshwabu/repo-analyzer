<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Models\RepoTechStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarize_endpoint_validates_request(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/summary-repo',
            'owner' => 'owner',
            'name' => 'summary-repo',
            'status' => 'completed',
        ]);

        $response = $this->postJson("/api/v1/repositories/{$repository->id}/summarize", [
            'provider' => 'invalid-provider',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['provider', 'api_key']);
    }

    public function test_summarize_endpoint_accepts_openai_with_header_key(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/openai-repo',
            'owner' => 'owner',
            'name' => 'openai-repo',
            'status' => 'completed',
            'description' => 'Test repo description',
        ]);

        RepoTechStack::create([
            'repository_id' => $repository->id,
            'category' => 'framework',
            'name' => 'Laravel',
            'confidence' => 99.0,
        ]);

        Http::fake([
            'https://api.github.com/*' => Http::response([], 200),
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'project_overview' => 'An automated repository analysis tool.',
                                'architecture' => 'Structured in layered services.',
                                'getting_started' => [
                                    'prerequisites' => ['PHP 8.2+'],
                                    'install_commands' => ['composer install'],
                                    'run_commands' => ['php artisan serve'],
                                    'test_commands' => ['php artisan test'],
                                    'instructions' => 'Run composer install and serve.',
                                ],
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'X-AI-API-Key' => 'sk-valid-openai-key',
        ])->postJson("/api/v1/repositories/{$repository->id}/summarize", [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'repository_id',
                    'provider',
                    'model',
                    'summary' => [
                        'project_overview',
                        'architecture',
                        'getting_started' => [
                            'prerequisites',
                            'install_commands',
                            'run_commands',
                            'test_commands',
                            'instructions',
                        ],
                    ],
                ],
            ])
            ->assertJson([
                'data' => [
                    'repository_id' => $repository->id,
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'summary' => [
                        'project_overview' => 'An automated repository analysis tool.',
                    ],
                ],
            ]);
    }

    public function test_summarize_endpoint_accepts_anthropic_with_body_key(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/claude-repo',
            'owner' => 'owner',
            'name' => 'claude-repo',
            'status' => 'completed',
        ]);

        Http::fake([
            'https://api.github.com/*' => Http::response([], 200),
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'project_overview' => 'Claude-generated summary of repo.',
                            'architecture' => 'Clean domain architecture.',
                            'getting_started' => [
                                'prerequisites' => ['Node 20'],
                                'install_commands' => ['npm i'],
                                'run_commands' => ['npm run dev'],
                                'test_commands' => ['npm test'],
                                'instructions' => 'Start development server.',
                            ],
                        ]),
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/repositories/{$repository->id}/summarize", [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-valid-key',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'repository_id' => $repository->id,
                    'provider' => 'anthropic',
                    'model' => 'claude-3-5-sonnet-20241022',
                    'summary' => [
                        'project_overview' => 'Claude-generated summary of repo.',
                        'architecture' => 'Clean domain architecture.',
                    ],
                ],
            ]);
    }

    public function test_summarize_returns_401_on_provider_rejection(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/badkey-repo',
            'owner' => 'owner',
            'name' => 'badkey-repo',
            'status' => 'completed',
        ]);

        Http::fake([
            'https://api.github.com/*' => Http::response([], 200),
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Invalid API key'],
            ], 401),
        ]);

        $response = $this->postJson("/api/v1/repositories/{$repository->id}/summarize", [
            'provider' => 'openai',
            'api_key' => 'sk-invalid',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid or unauthorized OpenAI API key.',
            ]);
    }

    public function test_summarize_returns_404_for_unknown_repository(): void
    {
        $response = $this->postJson('/api/v1/repositories/9999/summarize', [
            'provider' => 'openai',
            'api_key' => 'sk-test',
        ]);

        $response->assertStatus(404);
    }
}
