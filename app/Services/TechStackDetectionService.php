<?php

namespace App\Services;

use App\Models\RepoTechStack;
use App\Models\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class TechStackDetectionService
{
    /**
     * Detect technology stack for a repository.
     *
     * @param Repository $repository
     * @return array<int, array{category: string, name: string, confidence: float}>
     */
    public function detect(Repository $repository): array
    {
        $tempDir = sys_get_temp_dir() . '/repo-scan-' . $repository->id . '-' . uniqid();

        try {
            $this->cloneRepository($repository, $tempDir);
            $detected = $this->scanDirectory($tempDir);
        } catch (Throwable $e) {
            Log::warning("TechStackDetectionService: Clone/scan failed for repository #{$repository->id}: " . $e->getMessage());
            $detected = [];
        } finally {
            $this->cleanupTempDir($tempDir);
        }

        // Cross-check & merge with existing languages from GitHub API
        $merged = $this->mergeWithExistingLanguages($repository, $detected);

        // Persist to repo_tech_stack
        if (!empty($merged)) {
            $itemsToUpsert = array_map(function ($item) use ($repository) {
                return [
                    'repository_id' => $repository->id,
                    'category' => $item['category'],
                    'name' => $item['name'],
                    'confidence' => $item['confidence'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }, $merged);

            RepoTechStack::upsert(
                $itemsToUpsert,
                ['repository_id', 'category', 'name'],
                ['confidence', 'updated_at']
            );
        }

        return $merged;
    }

    /**
     * Clone repository into temporary directory using git shallow clone.
     *
     * @param Repository $repository
     * @param string $tempDir
     * @return void
     */
    public function cloneRepository(Repository $repository, string $tempDir): void
    {
        $cloneUrl = $this->buildCloneUrl($repository);
        $branch = $repository->default_branch ?: 'main';

        // Attempt 1: Clone with branch
        $process = new Process([
            'git',
            'clone',
            '--depth',
            '1',
            '--single-branch',
            '--branch',
            $branch,
            $cloneUrl,
            $tempDir,
        ]);

        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::info("Branch {$branch} clone failed, trying default clone for {$repository->github_url}");

            // Attempt 2: Fallback clone without specifying branch
            if (File::exists($tempDir)) {
                File::deleteDirectory($tempDir);
            }

            $fallbackProcess = new Process([
                'git',
                'clone',
                '--depth',
                '1',
                $cloneUrl,
                $tempDir,
            ]);

            $fallbackProcess->setTimeout(120);
            $fallbackProcess->run();

            if (!$fallbackProcess->isSuccessful()) {
                throw new \RuntimeException("Git clone failed: " . $fallbackProcess->getErrorOutput());
            }
        }
    }

    /**
     * Build authenticated or public git clone URL.
     */
    protected function buildCloneUrl(Repository $repository): string
    {
        $token = config('services.github.token');
        $owner = $repository->owner;
        $name = $repository->name;

        if (!empty($token)) {
            return "https://{$token}@github.com/{$owner}/{$name}.git";
        }

        return "https://github.com/{$owner}/{$name}.git";
    }

    /**
     * Clean up temporary directory.
     */
    public function cleanupTempDir(string $tempDir): void
    {
        try {
            if (File::exists($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        } catch (Throwable $e) {
            Log::warning("Failed cleaning up temp dir {$tempDir}: " . $e->getMessage());
        }
    }

    /**
     * Scan the cloned repository directory for tech stack items.
     *
     * @param string $dir
     * @return array<int, array{category: string, name: string, confidence: float}>
     */
    public function scanDirectory(string $dir): array
    {
        if (!File::isDirectory($dir)) {
            return [];
        }

        $detected = [];

        // 1. Scan package.json (Node, React, Vue, Next, Express, Nest, Vite, Jest, Prisma, etc.)
        $detected = array_merge($detected, $this->scanPackageJson($dir));

        // 2. Scan composer.json (PHP, Laravel, Symfony, PHPUnit, Pest, Doctrine, etc.)
        $detected = array_merge($detected, $this->scanComposerJson($dir));

        // 3. Scan Python manifests (Django, Flask, FastAPI, pytest, SQLAlchemy, etc.)
        $detected = array_merge($detected, $this->scanPython($dir));

        // 4. Scan Ruby manifests (Rails, Sinatra, RSpec, etc.)
        $detected = array_merge($detected, $this->scanRuby($dir));

        // 5. Scan Go manifests (Gin, Echo, Fiber, GORM, etc.)
        $detected = array_merge($detected, $this->scanGo($dir));

        // 6. Scan Rust manifests (Actix, Axum, Rocket, Diesel, etc.)
        $detected = array_merge($detected, $this->scanRust($dir));

        // 7. Scan Java / Kotlin manifests (Spring Boot, Quarkus, JUnit, etc.)
        $detected = array_merge($detected, $this->scanJava($dir));

        // 8. Scan DevOps & Containerization files (Docker, Compose, GitHub Actions, CI, K8s, Terraform)
        $detected = array_merge($detected, $this->scanDevops($dir));

        // 9. Scan file extensions as fallback for languages if no manifest languages detected
        $hasLanguage = false;
        foreach ($detected as $item) {
            if ($item['category'] === 'language') {
                $hasLanguage = true;
                break;
            }
        }

        if (!$hasLanguage) {
            $detected = array_merge($detected, $this->scanFileExtensions($dir));
        }

        return $this->deduplicateTechStack($detected);
    }

    /**
     * Scan package.json for JS/TS, frameworks, testing, database, and devops tools.
     */
    protected function scanPackageJson(string $dir): array
    {
        $results = [];
        $packageJsonPath = $dir . '/package.json';

        if (!File::exists($packageJsonPath)) {
            return $results;
        }

        try {
            $content = json_decode(File::get($packageJsonPath), true);
            if (!is_array($content)) {
                return $results;
            }

            $deps = array_merge(
                $content['dependencies'] ?? [],
                $content['devDependencies'] ?? [],
                $content['peerDependencies'] ?? []
            );

            // Language: TypeScript or JavaScript
            $hasTs = isset($deps['typescript']) || File::exists($dir . '/tsconfig.json');
            if ($hasTs) {
                $results[] = ['category' => 'language', 'name' => 'TypeScript', 'confidence' => 99.0];
            } else {
                $results[] = ['category' => 'language', 'name' => 'JavaScript', 'confidence' => 95.0];
            }

            // Framework mappings
            $frameworkMap = [
                'react' => ['name' => 'React', 'confidence' => 98.0],
                'react-dom' => ['name' => 'React', 'confidence' => 98.0],
                'vue' => ['name' => 'Vue.js', 'confidence' => 98.0],
                '@angular/core' => ['name' => 'Angular', 'confidence' => 98.0],
                'next' => ['name' => 'Next.js', 'confidence' => 98.0],
                'nuxt' => ['name' => 'Nuxt.js', 'confidence' => 98.0],
                'nuxt3' => ['name' => 'Nuxt.js', 'confidence' => 98.0],
                'svelte' => ['name' => 'Svelte', 'confidence' => 98.0],
                '@sveltejs/kit' => ['name' => 'SvelteKit', 'confidence' => 98.0],
                'express' => ['name' => 'Express', 'confidence' => 95.0],
                '@nestjs/core' => ['name' => 'NestJS', 'confidence' => 98.0],
                'fastify' => ['name' => 'Fastify', 'confidence' => 95.0],
                '@remix-run/react' => ['name' => 'Remix', 'confidence' => 98.0],
                'astro' => ['name' => 'Astro', 'confidence' => 98.0],
                'electron' => ['name' => 'Electron', 'confidence' => 95.0],
                'react-native' => ['name' => 'React Native', 'confidence' => 98.0],
                'tailwindcss' => ['name' => 'Tailwind CSS', 'confidence' => 95.0],
                'pinia' => ['name' => 'Pinia', 'confidence' => 95.0],
                'redux' => ['name' => 'Redux', 'confidence' => 95.0],
                '@reduxjs/toolkit' => ['name' => 'Redux', 'confidence' => 95.0],
            ];

            foreach ($frameworkMap as $pkg => $meta) {
                if (isset($deps[$pkg])) {
                    $results[] = ['category' => 'framework', 'name' => $meta['name'], 'confidence' => $meta['confidence']];
                }
            }

            // Testing tools
            $testingMap = [
                'jest' => ['name' => 'Jest', 'confidence' => 95.0],
                '@types/jest' => ['name' => 'Jest', 'confidence' => 90.0],
                'vitest' => ['name' => 'Vitest', 'confidence' => 95.0],
                'cypress' => ['name' => 'Cypress', 'confidence' => 95.0],
                '@playwright/test' => ['name' => 'Playwright', 'confidence' => 95.0],
                'mocha' => ['name' => 'Mocha', 'confidence' => 90.0],
                '@testing-library/react' => ['name' => 'Testing Library', 'confidence' => 90.0],
                '@testing-library/vue' => ['name' => 'Testing Library', 'confidence' => 90.0],
            ];

            foreach ($testingMap as $pkg => $meta) {
                if (isset($deps[$pkg])) {
                    $results[] = ['category' => 'testing', 'name' => $meta['name'], 'confidence' => $meta['confidence']];
                }
            }

            // Database / ORMs
            $dbMap = [
                'prisma' => ['name' => 'Prisma', 'confidence' => 95.0],
                '@prisma/client' => ['name' => 'Prisma', 'confidence' => 95.0],
                'mongoose' => ['name' => 'MongoDB / Mongoose', 'confidence' => 95.0],
                'typeorm' => ['name' => 'TypeORM', 'confidence' => 95.0],
                'sequelize' => ['name' => 'Sequelize', 'confidence' => 95.0],
                'drizzle-orm' => ['name' => 'Drizzle ORM', 'confidence' => 95.0],
                'pg' => ['name' => 'PostgreSQL', 'confidence' => 90.0],
                'mysql2' => ['name' => 'MySQL', 'confidence' => 90.0],
                'mysql' => ['name' => 'MySQL', 'confidence' => 90.0],
                'ioredis' => ['name' => 'Redis', 'confidence' => 90.0],
                'redis' => ['name' => 'Redis', 'confidence' => 90.0],
                'better-sqlite3' => ['name' => 'SQLite', 'confidence' => 90.0],
                'sqlite3' => ['name' => 'SQLite', 'confidence' => 90.0],
            ];

            foreach ($dbMap as $pkg => $meta) {
                if (isset($deps[$pkg])) {
                    $results[] = ['category' => 'database', 'name' => $meta['name'], 'confidence' => $meta['confidence']];
                }
            }

            // DevOps / Build Tooling
            $devopsMap = [
                'vite' => ['name' => 'Vite', 'confidence' => 90.0],
                'webpack' => ['name' => 'Webpack', 'confidence' => 90.0],
            ];

            foreach ($devopsMap as $pkg => $meta) {
                if (isset($deps[$pkg])) {
                    $results[] = ['category' => 'devops', 'name' => $meta['name'], 'confidence' => $meta['confidence']];
                }
            }
        } catch (Throwable $e) {
            Log::warning("Error parsing package.json: " . $e->getMessage());
        }

        return $results;
    }

    /**
     * Scan composer.json for PHP, frameworks, testing, and database tools.
     */
    protected function scanComposerJson(string $dir): array
    {
        $results = [];
        $composerPath = $dir . '/composer.json';

        if (!File::exists($composerPath)) {
            return $results;
        }

        try {
            $content = json_decode(File::get($composerPath), true);
            if (!is_array($content)) {
                return $results;
            }

            $results[] = ['category' => 'language', 'name' => 'PHP', 'confidence' => 95.0];

            $deps = array_merge(
                $content['require'] ?? [],
                $content['require-dev'] ?? []
            );

            // Frameworks
            $frameworkMap = [
                'laravel/framework' => ['name' => 'Laravel', 'confidence' => 99.0],
                'symfony/framework-bundle' => ['name' => 'Symfony', 'confidence' => 99.0],
                'symfony/symfony' => ['name' => 'Symfony', 'confidence' => 99.0],
                'slim/slim' => ['name' => 'Slim', 'confidence' => 95.0],
                'livewire/livewire' => ['name' => 'Livewire', 'confidence' => 95.0],
                'filament/filament' => ['name' => 'Filament', 'confidence' => 95.0],
                'inertiajs/inertia-laravel' => ['name' => 'Inertia.js', 'confidence' => 95.0],
                'cakephp/cakephp' => ['name' => 'CakePHP', 'confidence' => 95.0],
                'yiisoft/yii2' => ['name' => 'Yii2', 'confidence' => 95.0],
            ];

            foreach ($frameworkMap as $pkg => $meta) {
                if (isset($deps[$pkg])) {
                    $results[] = ['category' => 'framework', 'name' => $meta['name'], 'confidence' => $meta['confidence']];
                }
            }

            // Testing
            $testingMap = [
                'phpunit/phpunit' => ['name' => 'PHPUnit', 'confidence' => 95.0],
                'pestphp/pest' => ['name' => 'Pest', 'confidence' => 95.0],
                'mockery/mockery' => ['name' => 'Mockery', 'confidence' => 90.0],
                'behat/behat' => ['name' => 'Behat', 'confidence' => 90.0],
            ];

            foreach ($testingMap as $pkg => $meta) {
                if (isset($deps[$pkg])) {
                    $results[] = ['category' => 'testing', 'name' => $meta['name'], 'confidence' => $meta['confidence']];
                }
            }

            // Database
            $dbMap = [
                'doctrine/orm' => ['name' => 'Doctrine ORM', 'confidence' => 95.0],
                'illuminate/database' => ['name' => 'Eloquent', 'confidence' => 95.0],
            ];

            foreach ($dbMap as $pkg => $meta) {
                if (isset($deps[$pkg])) {
                    $results[] = ['category' => 'database', 'name' => $meta['name'], 'confidence' => $meta['confidence']];
                }
            }
        } catch (Throwable $e) {
            Log::warning("Error parsing composer.json: " . $e->getMessage());
        }

        return $results;
    }

    /**
     * Scan Python files (requirements.txt, pyproject.toml, Pipfile, setup.py).
     */
    protected function scanPython(string $dir): array
    {
        $results = [];
        $hasPythonManifest = false;
        $combinedText = '';

        $manifestFiles = ['requirements.txt', 'pyproject.toml', 'Pipfile', 'setup.py', 'setup.cfg'];
        foreach ($manifestFiles as $file) {
            $path = $dir . '/' . $file;
            if (File::exists($path)) {
                $hasPythonManifest = true;
                $combinedText .= "\n" . File::get($path);
            }
        }

        if (!$hasPythonManifest) {
            return $results;
        }

        $results[] = ['category' => 'language', 'name' => 'Python', 'confidence' => 95.0];

        $lowerText = strtolower($combinedText);

        // Frameworks
        $frameworkMap = [
            'django' => ['name' => 'Django', 'confidence' => 98.0],
            'flask' => ['name' => 'Flask', 'confidence' => 98.0],
            'fastapi' => ['name' => 'FastAPI', 'confidence' => 98.0],
            'tornado' => ['name' => 'Tornado', 'confidence' => 95.0],
            'celery' => ['name' => 'Celery', 'confidence' => 95.0],
            'torch' => ['name' => 'PyTorch', 'confidence' => 95.0],
            'pytorch' => ['name' => 'PyTorch', 'confidence' => 95.0],
            'tensorflow' => ['name' => 'TensorFlow', 'confidence' => 95.0],
        ];

        foreach ($frameworkMap as $key => $meta) {
            if (preg_match('/(?:^|[\s"\'=,;])' . preg_quote($key, '/') . '(?:[\s"\'=><~,;_\-]|$)/i', $lowerText)) {
                $results[] = ['category' => 'framework', 'name' => $meta['name'], 'confidence' => $meta['confidence']];
            }
        }

        // Testing
        $testingMap = [
            'pytest' => ['name' => 'pytest', 'confidence' => 95.0],
            'tox' => ['name' => 'tox', 'confidence' => 90.0],
        ];

        foreach ($testingMap as $key => $meta) {
            if (preg_match('/(?:^|[\s"\'=,;])' . preg_quote($key, '/') . '(?:[\s"\'=><~,;_\-]|$)/i', $lowerText)) {
                $results[] = ['category' => 'testing', 'name' => $meta['name'], 'confidence' => $meta['confidence']];
            }
        }

        // Database
        $dbMap = [
            'sqlalchemy' => ['name' => 'SQLAlchemy', 'confidence' => 95.0],
            'tortoise-orm' => ['name' => 'Tortoise ORM', 'confidence' => 95.0],
            'peewee' => ['name' => 'Peewee', 'confidence' => 95.0],
            'psycopg2' => ['name' => 'PostgreSQL', 'confidence' => 90.0],
            'psycopg' => ['name' => 'PostgreSQL', 'confidence' => 90.0],
            'asyncpg' => ['name' => 'PostgreSQL', 'confidence' => 90.0],
            'pymongo' => ['name' => 'MongoDB', 'confidence' => 90.0],
            'redis' => ['name' => 'Redis', 'confidence' => 90.0],
        ];

        foreach ($dbMap as $key => $meta) {
            if (preg_match('/(?:^|[\s"\'=,;])' . preg_quote($key, '/') . '(?:-binary)?(?:[\s"\'=><~,;_\-]|$)/i', $lowerText)) {
                $results[] = ['category' => 'database', 'name' => $meta['name'], 'confidence' => $meta['confidence']];
            }
        }

        return $results;
    }

    /**
     * Scan Ruby Gemfile.
     */
    protected function scanRuby(string $dir): array
    {
        $results = [];
        $gemfilePath = $dir . '/Gemfile';

        if (!File::exists($gemfilePath)) {
            return $results;
        }

        $results[] = ['category' => 'language', 'name' => 'Ruby', 'confidence' => 95.0];
        $text = File::get($gemfilePath);

        if (preg_match('/gem\s+[\'"]rails[\'"]/i', $text)) {
            $results[] = ['category' => 'framework', 'name' => 'Ruby on Rails', 'confidence' => 99.0];
        }
        if (preg_match('/gem\s+[\'"]sinatra[\'"]/i', $text)) {
            $results[] = ['category' => 'framework', 'name' => 'Sinatra', 'confidence' => 95.0];
        }
        if (preg_match('/gem\s+[\'"]rspec[\'"]/i', $text) || preg_match('/gem\s+[\'"]rspec-rails[\'"]/i', $text)) {
            $results[] = ['category' => 'testing', 'name' => 'RSpec', 'confidence' => 95.0];
        }
        if (preg_match('/gem\s+[\'"]pg[\'"]/i', $text)) {
            $results[] = ['category' => 'database', 'name' => 'PostgreSQL', 'confidence' => 90.0];
        }
        if (preg_match('/gem\s+[\'"]mysql2[\'"]/i', $text)) {
            $results[] = ['category' => 'database', 'name' => 'MySQL', 'confidence' => 90.0];
        }
        if (preg_match('/gem\s+[\'"]redis[\'"]/i', $text)) {
            $results[] = ['category' => 'database', 'name' => 'Redis', 'confidence' => 90.0];
        }

        return $results;
    }

    /**
     * Scan Go go.mod.
     */
    protected function scanGo(string $dir): array
    {
        $results = [];
        $goModPath = $dir . '/go.mod';

        if (!File::exists($goModPath)) {
            return $results;
        }

        $results[] = ['category' => 'language', 'name' => 'Go', 'confidence' => 95.0];
        $text = File::get($goModPath);

        if (str_contains($text, 'github.com/gin-gonic/gin')) {
            $results[] = ['category' => 'framework', 'name' => 'Gin', 'confidence' => 98.0];
        }
        if (str_contains($text, 'github.com/labstack/echo')) {
            $results[] = ['category' => 'framework', 'name' => 'Echo', 'confidence' => 98.0];
        }
        if (str_contains($text, 'github.com/gofiber/fiber')) {
            $results[] = ['category' => 'framework', 'name' => 'Fiber', 'confidence' => 98.0];
        }
        if (str_contains($text, 'github.com/go-chi/chi')) {
            $results[] = ['category' => 'framework', 'name' => 'Chi', 'confidence' => 95.0];
        }
        if (str_contains($text, 'gorm.io/gorm')) {
            $results[] = ['category' => 'database', 'name' => 'GORM', 'confidence' => 95.0];
        }
        if (str_contains($text, 'github.com/jmoiron/sqlx')) {
            $results[] = ['category' => 'database', 'name' => 'sqlx', 'confidence' => 90.0];
        }
        if (str_contains($text, 'github.com/redis/go-redis') || str_contains($text, 'github.com/go-redis/redis')) {
            $results[] = ['category' => 'database', 'name' => 'Redis', 'confidence' => 90.0];
        }

        return $results;
    }

    /**
     * Scan Rust Cargo.toml.
     */
    protected function scanRust(string $dir): array
    {
        $results = [];
        $cargoPath = $dir . '/Cargo.toml';

        if (!File::exists($cargoPath)) {
            return $results;
        }

        $results[] = ['category' => 'language', 'name' => 'Rust', 'confidence' => 95.0];
        $text = File::get($cargoPath);

        if (preg_match('/actix-web/i', $text)) {
            $results[] = ['category' => 'framework', 'name' => 'Actix Web', 'confidence' => 98.0];
        }
        if (preg_match('/axum/i', $text)) {
            $results[] = ['category' => 'framework', 'name' => 'Axum', 'confidence' => 98.0];
        }
        if (preg_match('/rocket/i', $text)) {
            $results[] = ['category' => 'framework', 'name' => 'Rocket', 'confidence' => 98.0];
        }
        if (preg_match('/tokio/i', $text)) {
            $results[] = ['category' => 'framework', 'name' => 'Tokio', 'confidence' => 95.0];
        }
        if (preg_match('/diesel/i', $text)) {
            $results[] = ['category' => 'database', 'name' => 'Diesel', 'confidence' => 95.0];
        }
        if (preg_match('/sqlx/i', $text)) {
            $results[] = ['category' => 'database', 'name' => 'SQLx', 'confidence' => 95.0];
        }

        return $results;
    }

    /**
     * Scan Java/Kotlin build files.
     */
    protected function scanJava(string $dir): array
    {
        $results = [];
        $pomPath = $dir . '/pom.xml';
        $gradlePath = $dir . '/build.gradle';
        $gradleKtsPath = $dir . '/build.gradle.kts';

        $hasJavaBuild = File::exists($pomPath) || File::exists($gradlePath) || File::exists($gradleKtsPath);
        if (!$hasJavaBuild) {
            return $results;
        }

        $isKotlin = File::exists($gradleKtsPath);
        $results[] = [
            'category' => 'language',
            'name' => $isKotlin ? 'Kotlin' : 'Java',
            'confidence' => 95.0,
        ];

        $combined = '';
        if (File::exists($pomPath)) $combined .= File::get($pomPath);
        if (File::exists($gradlePath)) $combined .= File::get($gradlePath);
        if (File::exists($gradleKtsPath)) $combined .= File::get($gradleKtsPath);

        if (str_contains($combined, 'spring-boot') || str_contains($combined, 'org.springframework.boot')) {
            $results[] = ['category' => 'framework', 'name' => 'Spring Boot', 'confidence' => 99.0];
        }
        if (str_contains($combined, 'quarkus')) {
            $results[] = ['category' => 'framework', 'name' => 'Quarkus', 'confidence' => 95.0];
        }
        if (str_contains($combined, 'junit')) {
            $results[] = ['category' => 'testing', 'name' => 'JUnit', 'confidence' => 95.0];
        }

        return $results;
    }

    /**
     * Scan DevOps and Containerization files.
     */
    protected function scanDevops(string $dir): array
    {
        $results = [];

        // Docker
        if (File::exists($dir . '/Dockerfile') || File::exists($dir . '/Containerfile')) {
            $results[] = ['category' => 'devops', 'name' => 'Docker', 'confidence' => 95.0];
        }

        // Docker Compose
        if (
            File::exists($dir . '/docker-compose.yml') ||
            File::exists($dir . '/docker-compose.yaml') ||
            File::exists($dir . '/compose.yml') ||
            File::exists($dir . '/compose.yaml')
        ) {
            $results[] = ['category' => 'devops', 'name' => 'Docker Compose', 'confidence' => 95.0];
        }

        // GitHub Actions
        $workflowsDir = $dir . '/.github/workflows';
        if (File::isDirectory($workflowsDir)) {
            $workflowFiles = File::glob($workflowsDir . '/*.{yml,yaml}', GLOB_BRACE);
            if (!empty($workflowFiles)) {
                $results[] = ['category' => 'devops', 'name' => 'GitHub Actions', 'confidence' => 95.0];
            }
        }

        // GitLab CI
        if (File::exists($dir . '/.gitlab-ci.yml')) {
            $results[] = ['category' => 'devops', 'name' => 'GitLab CI', 'confidence' => 95.0];
        }

        // Jenkins
        if (File::exists($dir . '/Jenkinsfile')) {
            $results[] = ['category' => 'devops', 'name' => 'Jenkins', 'confidence' => 95.0];
        }

        // Kubernetes / Helm
        if (
            File::isDirectory($dir . '/k8s') ||
            File::isDirectory($dir . '/kubernetes') ||
            File::isDirectory($dir . '/helm') ||
            File::exists($dir . '/Chart.yaml')
        ) {
            $results[] = ['category' => 'devops', 'name' => 'Kubernetes', 'confidence' => 90.0];
        }

        // Terraform
        $tfFiles = File::glob($dir . '/*.tf');
        if (File::isDirectory($dir . '/terraform') || !empty($tfFiles)) {
            $results[] = ['category' => 'devops', 'name' => 'Terraform', 'confidence' => 90.0];
        }

        return $results;
    }

    /**
     * Scan file extensions as fallback for language detection.
     */
    protected function scanFileExtensions(string $dir): array
    {
        $results = [];
        $extMap = [
            'php' => 'PHP',
            'py' => 'Python',
            'js' => 'JavaScript',
            'ts' => 'TypeScript',
            'jsx' => 'JavaScript',
            'tsx' => 'TypeScript',
            'go' => 'Go',
            'rs' => 'Rust',
            'rb' => 'Ruby',
            'java' => 'Java',
            'kt' => 'Kotlin',
            'cs' => 'C#',
            'cpp' => 'C++',
            'c' => 'C',
            'swift' => 'Swift',
        ];

        $foundLanguages = [];
        $files = File::allFiles($dir);

        foreach ($files as $file) {
            $ext = strtolower($file->getExtension());
            if (isset($extMap[$ext])) {
                $lang = $extMap[$ext];
                $foundLanguages[$lang] = ($foundLanguages[$lang] ?? 0) + 1;
            }
        }

        foreach ($foundLanguages as $lang => $count) {
            $results[] = [
                'category' => 'language',
                'name' => $lang,
                'confidence' => 70.0, // Medium confidence for extension inference
            ];
        }

        return $results;
    }

    /**
     * Deduplicate detected tech stack array, keeping the highest confidence.
     *
     * @param array<int, array{category: string, name: string, confidence: float}> $items
     * @return array<int, array{category: string, name: string, confidence: float}>
     */
    protected function deduplicateTechStack(array $items): array
    {
        $map = [];

        foreach ($items as $item) {
            $key = $item['category'] . '::' . $item['name'];
            if (!isset($map[$key]) || $item['confidence'] > $map[$key]['confidence']) {
                $map[$key] = $item;
            }
        }

        return array_values($map);
    }

    /**
     * Merge scanned results with languages previously fetched from GitHub API.
     *
     * @param Repository $repository
     * @param array<int, array{category: string, name: string, confidence: float}> $scanned
     * @return array<int, array{category: string, name: string, confidence: float}>
     */
    protected function mergeWithExistingLanguages(Repository $repository, array $scanned): array
    {
        $existing = RepoTechStack::where('repository_id', $repository->id)->get();
        $combined = $scanned;

        foreach ($existing as $row) {
            $combined[] = [
                'category' => $row->category,
                'name' => $row->name,
                'confidence' => (float) $row->confidence,
            ];
        }

        return $this->deduplicateTechStack($combined);
    }
}
