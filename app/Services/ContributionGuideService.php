<?php

namespace App\Services;

use App\Models\RepoCommit;
use App\Models\Repository;
use Illuminate\Support\Collection;

class ContributionGuideService
{
    protected RepoContextExtractor $contextExtractor;

    public function __construct(RepoContextExtractor $contextExtractor)
    {
        $this->contextExtractor = $contextExtractor;
    }

    /**
     * Generate structured "How to Contribute" markdown guide for a repository.
     *
     * @param Repository $repository
     * @return string
     */
    public function generate(Repository $repository): string
    {
        $repository->loadMissing('techStack');
        $owner = $repository->owner;
        $name = $repository->name;

        // 1. Check for existing CONTRIBUTING.md
        $existingContributing = $this->contextExtractor->fetchContributingFile($owner, $name);
        if ($existingContributing !== null && strlen(trim($existingContributing)) > 50) {
            return $this->formatExistingContributing($existingContributing);
        }

        // 2. Infer conventions from commits and repository
        $commits = RepoCommit::where('repository_id', $repository->id)->limit(100)->get();
        $usesConventionalCommits = $this->detectConventionalCommits($commits);
        $ciWorkflows = $this->contextExtractor->fetchCiWorkflows($owner, $name);
        $prTemplate = $this->contextExtractor->fetchPrTemplate($owner, $name);
        $setupCommands = $this->inferSetupAndTestCommands($repository);

        return $this->composeContributionGuide(
            $repository,
            $usesConventionalCommits,
            $ciWorkflows,
            $prTemplate,
            $setupCommands
        );
    }

    /**
     * Detect if repository commit history demonstrates Conventional Commits usage.
     *
     * @param Collection<int, RepoCommit> $commits
     * @return bool
     */
    public function detectConventionalCommits(Collection $commits): bool
    {
        if ($commits->isEmpty()) {
            return true; // default to standard conventional commits
        }

        $conventionalCount = 0;
        $pattern = '/^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(?:\([^\)]+\))?!?:/i';

        foreach ($commits as $commit) {
            if (preg_match($pattern, trim($commit->message))) {
                $conventionalCount++;
            }
        }

        return ($conventionalCount / $commits->count()) >= 0.15;
    }

    /**
     * Infer package manager setup, linting, and testing commands from tech stack.
     *
     * @param Repository $repository
     * @return array{install: array<string>, test: array<string>, lint: array<string>}
     */
    public function inferSetupAndTestCommands(Repository $repository): array
    {
        $techNames = $repository->techStack->pluck('name')->toArray();

        $install = [];
        $test = [];
        $lint = [];

        if (in_array('PHP', $techNames) || in_array('Laravel', $techNames) || in_array('Symfony', $techNames)) {
            $install[] = 'composer install';
            $install[] = 'cp .env.example .env';
            $install[] = 'php artisan key:generate';
            $test[] = 'php artisan test';
            $lint[] = 'composer lint || ./vendor/bin/pint';
        }

        if (in_array('JavaScript', $techNames) || in_array('TypeScript', $techNames) || in_array('Vue.js', $techNames) || in_array('React', $techNames) || in_array('Node.js', $techNames)) {
            $install[] = 'npm install';
            $test[] = 'npm test';
            $lint[] = 'npm run lint';
        }

        if (in_array('Python', $techNames) || in_array('Django', $techNames) || in_array('FastAPI', $techNames) || in_array('Flask', $techNames)) {
            $install[] = 'pip install -r requirements.txt';
            $test[] = 'pytest';
            $lint[] = 'flake8 || black --check .';
        }

        if (in_array('Go', $techNames)) {
            $install[] = 'go mod download';
            $test[] = 'go test ./...';
            $lint[] = 'golangci-lint run';
        }

        if (in_array('Rust', $techNames)) {
            $install[] = 'cargo build';
            $test[] = 'cargo test';
            $lint[] = 'cargo clippy';
        }

        if (empty($install)) {
            $install = ['git clone ' . $repository->github_url];
            $test = ['# Run project test suite'];
            $lint = ['# Run project code quality checks'];
        }

        return [
            'install' => array_unique($install),
            'test' => array_unique($test),
            'lint' => array_unique($lint),
        ];
    }

    /**
     * Format existing CONTRIBUTING.md.
     */
    protected function formatExistingContributing(string $content): string
    {
        $clean = trim($content);
        if (!str_starts_with($clean, '#')) {
            $clean = "## Contributing\n\n" . $clean;
        }

        return $clean;
    }

