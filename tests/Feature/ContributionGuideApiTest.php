<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Models\RepoTechStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContributionGuideApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_contributing_endpoint_returns_markdown(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/contrib-api-test-repo',
            'owner' => 'owner',
            'name' => 'contrib-api-test-repo',
            'status' => 'completed',
        ]);

        RepoTechStack::create([
            'repository_id' => $repository->id,
            'category' => 'framework',
            'name' => 'Laravel',
            'confidence' => 98.0,
        ]);

        Http::fake([
            'https://api.github.com/*' => Http::response([], 404),
        ]);

        $response = $this->getJson("/api/v1/repositories/{$repository->id}/contributing");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'repository_id',
                    'markdown',
                ],
            ])
            ->assertJson([
                'data' => [
                    'repository_id' => $repository->id,
                ],
            ]);

        $markdown = $response->json('data.markdown');
        $this->assertStringContainsString('## Contributing to contrib-api-test-repo', $markdown);
        $this->assertStringContainsString('Branch Naming', $markdown);
        $this->assertStringContainsString('Pull Request Checklist', $markdown);
    }

    public function test_contributing_endpoint_returns_404_for_unknown_repository(): void
    {
        $response = $this->getJson('/api/v1/repositories/9999/contributing');
        $response->assertStatus(404);
    }
}
