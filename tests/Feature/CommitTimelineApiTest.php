<?php

namespace Tests\Feature;

use App\Models\RepoCommit;
use App\Models\RepoContributor;
use App\Models\Repository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommitTimelineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_endpoint_returns_monthly_volume_and_significant_commits(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/timeline-api-repo',
            'owner' => 'owner',
            'name' => 'timeline-api-repo',
            'status' => 'completed',
        ]);

        RepoCommit::create([
            'repository_id' => $repository->id,
            'sha' => '1111111111111111',
            'message' => 'feat: initial setup',
            'author_name' => 'John Doe',
            'author_email' => 'john@test.com',
            'committed_at' => Carbon::parse('2024-01-15 10:00:00'),
            'additions' => 50,
            'deletions' => 10,
        ]);

        RepoCommit::create([
            'repository_id' => $repository->id,
            'sha' => '2222222222222222',
            'message' => 'docs: update readme',
            'author_name' => 'John Doe',
            'author_email' => 'john@test.com',
            'committed_at' => Carbon::parse('2024-01-20 14:00:00'),
            'additions' => 5,
            'deletions' => 2,
        ]);

        $response = $this->getJson("/api/v1/repositories/{$repository->id}/timeline");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'repository_id',
                    'total_commits',
                    'monthly_volume' => [
                        '*' => [
                            'period',
                            'year',
                            'month',
                            'count',
                        ],
                    ],
                    'significant_commits' => [
                        '*' => [
                            'id',
                            'sha',
                            'short_sha',
                            'message',
                            'author_name',
                            'author_email',
                            'committed_at',
                            'additions',
                            'deletions',
                            'reason',
                        ],
                    ],
                ],
            ])
            ->assertJson([
                'data' => [
                    'repository_id' => $repository->id,
                    'total_commits' => 2,
                    'monthly_volume' => [
                        [
                            'period' => '2024-01',
                            'year' => 2024,
                            'month' => 1,
                            'count' => 2,
                        ],
                    ],
                    'significant_commits' => [
                        [
                            'sha' => '1111111111111111',
                            'short_sha' => '1111111',
                            'message' => 'feat: initial setup',
                            'author_name' => 'John Doe',
                            'additions' => 50,
                            'deletions' => 10,
                            'reason' => 'feature',
                        ],
                    ],
                ],
            ]);
    }

    public function test_timeline_endpoint_returns_404_for_unknown_repository(): void
    {
        $response = $this->getJson('/api/v1/repositories/9999/timeline');
        $response->assertStatus(404);
    }

    public function test_contributors_endpoint_returns_activity_summary(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/contrib-api-repo',
            'owner' => 'owner',
            'name' => 'contrib-api-repo',
            'status' => 'completed',
        ]);

        RepoContributor::create([
            'repository_id' => $repository->id,
            'github_username' => 'alice',
            'commit_count' => 60,
            'first_commit_at' => Carbon::parse('2023-01-01'),
            'last_commit_at' => Carbon::parse('2024-01-01'),
        ]);

        RepoContributor::create([
            'repository_id' => $repository->id,
            'github_username' => 'bob',
            'commit_count' => 40,
            'first_commit_at' => Carbon::parse('2023-05-01'),
            'last_commit_at' => Carbon::parse('2023-11-01'),
        ]);

        $response = $this->getJson("/api/v1/repositories/{$repository->id}/contributors");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'repository_id',
                    'total_contributors',
                    'total_commits',
                    'contributors' => [
                        '*' => [
                            'id',
                            'github_username',
                            'commit_count',
                            'percentage_share',
                            'first_commit_at',
                            'last_commit_at',
                        ],
                    ],
                ],
            ])
            ->assertJson([
                'data' => [
                    'repository_id' => $repository->id,
                    'total_contributors' => 2,
                    'total_commits' => 100,
                    'contributors' => [
                        [
                            'github_username' => 'alice',
                            'commit_count' => 60,
                            'percentage_share' => 60.0,
                        ],
                        [
                            'github_username' => 'bob',
                            'commit_count' => 40,
                            'percentage_share' => 40.0,
                        ],
                    ],
                ],
            ]);
    }

    public function test_contributors_endpoint_returns_404_for_unknown_repository(): void
    {
        $response = $this->getJson('/api/v1/repositories/9999/contributors');
        $response->assertStatus(404);
    }
}
