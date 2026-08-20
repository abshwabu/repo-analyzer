<?php

namespace App\Services;

use App\Exceptions\RateLimitExceededException;
use App\Models\RepoCommit;
use App\Models\RepoContributor;
use App\Models\RepoTechStack;
use App\Models\Repository;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class GithubIngestionService
{
    /**
     * Parse owner, repository name, and normalized URL from various GitHub URL formats.
     *
     * Supports:
     * - https://github.com/owner/repo
     * - https://github.com/owner/repo.git
     * - http://github.com/owner/repo
     * - git@github.com:owner/repo.git
     * - git@github.com:owner/repo
     * - github.com/owner/repo
     *
     * @param string $url
     * @return array{owner: string, repo: string, normalized_url: string}
     * @throws InvalidArgumentException
     */
    public function parseUrl(string $url): array
    {
        $trimmed = trim($url);

        // SSH format: git@github.com:owner/repo(.git)
        if (preg_match('/^git@github\.com:([a-zA-Z0-9_.-]+)\/([a-zA-Z0-9_.-]+?)(?:\.git)?$/i', $trimmed, $matches)) {
            $owner = $matches[1];
            $repo = preg_replace('/\.git$/i', '', $matches[2]);
            return [
                'owner' => $owner,
                'repo' => $repo,
                'normalized_url' => "https://github.com/{$owner}/{$repo}",
            ];
        }

        // HTTP/HTTPS or schemeless format: (https?://)?(www\.)?github\.com/owner/repo(/.*)?
        if (preg_match('/^(?:https?:\/\/)?(?:www\.)?github\.com\/([a-zA-Z0-9_.-]+)\/([a-zA-Z0-9_.-]+?)(?:\.git|\/.*)?$/i', $trimmed, $matches)) {
            $owner = $matches[1];
            $repo = preg_replace('/\.git$/i', '', $matches[2]);
            return [
                'owner' => $owner,
                'repo' => $repo,
                'normalized_url' => "https://github.com/{$owner}/{$repo}",
            ];
        }

        throw new InvalidArgumentException("Invalid GitHub repository URL: {$url}");
    }

    /**
     * Create an HTTP client configured with GitHub headers and optional authentication.
     *
     * @return PendingRequest
     */
    protected function getHttpClient(): PendingRequest
    {
        $token = config('services.github.token');
        $userAgent = config('services.github.user_agent', 'Repo-Analyzer-App');
        $baseUrl = rtrim(config('services.github.api_url', 'https://api.github.com'), '/');

        $client = Http::baseUrl($baseUrl)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => $userAgent,
            ])
            ->timeout(30);

        if (!empty($token)) {
            $client = $client->withToken($token);
        }

        return $client;
    }

    /**
     * Perform an API request and handle rate limits and error responses.
     *
     * @param string $endpoint
     * @param array<string, mixed> $queryParams
     * @return Response
     * @throws RateLimitExceededException|RuntimeException
     */
    public function makeRequest(string $endpoint, array $queryParams = []): Response
    {
        $client = $this->getHttpClient();
        $response = $client->get($endpoint, $queryParams);

        $remaining = $response->header('X-RateLimit-Remaining');
        $reset = $response->header('X-RateLimit-Reset');

        // Check for rate limiting
        if ($response->status() === 403 && $remaining !== null && (int)$remaining === 0) {
            $resetTime = $reset ? (int)$reset : (time() + 60);
            $retryAfter = max(5, $resetTime - time());
            Log::warning("GitHub API rate limit hit for {$endpoint}. Reset in {$retryAfter} seconds.");
            throw new RateLimitExceededException(
                "GitHub API rate limit exceeded. Resets at " . date('c', $resetTime),
                $resetTime,
                $retryAfter
            );
        }

        if ($response->status() === 404) {
            throw new RuntimeException("GitHub repository or resource not found: {$endpoint}");
        }

        if (!$response->successful()) {
            $errorMsg = $response->json('message') ?? $response->body();
            throw new RuntimeException("GitHub API request failed [{$response->status()}]: {$errorMsg}");
        }

        return $response;
    }

    /**
     * Fetch repository metadata from GitHub.
     *
     * @param string $owner
     * @param string $repo
     * @return array{description: ?string, default_branch: string, stars: int, license: ?string, repo_created_at: ?Carbon}
     */
    public function fetchMetadata(string $owner, string $repo): array
    {
        $response = $this->makeRequest("/repos/{$owner}/{$repo}");
        $data = $response->json();

        $license = null;
        if (!empty($data['license'])) {
            $license = $data['license']['spdx_id'] ?? $data['license']['name'] ?? null;
            if ($license === 'NOASSERTION') {
                $license = $data['license']['name'] ?? null;
            }
        }

        return [
            'description' => $data['description'] ?? null,
            'default_branch' => $data['default_branch'] ?? 'main',
            'stars' => (int) ($data['stargazers_count'] ?? 0),
            'license' => $license,
            'repo_created_at' => !empty($data['created_at']) ? Carbon::parse($data['created_at']) : null,
        ];
    }

    /**
     * Fetch full commit list from GitHub (paginated, supporting >100 commits).
     *
     * @param string $owner
     * @param string $repo
     * @param int $maxPages Maximum pages to fetch (100 commits per page)
     * @return array<int, array{sha: string, message: string, author_name: ?string, author_email: ?string, committed_at: ?Carbon, additions: int, deletions: int}>
     */
    public function fetchCommits(string $owner, string $repo, int $maxPages = 10): array
    {
        $allCommits = [];
        $page = 1;

        while ($page <= $maxPages) {
            $response = $this->makeRequest("/repos/{$owner}/{$repo}/commits", [
                'per_page' => 100,
                'page' => $page,
            ]);

            $commits = $response->json();
            if (!is_array($commits) || empty($commits)) {
                break;
            }

            foreach ($commits as $item) {
                $sha = $item['sha'] ?? null;
                if (!$sha) {
                    continue;
                }

                $author = $item['commit']['author'] ?? [];
                $committer = $item['commit']['committer'] ?? [];
                $dateStr = $author['date'] ?? ($committer['date'] ?? null);
                $committedAt = !empty($dateStr) ? Carbon::parse($dateStr) : null;

                $allCommits[] = [
                    'sha' => $sha,
                    'message' => $item['commit']['message'] ?? '',
                    'author_name' => $author['name'] ?? ($item['author']['login'] ?? null),
                    'author_email' => $author['email'] ?? null,
                    'committed_at' => $committedAt,
                    'additions' => 0,
                    'deletions' => 0,
                ];
            }

            if (count($commits) < 100) {
                break;
            }

            $linkHeader = $response->header('Link');
            if ($linkHeader && !str_contains($linkHeader, 'rel="next"')) {
                break;
            }

            $page++;
        }

        return $allCommits;
    }

    /**
     * Fetch contributors list with commit counts.
     *
     * @param string $owner
     * @param string $repo
     * @param int $maxPages
     * @return array<int, array{github_username: string, commit_count: int}>
     */
    public function fetchContributors(string $owner, string $repo, int $maxPages = 5): array
    {
        $allContributors = [];
        $page = 1;

        while ($page <= $maxPages) {
            $response = $this->makeRequest("/repos/{$owner}/{$repo}/contributors", [
                'per_page' => 100,
                'page' => $page,
                'anon' => 'false',
            ]);

            $contributors = $response->json();
            if (!is_array($contributors) || empty($contributors)) {
                break;
            }

            foreach ($contributors as $item) {
                $username = $item['login'] ?? null;
                if (!$username) {
                    continue;
                }

                $allContributors[] = [
                    'github_username' => $username,
                    'commit_count' => (int) ($item['contributions'] ?? 0),
                ];
            }

            if (count($contributors) < 100) {
                break;
            }

            $linkHeader = $response->header('Link');
            if ($linkHeader && !str_contains($linkHeader, 'rel="next"')) {
                break;
            }

            $page++;
        }

        return $allContributors;
    }

    /**
     * Fetch language breakdown and calculate percentage confidence.
     *
     * @param string $owner
     * @param string $repo
     * @return array<int, array{category: string, name: string, confidence: float}>
     */
    public function fetchLanguages(string $owner, string $repo): array
    {
        $response = $this->makeRequest("/repos/{$owner}/{$repo}/languages");
        $data = $response->json();

        if (!is_array($data) || empty($data)) {
            return [];
        }

        $totalBytes = array_sum($data);
        $result = [];

        foreach ($data as $lang => $bytes) {
            $confidence = $totalBytes > 0 ? round(($bytes / $totalBytes) * 100, 2) : 0.0;
            $result[] = [
                'category' => 'language',
                'name' => $lang,
                'confidence' => $confidence,
            ];
        }

        return $result;
    }

    /**
     * Ingest and persist all GitHub data for a repository.
     *
     * @param Repository $repository
     * @return Repository
     */
    public function ingest(Repository $repository): Repository
    {
        $owner = $repository->owner;
        $name = $repository->name;

        // 1. Fetch metadata
        $metadata = $this->fetchMetadata($owner, $name);

        // 2. Fetch commits
        $commits = $this->fetchCommits($owner, $name);

        // 3. Fetch contributors
        $contributors = $this->fetchContributors($owner, $name);

        // 4. Fetch languages
        $languages = $this->fetchLanguages($owner, $name);

        // Map first and last commit times for contributors based on fetched commits
        $contributorsData = [];
        foreach ($contributors as $contributor) {
            $username = $contributor['github_username'];
            $matchingCommits = array_filter($commits, function ($c) use ($username) {
                return (isset($c['author_name']) && strcasecmp($c['author_name'], $username) === 0);
            });

            $firstCommitAt = null;
            $lastCommitAt = null;
            if (!empty($matchingCommits)) {
                $dates = array_filter(array_column($matchingCommits, 'committed_at'));
                if (!empty($dates)) {
                    sort($dates);
                    $firstCommitAt = $dates[0];
                    $lastCommitAt = end($dates);
                }
            }

            $contributorsData[] = [
                'repository_id' => $repository->id,
                'github_username' => $username,
                'commit_count' => $contributor['commit_count'],
                'first_commit_at' => $firstCommitAt,
                'last_commit_at' => $lastCommitAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($repository, $metadata, $commits, $contributorsData, $languages) {
            // Update repository metadata and status
            $repository->update([
                'description' => $metadata['description'],
                'default_branch' => $metadata['default_branch'],
                'stars' => $metadata['stars'],
                'license' => $metadata['license'],
                'repo_created_at' => $metadata['repo_created_at'],
                'status' => 'completed',
                'error_message' => null,
                'last_analyzed_at' => now(),
            ]);

            // Upsert commits
            if (!empty($commits)) {
                $commitsToUpsert = array_map(function ($c) use ($repository) {
                    return [
                        'repository_id' => $repository->id,
                        'sha' => $c['sha'],
                        'message' => $c['message'],
                        'author_name' => $c['author_name'],
                        'author_email' => $c['author_email'],
                        'committed_at' => $c['committed_at'],
                        'additions' => $c['additions'],
                        'deletions' => $c['deletions'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $commits);

                foreach (array_chunk($commitsToUpsert, 200) as $chunk) {
                    RepoCommit::upsert(
                        $chunk,
                        ['repository_id', 'sha'],
                        ['message', 'author_name', 'author_email', 'committed_at', 'additions', 'deletions', 'updated_at']
                    );
                }
            }

            // Upsert contributors
            if (!empty($contributorsData)) {
                foreach (array_chunk($contributorsData, 200) as $chunk) {
                    RepoContributor::upsert(
                        $chunk,
                        ['repository_id', 'github_username'],
                        ['commit_count', 'first_commit_at', 'last_commit_at', 'updated_at']
                    );
                }
            }

            // Upsert language tech stack
            if (!empty($languages)) {
                $techStackToUpsert = array_map(function ($l) use ($repository) {
                    return [
                        'repository_id' => $repository->id,
                        'category' => $l['category'],
                        'name' => $l['name'],
                        'confidence' => $l['confidence'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $languages);

                RepoTechStack::upsert(
                    $techStackToUpsert,
                    ['repository_id', 'category', 'name'],
                    ['confidence', 'updated_at']
                );
            }
        });

        return $repository->fresh(['techStack', 'commits', 'contributors']);
    }
}
