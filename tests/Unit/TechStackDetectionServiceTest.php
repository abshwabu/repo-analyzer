<?php

namespace Tests\Unit;

use App\Models\Repository;
use App\Models\RepoTechStack;
use App\Services\TechStackDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TechStackDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TechStackDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TechStackDetectionService();
    }

    public function test_scan_directory_detects_nodejs_and_react_stack(): void
    {
        $tempDir = sys_get_temp_dir() . '/test-node-stack-' . uniqid();
        File::makeDirectory($tempDir, 0755, true);

        try {
            File::put($tempDir . '/package.json', json_encode([
                'dependencies' => [
                    'react' => '^18.2.0',
                    'next' => '^13.0.0',
                    '@prisma/client' => '^4.0.0',
                    'tailwindcss' => '^3.0.0',
                ],
                'devDependencies' => [
                    'typescript' => '^5.0.0',
                    'jest' => '^29.0.0',
                ],
            ]));

            $results = $this->service->scanDirectory($tempDir);

            $names = array_column($results, 'name');
            $this->assertContains('TypeScript', $names);
            $this->assertContains('React', $names);
            $this->assertContains('Next.js', $names);
            $this->assertContains('Prisma', $names);
            $this->assertContains('Jest', $names);
            $this->assertContains('Tailwind CSS', $names);

            // Check high confidence for manifest
            foreach ($results as $item) {
                $this->assertGreaterThanOrEqual(90.0, $item['confidence']);
            }
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_scan_directory_detects_php_and_laravel_stack(): void
    {
        $tempDir = sys_get_temp_dir() . '/test-php-stack-' . uniqid();
        File::makeDirectory($tempDir, 0755, true);

        try {
            File::put($tempDir . '/composer.json', json_encode([
                'require' => [
                    'php' => '^8.2',
                    'laravel/framework' => '^11.0',
                ],
                'require-dev' => [
                    'phpunit/phpunit' => '^11.0',
                    'pestphp/pest' => '^2.0',
                ],
            ]));

            $results = $this->service->scanDirectory($tempDir);

            $names = array_column($results, 'name');
            $this->assertContains('PHP', $names);
            $this->assertContains('Laravel', $names);
            $this->assertContains('PHPUnit', $names);
            $this->assertContains('Pest', $names);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_scan_directory_detects_python_django_stack(): void
    {
        $tempDir = sys_get_temp_dir() . '/test-python-stack-' . uniqid();
        File::makeDirectory($tempDir, 0755, true);

        try {
            File::put($tempDir . '/requirements.txt', "django>=4.2\npytest==7.4.0\npsycopg2-binary==2.9.6\ncelery==5.3.1\n");

            $results = $this->service->scanDirectory($tempDir);

            $names = array_column($results, 'name');
            $this->assertContains('Python', $names);
            $this->assertContains('Django', $names);
            $this->assertContains('pytest', $names);
            $this->assertContains('PostgreSQL', $names);
            $this->assertContains('Celery', $names);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_scan_directory_detects_ruby_and_go_and_devops(): void
    {
        $tempDir = sys_get_temp_dir() . '/test-multi-stack-' . uniqid();
        File::makeDirectory($tempDir . '/.github/workflows', 0755, true);

        try {
            File::put($tempDir . '/Gemfile', "source 'https://rubygems.org'\ngem 'rails', '~> 7.0'\ngem 'rspec-rails'\n");
            File::put($tempDir . '/go.mod', "module example.com/app\n\ngo 1.21\n\nrequire github.com/gin-gonic/gin v1.9.1\n");
            File::put($tempDir . '/Dockerfile', "FROM alpine\n");
            File::put($tempDir . '/docker-compose.yml', "version: '3'\n");
            File::put($tempDir . '/.github/workflows/ci.yml', "name: CI\n");

            $results = $this->service->scanDirectory($tempDir);

            $names = array_column($results, 'name');
            $this->assertContains('Ruby', $names);
            $this->assertContains('Ruby on Rails', $names);
            $this->assertContains('RSpec', $names);
            $this->assertContains('Go', $names);
            $this->assertContains('Gin', $names);
            $this->assertContains('Docker', $names);
            $this->assertContains('Docker Compose', $names);
            $this->assertContains('GitHub Actions', $names);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_scan_directory_falls_back_to_file_extensions(): void
    {
        $tempDir = sys_get_temp_dir() . '/test-ext-stack-' . uniqid();
        File::makeDirectory($tempDir . '/src', 0755, true);

        try {
            File::put($tempDir . '/src/main.rs', 'fn main() {}');
            File::put($tempDir . '/src/app.py', 'print("hello")');

            $results = $this->service->scanDirectory($tempDir);

            $names = array_column($results, 'name');
            $this->assertContains('Rust', $names);
            $this->assertContains('Python', $names);

            // Medium confidence for extension inference
            foreach ($results as $item) {
                $this->assertEquals(70.0, $item['confidence']);
            }
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_cleanup_temp_dir_removes_directory(): void
    {
        $tempDir = sys_get_temp_dir() . '/test-cleanup-' . uniqid();
        File::makeDirectory($tempDir, 0755, true);
        File::put($tempDir . '/test.txt', 'sample');

        $this->assertTrue(File::exists($tempDir));
        $this->service->cleanupTempDir($tempDir);
        $this->assertFalse(File::exists($tempDir));
    }

    public function test_detect_merges_with_existing_github_languages(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/demo-repo',
            'owner' => 'owner',
            'name' => 'demo-repo',
            'default_branch' => 'main',
            'status' => 'processing',
        ]);

        // Existing language from GitHub API (Prompt 2)
        RepoTechStack::create([
            'repository_id' => $repository->id,
            'category' => 'language',
            'name' => 'PHP',
            'confidence' => 85.50,
        ]);

        $results = $this->service->detect($repository);

        $this->assertDatabaseHas('repo_tech_stack', [
            'repository_id' => $repository->id,
            'name' => 'PHP',
            'confidence' => 85.50,
        ]);
    }
}
