<?php

namespace App\Services;

use App\Models\RepoCommit;
use App\Models\RepoContributor;
use App\Models\Repository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class CommitTimelineService
{
    protected GithubIngestionService $ingestionService;

    public function __construct(GithubIngestionService $ingestionService)
    {
        $this->ingestionService = $ingestionService;
    }

    /**
     * Generate structured timeline of commit history including monthly volume and significant commits.
     *
     * @param Repository $repository
     * @param bool $fetchDiffStats
     * @return array
     */
    public function getTimeline(Repository $repository, bool $fetchDiffStats = true): array
    {
        $commits = RepoCommit::where('repository_id', $repository->id)
            ->whereNotNull('committed_at')
            ->orderBy('committed_at', 'asc')
            ->get();

        $monthlyVolume = $this->computeMonthlyVolume($commits);
        $significantCommits = $this->identifySignificantCommits($repository, $commits, $fetchDiffStats);

        return [
            'repository_id' => $repository->id,
            'total_commits' => $commits->count(),
            'monthly_volume' => $monthlyVolume,
            'significant_commits' => $significantCommits,
        ];
    }

    /**
     * Group commits by month and compute commit volume over time.
     *
     * @param \Illuminate\Support\Collection<int, RepoCommit> $commits
     * @return array<int, array{period: string, year: int, month: int, count: int}>
     */
    public function computeMonthlyVolume($commits): array
    {
        $grouped = [];

        foreach ($commits as $commit) {
            /** @var Carbon $date */
            $date = $commit->committed_at;
            $period = $date->format('Y-m');

            if (!isset($grouped[$period])) {
                $grouped[$period] = [
                    'period' => $period,
                    'year' => (int) $date->format('Y'),
                    'month' => (int) $date->format('m'),
                    'count' => 0,
                ];
            }

            $grouped[$period]['count']++;
        }

        return array_values($grouped);
    }

    /**
     * Identify significant commits matching conventional prefixes or touching >20 files.
     *
     * @param Repository $repository
     * @param \Illuminate\Support\Collection<int, RepoCommit> $commits
     * @param bool $fetchDiffStats
     * @return array<int, array>
     */
    public function identifySignificantCommits(Repository $repository, $commits, bool $fetchDiffStats = true): array
    {
        $significant = [];

        foreach ($commits as $commit) {
            $analysis = $this->analyzeCommitSignificance($commit->message);

            // If matched conventional pattern or diff stat fetching is enabled
            if ($analysis['is_significant']) {
                $additions = $commit->additions;
                $deletions = $commit->deletions;
                $filesCount = 0;

                if ($fetchDiffStats && $additions === 0 && $deletions === 0) {
                    $diffStats = $this->fetchDiffStat($repository, $commit->sha);
                    if ($diffStats !== null) {
                        $additions = $diffStats['additions'];
                        $deletions = $diffStats['deletions'];
                        $filesCount = $diffStats['files_count'];

                        // Store additions/deletions on repo_commits
                        $commit->update([
                            'additions' => $additions,
                            'deletions' => $deletions,
                        ]);
                    }
                }

                $reason = $analysis['primary_reason'];
                if ($filesCount > 20) {
                    $reason = 'large_change (>20 files)';
                }

                $significant[] = [
                    'id' => $commit->id,
                    'sha' => $commit->sha,
                    'short_sha' => substr($commit->sha, 0, 7),
                    'message' => $commit->message,
                    'author_name' => $commit->author_name,
                    'author_email' => $commit->author_email,
                    'committed_at' => $commit->committed_at?->toIso8601String(),
                    'additions' => $additions,
                    'deletions' => $deletions,
                    'reason' => $reason,
                ];
            }
        }

        return $significant;
    }

    /**
     * Determine if a commit message matches significance rules.
     *
     * @param string $message
     * @return array{is_significant: bool, primary_reason: ?string, reasons: array<string>}
     */
    public function analyzeCommitSignificance(string $message): array
    {
        $reasons = [];

        $patterns = [
            'breaking_change' => '/\bBREAKING[- ]CHANGE\b|^breaking(?:\([^\)]+\))?!?:/i',
            'breaking_exclamation' => '/^[a-z0-9_.-]+(?:\([^\)]+\))?!:/i',
            'feature' => '/^feat(?:\([^\)]+\))?:/i',
            'refactor' => '/^refactor(?:\([^\)]+\))?:/i',
            'performance' => '/^perf(?:\([^\)]+\))?:/i',
        ];

        foreach ($patterns as $reasonKey => $pattern) {
            if (preg_match($pattern, trim($message))) {
                $reasons[] = $reasonKey;
            }
        }

        return [
            'is_significant' => !empty($reasons),
            'primary_reason' => !empty($reasons) ? $reasons[0] : null,
            'reasons' => $reasons,
        ];
    }

    /**
     * Fetch diff stats (additions, deletions, files changed) for a specific commit via GitHub API.
     *
     * @param Repository $repository
     * @param string $sha
     * @return ?array{additions: int, deletions: int, files_count: int}
     */
    public function fetchDiffStat(Repository $repository, string $sha): ?array
    {
        try {
            $owner = $repository->owner;
            $name = $repository->name;
            $response = $this->ingestionService->makeRequest("/repos/{$owner}/{$name}/commits/{$sha}");

            $data = $response->json();
            $additions = (int) ($data['stats']['additions'] ?? 0);
            $deletions = (int) ($data['stats']['deletions'] ?? 0);
            $filesCount = count($data['files'] ?? []);

            return [
                'additions' => $additions,
                'deletions' => $deletions,
                'files_count' => $filesCount,
            ];
        } catch (Throwable $e) {
            Log::warning("Could not fetch diff stat for commit {$sha} in repo #{$repository->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate contributor activity summary including commit counts, contribution dates, and percentage share.
     *
     * @param Repository $repository
     * @return array
     */
    public function getContributorsSummary(Repository $repository): array
    {
        $contributors = RepoContributor::where('repository_id', $repository->id)
            ->orderBy('commit_count', 'desc')
            ->get();

        $totalCommits = RepoCommit::where('repository_id', $repository->id)->count();
        if ($totalCommits === 0) {
            $totalCommits = $contributors->sum('commit_count');
        }

        $summary = [];
        foreach ($contributors as $contributor) {
            $count = $contributor->commit_count;
            $share = $totalCommits > 0 ? round(($count / $totalCommits) * 100, 2) : 0.0;

            $summary[] = [
                'id' => $contributor->id,
                'github_username' => $contributor->github_username,
                'commit_count' => $count,
                'percentage_share' => $share,
                'first_commit_at' => $contributor->first_commit_at?->toIso8601String(),
                'last_commit_at' => $contributor->last_commit_at?->toIso8601String(),
            ];
        }

        return [
            'repository_id' => $repository->id,
            'total_contributors' => $contributors->count(),
            'total_commits' => $totalCommits,
            'contributors' => $summary,
        ];
    }
}
