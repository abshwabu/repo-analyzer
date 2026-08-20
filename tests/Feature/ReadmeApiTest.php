<?php

namespace Tests\Feature;

use App\Models\GeneratedReadme;
use App\Models\Repository;
use App\Models\RepoTechStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReadmeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_readme_endpoint_creates_and_returns_markdown(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/readme-feature-repo',
            'owner' => 'owner',
            'name' => 'readme-feature-repo',
            'description' => 'A feature test repo',
            'status' => 'completed',
        ]);

        RepoTechStack::create([
            'repository_id' => $repository->id,
            'category' => 'language',
            'name' => 'PHP',
            'confidence' => 95.0,
        ]);

        Http::fake([
            'https://api.github.com/*' => Http::response([], 200),
        ]);

        $response = $this->postJson("/api/v1/repositories/{$repository->id}/generate-readme");

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'repository_id',
                    'readme_id',
                    'content',
                    'generated_at',
                ],
            ])
            ->assertJson([
                'data' => [
                    'repository_id' => $repository->id,
                ],
            ]);

        $this->assertStringContainsString('# readme-feature-repo', $response->json('data.content'));
    }

    public function test_show_readme_endpoint_returns_latest_readme(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/readme-show-repo',
            'owner' => 'owner',
            'name' => 'readme-show-repo',
            'status' => 'completed',
        ]);

        GeneratedReadme::create([
            'repository_id' => $repository->id,
            'content' => '# Old Readme',
            'generated_at' => now()->subDay(),
        ]);

        $latest = GeneratedReadme::create([
            'repository_id' => $repository->id,
            'content' => '# Latest Readme Content',
            'generated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/repositories/{$repository->id}/readme");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'repository_id' => $repository->id,
                    'readme_id' => $latest->id,
                    'content' => '# Latest Readme Content',
                ],
            ]);
    }

    public function test_show_readme_returns_404_when_not_generated(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/no-readme-repo',
            'owner' => 'owner',
            'name' => 'no-readme-repo',
            'status' => 'completed',
        ]);

        $response = $this->getJson("/api/v1/repositories/{$repository->id}/readme");

        $response->assertStatus(404);
    }

    public function test_download_readme_endpoint_returns_markdown_attachment(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/download-readme-repo',
            'owner' => 'owner',
            'name' => 'download-readme-repo',
            'status' => 'completed',
        ]);

        GeneratedReadme::create([
            'repository_id' => $repository->id,
            'content' => "# Downloadable Readme\n\nFull text here.",
            'generated_at' => now(),
        ]);

        $response = $this->get("/api/v1/repositories/{$repository->id}/readme/download");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="README.md"');

        $this->assertEquals("# Downloadable Readme\n\nFull text here.", $response->getContent());
    }

    public function test_download_readme_returns_404_when_not_generated(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/nodownload-repo',
            'owner' => 'owner',
            'name' => 'nodownload-repo',
            'status' => 'completed',
        ]);

        $response = $this->get("/api/v1/repositories/{$repository->id}/readme/download");

        $response->assertStatus(404);
    }
}
