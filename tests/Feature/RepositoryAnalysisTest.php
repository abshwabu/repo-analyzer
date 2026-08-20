<?php

namespace Tests\Feature;

use App\Jobs\IngestGithubRepositoryJob;
use App\Models\RepoCommit;
use App\Models\RepoContributor;
use App\Models\Repository;
use App\Models\RepoTechStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RepositoryAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_endpoint_validates_url(): void
    {
        $response = $this->postJson('/api/v1/repositories/analyze', [
            'github_url' => 'not-a-url',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['github_url']);
    }

    public function test_analyze_endpoint_dispatches_job_and_returns_accepted(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/repositories/analyze', [
            'github_url' => 'https://github.com/torvalds/linux',
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'repository_id',
                    'status',
                    'github_url',
                    'owner',
                    'name',
                ],
            ])
            ->assertJson([
                'message' => 'Repository analysis queued successfully',
                'data' => [
                    'status' => 'pending',
                    'github_url' => 'https://github.com/torvalds/linux',
                    'owner' => 'torvalds',
                    'name' => 'linux',
                ],
            ]);

        $repoId = $response->json('data.repository_id');
        $this->assertDatabaseHas('repositories', [
            'id' => $repoId,
            'owner' => 'torvalds',
            'name' => 'linux',
            'status' => 'pending',
        ]);

        Queue::assertPushed(IngestGithubRepositoryJob::class, function ($job) use ($repoId) {
            return $job->repositoryId === $repoId;
        });
    }

    public function test_status_endpoint_returns_repository_progress_and_stats(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/sample',
            'owner' => 'owner',
            'name' => 'sample',
            'status' => 'completed',
            'description' => 'A test description',
            'stars' => 123,
            'license' => 'MIT',
            'last_analyzed_at' => now(),
        ]);

        RepoCommit::create([
            'repository_id' => $repository->id,
            'sha' => '1234567890',
            'message' => 'Test commit',
            'author_name' => 'Dev',
            'author_email' => 'dev@test.com',
            'committed_at' => now(),
        ]);

        RepoContributor::create([
            'repository_id' => $repository->id,
            'github_username' => 'Dev',
            'commit_count' => 1,
        ]);

        RepoTechStack::create([
            'repository_id' => $repository->id,
            'category' => 'language',
            'name' => 'PHP',
            'confidence' => 100.0,
        ]);

        $response = $this->getJson("/api/v1/repositories/{$repository->id}/status");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $repository->id,
                    'status' => 'completed',
                    'owner' => 'owner',
                    'name' => 'sample',
                    'description' => 'A test description',
                    'stars' => 123,
                    'license' => 'MIT',
                    'stats' => [
                        'commits_count' => 1,
                        'contributors_count' => 1,
                        'tech_stack_count' => 1,
                    ],
                ],
            ]);
    }

    public function test_status_endpoint_returns_404_for_unknown_repository(): void
    {
        $response = $this->getJson('/api/v1/repositories/99999/status');
        $response->assertStatus(404);
    }

    public function test_job_execution_processes_repository_and_updates_status(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/demo-project',
            'owner' => 'owner',
            'name' => 'demo-project',
            'status' => 'pending',
        ]);

        Http::fake([
            'https://api.github.com/repos/owner/demo-project' => Http::response([
                'description' => 'Demo project',
                'default_branch' => 'main',
                'stargazers_count' => 50,
                'license' => ['name' => 'Apache-2.0'],
                'created_at' => '2023-05-01T00:00:00Z',
            ], 200),
            'https://api.github.com/repos/owner/demo-project/commits*' => Http::response([
                [
                    'sha' => 'abcdef123456',
                    'commit' => [
                        'message' => 'feat: initial commit',
                        'author' => ['name' => 'alice', 'email' => 'alice@test.com', 'date' => '2023-05-01T12:00:00Z'],
                    ],
                ],
            ], 200),
            'https://api.github.com/repos/owner/demo-project/contributors*' => Http::response([
                [
                    'login' => 'alice',
                    'contributions' => 1,
                ],
            ], 200),
            'https://api.github.com/repos/owner/demo-project/languages' => Http::response([
                'PHP' => 5000,
            ], 200),
        ]);

        $job = new IngestGithubRepositoryJob($repository->id);
        app()->call([$job, 'handle']);

        $repository->refresh();
        $this->assertEquals('completed', $repository->status);
        $this->assertEquals('Demo project', $repository->description);
        $this->assertEquals(50, $repository->stars);
        $this->assertEquals('Apache-2.0', $repository->license);
        $this->assertDatabaseHas('repo_commits', ['repository_id' => $repository->id, 'sha' => 'abcdef123456']);
        $this->assertDatabaseHas('repo_contributors', ['repository_id' => $repository->id, 'github_username' => 'alice']);
    }
}