    /**
     * Compose comprehensive contribution guide markdown.
     */
    protected function composeContributionGuide(
        Repository $repository,
        bool $usesConventionalCommits,
        array $ciWorkflows,
        ?string $prTemplate,
        array $commands
    ): string {
        $repoName = $repository->name;
        $defaultBranch = $repository->default_branch ?: 'main';

        $md = [];
        $md[] = "## Contributing to {$repoName}";
        $md[] = "";
        $md[] = "Thank you for considering contributing to **{$repoName}**! Contributions from the community help make this project better for everyone.";
        $md[] = "";

        // 1. Development Setup Steps
        $md[] = "### 1. Development Setup";
        $md[] = "";
        $md[] = "1. **Fork & Clone:**";
        $md[] = "```bash";
        $md[] = "git clone {$repository->github_url}";
        $md[] = "cd {$repoName}";
        $md[] = "git checkout {$defaultBranch}";
        $md[] = "git pull origin {$defaultBranch}";
        $md[] = "```";
        $md[] = "";

        if (!empty($commands['install'])) {
            $md[] = "2. **Install Dependencies & Environment:**";
            $md[] = "```bash";
            foreach ($commands['install'] as $cmd) {
                $md[] = $cmd;
            }
            $md[] = "```";
            $md[] = "";
        }

        // 2. Branch Naming Conventions
        $md[] = "### 2. Branch Naming Convention";
        $md[] = "";
        $md[] = "Create a descriptive branch name from `{$defaultBranch}` using the following prefix conventions:";
        $md[] = "- `feature/<short-description>` - For new features and enhancements";
        $md[] = "- `fix/<issue-or-description>` - For bug fixes";
        $md[] = "- `refactor/<short-description>` - For code refactoring without behavior change";
        $md[] = "- `docs/<short-description>` - For documentation additions and improvements";
        $md[] = "- `test/<short-description>` - For adding or updating test suites";
        $md[] = "";
        $md[] = "```bash";
        $md[] = "git checkout -b feature/my-new-feature";
        $md[] = "```";
        $md[] = "";

        // 3. Commit Message Conventions
        $md[] = "### 3. Commit Message Guidelines";
        $md[] = "";
        if ($usesConventionalCommits) {
            $md[] = "This project strictly adheres to [Conventional Commits](https://www.conventionalcommits.org/).";
            $md[] = "";
            $md[] = "Format: `<type>(<optional scope>): <description>`";
            $md[] = "";
            $md[] = "**Allowed Types:**";
            $md[] = "- `feat:` Introduces a new feature";
            $md[] = "- `fix:` Patches a bug";
            $md[] = "- `docs:` Documentation changes only";
            $md[] = "- `refactor:` Code change that neither fixes a bug nor adds a feature";
            $md[] = "- `perf:` Performance improvements";
            $md[] = "- `test:` Adding or modifying tests";
            $md[] = "- `chore:` Routine tasks, dependency updates, and maintenance";
            $md[] = "";
            $md[] = "**Example:** `feat(auth): support oauth2 authentication tokens`";
        } else {
            $md[] = "Write clear, concise commit messages in the imperative mood (e.g. `Add oauth login support` instead of `Added oauth login support`).";
        }
        $md[] = "";

        // 4. Testing & Quality Assurance
        $md[] = "### 4. Running Tests & Linters";
        $md[] = "";
        $md[] = "Before submitting a pull request, ensure all tests and code quality checks pass locally:";
        $md[] = "```bash";
        if (!empty($commands['test'])) {
            foreach ($commands['test'] as $cmd) {
                $md[] = $cmd;
            }
        }
        if (!empty($commands['lint'])) {
            foreach ($commands['lint'] as $cmd) {
                $md[] = $cmd;
            }
        }
        $md[] = "```";
        $md[] = "";

        if (!empty($ciWorkflows)) {
            $workflowsList = implode(', ', $ciWorkflows);
            $md[] = "> **Note:** Automated CI pipelines (`{$workflowsList}`) are enabled. All checks must pass before pull requests can be merged.";
            $md[] = "";
        }

        // 5. Pull Request Checklist
        $md[] = "### 5. Pull Request Checklist";
        $md[] = "";
        $md[] = "When submitting your PR, please verify:";
        $md[] = "- [ ] My branch is up to date with the latest `{$defaultBranch}` branch.";
        $md[] = "- [ ] Unit and/or feature tests have been added or updated to cover all code changes.";
        $md[] = "- [ ] All tests and static analysis linters pass locally without warnings.";
        $md[] = "- [ ] Commit messages follow the project's commit guidelines.";
        $md[] = "- [ ] Documentation and README have been updated if necessary.";
        $md[] = "- [ ] PR description clearly explains the **motivation**, **changes**, and any **breaking changes**.";
        $md[] = "";

        return implode("\n", $md);
    }
}
