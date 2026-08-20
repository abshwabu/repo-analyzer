<?php

namespace Tests\Feature;

use App\Jobs\DetectTechStackJob;
use App\Jobs\IngestGithubRepositoryJob;
use App\Models\Repository;
use App\Models\RepoTechStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TechStackDetectionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_endpoint_dispatches_chained_jobs(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/v1/repositories/analyze', [
            'github_url' => 'https://github.com/vuejs/core',
        ]);

        $response->assertStatus(202);

        Bus::assertChained([
            IngestGithubRepositoryJob::class,
            DetectTechStackJob::class,
        ]);
    }

    public function test_status_endpoint_returns_tech_stack_items(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/full-project',
            'owner' => 'owner',
            'name' => 'full-project',
            'default_branch' => 'main',
            'status' => 'completed',
            'description' => 'Full project description',
            'stars' => 300,
            'last_analyzed_at' => now(),
        ]);

        RepoTechStack::create([
            'repository_id' => $repository->id,
            'category' => 'framework',
            'name' => 'Laravel',
            'confidence' => 99.0,
        ]);

        RepoTechStack::create([
            'repository_id' => $repository->id,
            'category' => 'language',
            'name' => 'PHP',
            'confidence' => 95.0,
        ]);

        RepoTechStack::create([
            'repository_id' => $repository->id,
            'category' => 'devops',
            'name' => 'Docker',
            'confidence' => 95.0,
        ]);

        $response = $this->getJson("/api/v1/repositories/{$repository->id}/status");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'github_url',
                    'status',
                    'tech_stack' => [
                        '*' => [
                            'id',
                            'category',
                            'name',
                            'confidence',
                        ],
                    ],
                    'stats' => [
                        'tech_stack_count',
                    ],
                ],
            ])
            ->assertJson([
                'data' => [
                    'id' => $repository->id,
                    'status' => 'completed',
                    'stats' => [
                        'tech_stack_count' => 3,
                    ],
                ],
            ])
            ->assertJsonFragment(['name' => 'Laravel', 'category' => 'framework', 'confidence' => 99.0])
            ->assertJsonFragment(['name' => 'PHP', 'category' => 'language', 'confidence' => 95.0])
            ->assertJsonFragment(['name' => 'Docker', 'category' => 'devops', 'confidence' => 95.0]);
    }
}
