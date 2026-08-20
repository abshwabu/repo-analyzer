<?php

namespace App\Services;

use App\Models\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

class RepoContextExtractor
{
    protected GithubIngestionService $ingestionService;

    public const MAX_EXCERPT_CHARS = 2000;

    public function __construct(GithubIngestionService $ingestionService)
    {
        $this->ingestionService = $ingestionService;
    }

    /**
     * Extract compiled and token-capped repository context for AI summarization.
     *
     * @param Repository $repository
     * @return array<string, mixed>
     */
    public function extract(Repository $repository): array
    {
        $owner = $repository->owner;
        $name = $repository->name;

        // 1. Metadata
        $metadata = [
            'owner' => $owner,
            'name' => $name,
            'description' => $repository->description,
            'stars' => $repository->stars,
            'license' => $repository->license,
            'default_branch' => $repository->default_branch,
        ];

        // 2. Tech stack
        $techStack = $repository->techStack()->get()->map(function ($item) {
            return [
                'category' => $item->category,
                'name' => $item->name,
                'confidence' => (float) $item->confidence,
            ];
        })->toArray();

        // 3. Top-level folder & file structure
        $topLevelStructure = $this->fetchTopLevelStructure($owner, $name);

        // 4. File samples (README, manifest, entry point)
        $fileSamples = $this->fetchKeyFileSamples($owner, $name, $topLevelStructure);

        return [
            'repository' => $metadata,
            'tech_stack' => $techStack,
            'top_level_structure' => $topLevelStructure,
            'file_samples' => $fileSamples,
        ];
    }

    /**
     * Fetch list of top-level directory entries.
     *
     * @param string $owner
     * @param string $repo
     * @return array<string>
     */
    public function fetchTopLevelStructure(string $owner, string $repo): array
    {
        try {
            $response = $this->ingestionService->makeRequest("/repos/{$owner}/{$repo}/contents");
            $items = $response->json();

            if (!is_array($items)) {
                return [];
            }

            $structure = [];
            foreach ($items as $item) {
                $name = $item['name'] ?? '';
                $type = $item['type'] ?? 'file';
                if ($name !== '') {
                    $structure[] = $type === 'dir' ? "{$name}/" : $name;
                }
            }

            return $structure;
        } catch (Throwable $e) {
            Log::warning("Could not fetch top-level structure for {$owner}/{$repo}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch excerpts of README, manifest file, and entry file.
     *
     * @param string $owner
     * @param string $repo
     * @param array<string> $topLevelItems
     * @return array<string, ?string>
     */
    public function fetchKeyFileSamples(string $owner, string $repo, array $topLevelItems = []): array
    {
        return [
            'readme' => $this->fetchReadmeExcerpt($owner, $repo),
            'package_manifest' => $this->fetchManifestExcerpt($owner, $repo, $topLevelItems),
            'entry_file' => $this->fetchEntryFileExcerpt($owner, $repo, $topLevelItems),
        ];
    }

    /**
     * Fetch and truncate existing README.
     */
    public function fetchReadmeExcerpt(string $owner, string $repo): ?string
    {
        try {
            $response = $this->ingestionService->makeRequest("/repos/{$owner}/{$repo}/readme");
            $data = $response->json();

            if (!empty($data['content'])) {
                $decoded = base64_decode(str_replace(["\n", "\r"], '', $data['content']));
                return $this->truncateText($decoded, self::MAX_EXCERPT_CHARS);
            }
        } catch (Throwable $e) {
            // README might not exist
        }

        return null;
    }

    /**
     * Fetch and truncate first matching package manifest.
     */
    public function fetchManifestExcerpt(string $owner, string $repo, array $topLevelItems = []): ?string
    {
        $manifestCandidates = [
            'package.json',
            'composer.json',
            'requirements.txt',
            'pyproject.toml',
            'Cargo.toml',
            'go.mod',
            'Gemfile',
            'pom.xml',
            'build.gradle',
        ];

        foreach ($manifestCandidates as $file) {
            $content = $this->fetchFileContent($owner, $repo, $file);
            if ($content !== null) {
                return $this->truncateText($content, self::MAX_EXCERPT_CHARS);
            }
        }

        return null;
    }

    /**
     * Fetch and truncate main entry file.
     */
    public function fetchEntryFileExcerpt(string $owner, string $repo, array $topLevelItems = []): ?string
    {
        $entryCandidates = [
            'src/main.js',
            'src/index.js',
            'src/main.ts',
            'src/index.ts',
            'index.js',
            'main.py',
            'app.py',
            'src/main.rs',
            'main.go',
            'public/index.php',
            'src/App.vue',
            'src/App.tsx',
        ];

        foreach ($entryCandidates as $path) {
            $content = $this->fetchFileContent($owner, $repo, $path);
            if ($content !== null) {
                return $this->truncateText($content, self::MAX_EXCERPT_CHARS);
            }
        }

        return null;
    }

    /**
     * Fetch single file content from GitHub API.
     */
    public function fetchFileContent(string $owner, string $repo, string $path): ?string
    {
        try {
            $response = $this->ingestionService->makeRequest("/repos/{$owner}/{$repo}/contents/{$path}");
            $data = $response->json();

            if (!empty($data['content']) && ($data['encoding'] ?? '') === 'base64') {
                return base64_decode(str_replace(["\n", "\r"], '', $data['content']));
            }
        } catch (Throwable $e) {
            // File not found
        }

        return null;
    }

    /**
     * Fetch existing CONTRIBUTING.md file if available.
     */
    public function fetchContributingFile(string $owner, string $repo): ?string
    {
        $candidates = [
            'CONTRIBUTING.md',
            '.github/CONTRIBUTING.md',
            'docs/CONTRIBUTING.md',
            'CONTRIBUTING',
            'contributing.md',
        ];

        foreach ($candidates as $path) {
            $content = $this->fetchFileContent($owner, $repo, $path);
            if ($content !== null) {
                return $this->truncateText($content, 4000);
            }
        }

        return null;
    }

    /**
     * Fetch PR template if present.
     */
    public function fetchPrTemplate(string $owner, string $repo): ?string
    {
        $candidates = [
            '.github/pull_request_template.md',
            '.github/PULL_REQUEST_TEMPLATE.md',
            'pull_request_template.md',
            'PULL_REQUEST_TEMPLATE.md',
            '.github/PULL_REQUEST_TEMPLATE/pull_request_template.md',
        ];

        foreach ($candidates as $path) {
            $content = $this->fetchFileContent($owner, $repo, $path);
            if ($content !== null) {
                return $this->truncateText($content, 2000);
            }
        }

        return null;
    }

    /**
     * Fetch CI workflow file names from .github/workflows.
     *
     * @param string $owner
     * @param string $repo
     * @return array<string>
     */
    public function fetchCiWorkflows(string $owner, string $repo): array
    {
        try {
            $response = $this->ingestionService->makeRequest("/repos/{$owner}/{$repo}/contents/.github/workflows");
            $items = $response->json();

            if (!is_array($items)) {
                return [];
            }

            $workflows = [];
            foreach ($items as $item) {
                $name = $item['name'] ?? '';
                if ($name !== '' && (str_ends_with($name, '.yml') || str_ends_with($name, '.yaml'))) {
                    $workflows[] = $name;
                }
            }

            return $workflows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Safely truncate text to a maximum length.
     */
    public function truncateText(?string $text, int $maxChars): ?string
    {
        if ($text === null) {
            return null;
        }

        $trimmed = trim($text);
        if (mb_strlen($trimmed) <= $maxChars) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $maxChars) . "\n... [truncated]";
    }
}
