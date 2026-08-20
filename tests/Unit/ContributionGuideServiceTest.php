<?php

namespace Tests\Unit;

use App\Models\RepoCommit;
use App\Models\Repository;
use App\Models\RepoTechStack;
use App\Services\ContributionGuideService;
use App\Services\GithubIngestionService;
use App\Services\RepoContextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContributionGuideServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ContributionGuideService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $ingestionService = new GithubIngestionService();
        $extractor = new RepoContextExtractor($ingestionService);
        $this->service = new ContributionGuideService($extractor);
    }

    public function test_detect_conventional_commits_identifies_prefixes(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/conv-repo',
            'owner' => 'owner',
            'name' => 'conv-repo',
        ]);

        $commit1 = RepoCommit::create([
            'repository_id' => $repository->id,
            'sha' => 'sha1',
            'message' => 'feat(auth): support token login',
        ]);

        $commit2 = RepoCommit::create([
            'repository_id' => $repository->id,
            'sha' => 'sha2',
            'message' => 'fix: resolve query race condition',
        ]);

        $commit3 = RepoCommit::create([
            'repository_id' => $repository->id,
            'sha' => 'sha3',
            'message' => 'docs: update changelog',
        ]);

        $commits = collect([$commit1, $commit2, $commit3]);

        $this->assertTrue($this->service->detectConventionalCommits($commits));
    }

    public function test_infer_setup_and_test_commands_for_laravel(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/laravel-guide-repo',
            'owner' => 'owner',
            'name' => 'laravel-guide-repo',
        ]);

        RepoTechStack::create([
            'repository_id' => $repository->id,
            'category' => 'framework',
            'name' => 'Laravel',
            'confidence' => 99.0,
        ]);

        $commands = $this->service->inferSetupAndTestCommands($repository);

        $this->assertContains('composer install', $commands['install']);
        $this->assertContains('php artisan test', $commands['test']);
    }

    public function test_generate_uses_existing_contributing_file_if_present(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/existing-guide-repo',
            'owner' => 'owner',
            'name' => 'existing-guide-repo',
        ]);

        $existingContent = "# Custom Guidelines\nPlease submit issues before PRs and follow our code of conduct thoroughly.";

        Http::fake([
            'https://api.github.com/repos/owner/existing-guide-repo/contents/CONTRIBUTING.md' => Http::response([
                'content' => base64_encode($existingContent),
                'encoding' => 'base64',
            ], 200),
            'https://api.github.com/*' => Http::response([], 200),
        ]);

        $guide = $this->service->generate($repository);

        $this->assertStringContainsString('Custom Guidelines', $guide);
        $this->assertStringContainsString('code of conduct', $guide);
    }

    public function test_generate_composes_inferred_guide_with_all_sections(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/inferred-guide-repo',
            'owner' => 'owner',
            'name' => 'inferred-guide-repo',
            'default_branch' => 'main',
        ]);

        RepoTechStack::create([
            'repository_id' => $repository->id,
            'category' => 'language',
            'name' => 'TypeScript',
            'confidence' => 90.0,
        ]);

        Http::fake([
            'https://api.github.com/*' => Http::response([], 404),
        ]);

        $guide = $this->service->generate($repository);

        $this->assertStringContainsString('## Contributing to inferred-guide-repo', $guide);
        $this->assertStringContainsString('### 1. Development Setup', $guide);
        $this->assertStringContainsString('### 2. Branch Naming Convention', $guide);
        $this->assertStringContainsString('### 3. Commit Message Guidelines', $guide);
        $this->assertStringContainsString('### 4. Running Tests & Linters', $guide);
        $this->assertStringContainsString('### 5. Pull Request Checklist', $guide);
        $this->assertStringContainsString('npm test', $guide);
    }
}
