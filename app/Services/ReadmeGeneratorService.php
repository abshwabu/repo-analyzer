<?php

namespace App\Services;

use App\Models\GeneratedReadme;
use App\Models\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReadmeGeneratorService
{
    protected AiSummaryService $aiSummaryService;
    protected RepoContextExtractor $contextExtractor;
    protected ContributionGuideService $contributionGuideService;

    public function __construct(
        AiSummaryService $aiSummaryService,
        RepoContextExtractor $contextExtractor,
        ContributionGuideService $contributionGuideService
    ) {
        $this->aiSummaryService = $aiSummaryService;
        $this->contextExtractor = $contextExtractor;
        $this->contributionGuideService = $contributionGuideService;
    }

    /**
     * Generate and persist README.md for a repository.
     *
     * @param Repository $repository
     * @param string|null $provider
     * @param string|null $apiKey
     * @param string|null $model
     * @param array|null $customSummary
     * @return GeneratedReadme
     */
    public function generate(
        Repository $repository,
        ?string $provider = null,
        ?string $apiKey = null,
        ?string $model = null,
        ?array $customSummary = null
    ): GeneratedReadme {
        $repository->loadMissing('techStack');

        // 1. Gather AI or fallback summary
        $summary = $this->resolveSummary($repository, $provider, $apiKey, $model, $customSummary);

        // 2. Extract scripts and commands from manifests
        $scripts = $this->detectScripts($repository);

        // 3. Compose clean Markdown content
        $markdown = $this->composeMarkdown($repository, $summary, $scripts);

        // 4. Persist to generated_readmes
        return GeneratedReadme::create([
            'repository_id' => $repository->id,
            'content' => $markdown,
            'generated_at' => now(),
        ]);
    }

    /**
     * Resolve AI summary or construct rule-based fallback summary.
     *
     * @param Repository $repository
     * @param string|null $provider
     * @param string|null $apiKey
     * @param string|null $model
     * @param array|null $customSummary
     * @return array{project_overview: string, architecture: string, getting_started: array}
     */
    public function resolveSummary(
        Repository $repository,
        ?string $provider = null,
        ?string $apiKey = null,
        ?string $model = null,
        ?array $customSummary = null
    ): array {
        if (!empty($customSummary)) {
            return $customSummary;
        }

        if (!empty($provider) && !empty($apiKey)) {
            try {
                $result = $this->aiSummaryService->summarize($repository, $provider, $apiKey, $model);
                return $result['summary'];
            } catch (Throwable $e) {
                Log::warning("AI summary generation failed for repo #{$repository->id}, falling back to template: " . $e->getMessage());
            }
        }

        // Rule-based fallback summary
        $description = $repository->description ?: "A full-featured repository named {$repository->name}.";
        $techNames = $repository->techStack->pluck('name')->implode(', ');
        $techSentence = !empty($techNames) ? " Built using {$techNames}." : "";

        $primaryLang = $repository->techStack->where('category', 'language')->sortByDesc('confidence')->first()?->name;
        $primaryFramework = $repository->techStack->where('category', 'framework')->sortByDesc('confidence')->first()?->name;

        $prerequisites = [];
        $installCommands = [];
        $runCommands = [];

        if ($primaryLang === 'PHP' || $primaryFramework === 'Laravel' || $primaryFramework === 'Symfony') {
            $prerequisites = ['PHP 8.2+', 'Composer'];
            $installCommands = ['composer install', 'cp .env.example .env', 'php artisan key:generate'];
            $runCommands = ['php artisan serve'];
        } elseif ($primaryLang === 'TypeScript' || $primaryLang === 'JavaScript' || in_array($primaryFramework, ['React', 'Vue.js', 'Next.js', 'Nuxt.js', 'Express'])) {
            $prerequisites = ['Node.js 18+', 'npm or yarn'];
            $installCommands = ['npm install'];
            $runCommands = ['npm run dev'];
        } elseif ($primaryLang === 'Python' || in_array($primaryFramework, ['Django', 'Flask', 'FastAPI'])) {
            $prerequisites = ['Python 3.10+', 'pip'];
            $installCommands = ['pip install -r requirements.txt'];
            $runCommands = ['python main.py'];
        } elseif ($primaryLang === 'Go') {
            $prerequisites = ['Go 1.21+'];
            $installCommands = ['go mod download'];
            $runCommands = ['go run .'];
        } elseif ($primaryLang === 'Rust') {
            $prerequisites = ['Rust / Cargo'];
            $installCommands = ['cargo build'];
            $runCommands = ['cargo run'];
        } else {
            $prerequisites = ['Git'];
            $installCommands = ['git clone ' . $repository->github_url];
            $runCommands = ['# Refer to project docs'];
        }

        return [
            'project_overview' => "{$description}{$techSentence} Designed to deliver reliable and performant functionality.",
            'architecture' => "The repository follows a clean, modular structure organized around core domain logic and scalable components.",
            'getting_started' => [
                'prerequisites' => $prerequisites,
                'install_commands' => $installCommands,
                'run_commands' => $runCommands,
                'test_commands' => [],
                'instructions' => "Clone the repository, install dependencies, and execute the startup command.",
            ],
        ];
    }

    /**
     * Detect scripts from package manifests and Makefiles.
     *
     * @param Repository $repository
     * @return array<string, array<string, string>>
     */
    public function detectScripts(Repository $repository): array
    {
        $owner = $repository->owner;
        $name = $repository->name;
        $scripts = [];

        // 1. Detect package.json scripts
        $packageJson = $this->contextExtractor->fetchFileContent($owner, $name, 'package.json');
        if ($packageJson) {
            $pkg = json_decode($packageJson, true);
            if (!empty($pkg['scripts']) && is_array($pkg['scripts'])) {
                foreach ($pkg['scripts'] as $scriptName => $command) {
                    $scripts['npm']["npm run {$scriptName}"] = (string) $command;
                }
            }
        }

        // 2. Detect composer.json scripts
        $composerJson = $this->contextExtractor->fetchFileContent($owner, $name, 'composer.json');
        if ($composerJson) {
            $composer = json_decode($composerJson, true);
            if (!empty($composer['scripts']) && is_array($composer['scripts'])) {
                foreach ($composer['scripts'] as $scriptName => $command) {
                    $cmdStr = is_array($command) ? implode(' && ', $command) : (string) $command;
                    $scripts['composer']["composer {$scriptName}"] = $cmdStr;
                }
            }
        }

        // 3. Detect Makefile targets
        $makefile = $this->contextExtractor->fetchFileContent($owner, $name, 'Makefile');
        if ($makefile) {
            if (preg_match_all('/^([a-zA-Z0-9_-]+):/m', $makefile, $matches)) {
                foreach ($matches[1] as $target) {
                    if ($target !== '.PHONY') {
                        $scripts['make']["make {$target}"] = "Execute {$target} target";
                    }
                }
            }
        }

        return $scripts;
    }

    /**
     * Compose clean Markdown following a standard, professional README structure.
     *
     * @param Repository $repository
     * @param array $summary
     * @param array $scripts
     * @return string
     */
    public function composeMarkdown(Repository $repository, array $summary, array $scripts = []): string
    {
        $repoName = $repository->name;
        $owner = $repository->owner;
        $license = $repository->license ?: 'MIT';
        $stars = $repository->stars;
        $defaultBranch = $repository->default_branch ?: 'main';

        $overview = $summary['project_overview'] ?? '';
        $architecture = $summary['architecture'] ?? '';
        $gettingStarted = $summary['getting_started'] ?? [];

        $prerequisites = (array) ($gettingStarted['prerequisites'] ?? []);
        $installCommands = (array) ($gettingStarted['install_commands'] ?? []);
        $runCommands = (array) ($gettingStarted['run_commands'] ?? []);
        $testCommands = (array) ($gettingStarted['test_commands'] ?? []);
        $instructions = (string) ($gettingStarted['instructions'] ?? '');

        $md = [];

        // Title & Badges
        $md[] = "# {$repoName}";
        $md[] = "";

        $badges = [];
        if ($license) {
            $badges[] = "[![License](https://img.shields.io/badge/license-" . urlencode($license) . "-blue.svg)](#license)";
        }
        $badges[] = "[![Stars](https://img.shields.io/github/stars/{$owner}/{$repoName}?style=flat-square)](https://github.com/{$owner}/{$repoName})";
        $badges[] = "[![Branch](https://img.shields.io/badge/branch-" . urlencode($defaultBranch) . "-green.svg)](https://github.com/{$owner}/{$repoName})";

        $md[] = implode(" ", $badges);
        $md[] = "";

        // Description
        $md[] = "> " . ($overview ?: $repository->description ?: "A software project repository.");
        $md[] = "";

        // Table of Contents
        $md[] = "## Table of Contents";
        $md[] = "- [About the Project](#about-the-project)";
        $md[] = "- [Built With](#built-with)";
        if (!empty($architecture)) {
            $md[] = "- [Architecture](#architecture)";
        }
        $md[] = "- [Getting Started](#getting-started)";
        $md[] = "  - [Prerequisites](#prerequisites)";
        $md[] = "  - [Installation](#installation)";
        $md[] = "- [Usage & Available Scripts](#usage--available-scripts)";
        $md[] = "- [Contributing](#contributing)";
        $md[] = "- [License](#license)";
        $md[] = "";

        // About the Project
        $md[] = "## About the Project";
        $md[] = "";
        $md[] = $overview ?: ($repository->description ?: "{$repoName} is an open-source project by {$owner}.");
        $md[] = "";

        // Built With (Tech Stack)
        $md[] = "## Built With";
        $md[] = "";
        if ($repository->techStack->isNotEmpty()) {
            $grouped = $repository->techStack->groupBy('category');
            foreach ($grouped as $category => $items) {
                $categoryTitle = ucfirst($category);
                $md[] = "### {$categoryTitle}";
                foreach ($items as $item) {
                    $conf = $item->confidence ? " `({$item->confidence}%)`" : "";
                    $md[] = "- **{$item->name}**{$conf}";
                }
                $md[] = "";
            }
        } else {
            $md[] = "- *Tech stack details pending analysis.*";
            $md[] = "";
        }

        // Architecture
        if (!empty($architecture)) {
            $md[] = "## Architecture";
            $md[] = "";
            $md[] = $architecture;
            $md[] = "";
        }

        // Getting Started
        $md[] = "## Getting Started";
        $md[] = "";
        if (!empty($instructions)) {
            $md[] = "{$instructions}";
            $md[] = "";
        }

        // Prerequisites
        $md[] = "### Prerequisites";
        $md[] = "";
        if (!empty($prerequisites)) {
            foreach ($prerequisites as $prereq) {
                $md[] = "- {$prereq}";
            }
        } else {
            $md[] = "- Git installed on your local machine";
        }
        $md[] = "";

        // Installation
        $md[] = "### Installation";
        $md[] = "";
        $md[] = "1. Clone the repository:";
        $md[] = "```bash";
        $md[] = "git clone {$repository->github_url}";
        $md[] = "cd {$repoName}";
        $md[] = "```";
        $md[] = "";

        if (!empty($installCommands)) {
            $md[] = "2. Install dependencies & configure:";
            $md[] = "```bash";
            foreach ($installCommands as $cmd) {
                $md[] = $cmd;
            }
            $md[] = "```";
            $md[] = "";
        }

        if (!empty($runCommands)) {
            $md[] = "3. Run the application:";
            $md[] = "```bash";
            foreach ($runCommands as $cmd) {
                $md[] = $cmd;
            }
            $md[] = "```";
            $md[] = "";
        }

        // Usage & Scripts
        $md[] = "## Usage & Available Scripts";
        $md[] = "";
        $hasScripts = false;

        foreach ($scripts as $group => $commandList) {
            if (!empty($commandList)) {
                $hasScripts = true;
                $groupTitle = strtoupper($group) . " Scripts";
                $md[] = "### {$groupTitle}";
                $md[] = "| Command | Description |";
                $md[] = "| --- | --- |";
                foreach ($commandList as $cmd => $desc) {
                    $escapedDesc = str_replace('|', '\|', $desc);
                    $md[] = "| `{$cmd}` | `{$escapedDesc}` |";
                }
                $md[] = "";
            }
        }

        if (!$hasScripts) {
            if (!empty($runCommands)) {
                $md[] = "Start the project with:";
                $md[] = "```bash";
                foreach ($runCommands as $cmd) {
                    $md[] = $cmd;
                }
                $md[] = "```";
            } else {
                $md[] = "Refer to the project documentation for advanced usage instructions.";
            }
            $md[] = "";
        }

        // Contributing Section (generated via ContributionGuideService)
        $contributingGuide = $this->contributionGuideService->generate($repository);
        $md[] = $contributingGuide;
        $md[] = "";

        // License
        $md[] = "## License";
        $md[] = "";
        $md[] = "Distributed under the **{$license}** License. See `LICENSE` for more information.";
        $md[] = "";

        return implode("\n", $md);
    }
}
