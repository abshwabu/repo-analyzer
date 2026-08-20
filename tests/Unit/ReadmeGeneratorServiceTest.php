<?php

namespace Tests\Unit;

use App\Models\GeneratedReadme;
use App\Models\Repository;
use App\Models\RepoTechStack;
use App\Services\AiSummaryService;
use App\Services\GithubIngestionService;
use App\Services\ReadmeGeneratorService;
use App\Services\RepoContextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReadmeGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ReadmeGeneratorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $ingestionService = new GithubIngestionService();
        $extractor = new RepoContextExtractor($ingestionService);
        $aiService = new AiSummaryService($extractor);
        $this->service = new ReadmeGeneratorService($aiService, $extractor);
    }

    public function test_compose_markdown_includes_all_standard_sections(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/markdown-repo',
            'owner' => 'owner',
            'name' => 'markdown-repo',
            'description' => 'A wonderful tool.',
            'license' => 'MIT',
            'default_branch' => 'main',
            'stars' => 42,
        ]);

        RepoTechStack::create([
            'repository_id' => $repository->id,
            'category' => 'framework',
            'name' => 'Vue.js',
            'confidence' => 95.0,
        ]);

        $summary = [
            'project_overview' => 'An automated test generator for developers.',
            'architecture' => 'Frontend components in src/ and state in stores/.',
            'getting_started' => [
                'prerequisites' => ['Node.js 18+'],
                'install_commands' => ['npm install'],
                'run_commands' => ['npm run dev'],
                'test_commands' => ['npm test'],
                'instructions' => 'Clone and start dev server.',
            ],
        ];

        $scripts = [
            'npm' => [
                'npm run dev' => 'vite',
                'npm run build' => 'vite build',
            ],
        ];

        $markdown = $this->service->composeMarkdown($repository, $summary, $scripts);

        $this->assertStringContainsString('# markdown-repo', $markdown);
        $this->assertStringContainsString('## Table of Contents', $markdown);
        $this->assertStringContainsString('## About the Project', $markdown);
        $this->assertStringContainsString('## Built With', $markdown);
        $this->assertStringContainsString('Vue.js', $markdown);
        $this->assertStringContainsString('## Architecture', $markdown);
        $this->assertStringContainsString('## Getting Started', $markdown);
        $this->assertStringContainsString('### Prerequisites', $markdown);
        $this->assertStringContainsString('### Installation', $markdown);
        $this->assertStringContainsString('## Usage & Available Scripts', $markdown);
        $this->assertStringContainsString('npm run dev', $markdown);
        $this->assertStringContainsString('## Contributing', $markdown);
        $this->assertStringContainsString('## License', $markdown);
        $this->assertStringContainsString('MIT', $markdown);
    }

    public function test_generate_persists_to_generated_readmes(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/persist-repo',
            'owner' => 'owner',
            'name' => 'persist-repo',
            'status' => 'completed',
        ]);

        Http::fake([
            'https://api.github.com/*' => Http::response([], 200),
        ]);

        $readme = $this->service->generate($repository);

        $this->assertInstanceOf(GeneratedReadme::class, $readme);
        $this->assertDatabaseHas('generated_readmes', [
            'id' => $readme->id,
            'repository_id' => $repository->id,
        ]);
        $this->assertStringContainsString('# persist-repo', $readme->content);
    }
}
