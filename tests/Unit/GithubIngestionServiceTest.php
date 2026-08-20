<?php

namespace Tests\Unit;

use App\Exceptions\RateLimitExceededException;
use App\Models\Repository;
use App\Services\GithubIngestionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class GithubIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected GithubIngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GithubIngestionService();
    }

    public function test_parse_url_supports_various_formats(): void
    {
        $urls = [
            'https://github.com/torvalds/linux' => ['owner' => 'torvalds', 'repo' => 'linux'],
            'https://github.com/torvalds/linux.git' => ['owner' => 'torvalds', 'repo' => 'linux'],
            'http://github.com/laravel/framework' => ['owner' => 'laravel', 'repo' => 'framework'],
            'git@github.com:vuejs/core.git' => ['owner' => 'vuejs', 'repo' => 'core'],
            'git@github.com:vuejs/core' => ['owner' => 'vuejs', 'repo' => 'core'],
            'github.com/facebook/react' => ['owner' => 'facebook', 'repo' => 'react'],
            'https://www.github.com/tailwindlabs/tailwindcss/' => ['owner' => 'tailwindlabs', 'repo' => 'tailwindcss'],
        ];

        foreach ($urls as $url => $expected) {
            $parsed = $this->service->parseUrl($url);
            $this->assertEquals($expected['owner'], $parsed['owner'], "Failed parsing owner for {$url}");
            $this->assertEquals($expected['repo'], $parsed['repo'], "Failed parsing repo for {$url}");
            $this->assertEquals("https://github.com/{$expected['owner']}/{$expected['repo']}", $parsed['normalized_url']);
        }
    }

    public function test_parse_url_throws_exception_for_invalid_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->parseUrl('https://gitlab.com/owner/repo');
    }

    public function test_rate_limiting_triggers_exception(): void
    {
        $resetTime = time() + 120;

        Http::fake([
            'https://api.github.com/*' => Http::response(
                ['message' => 'API rate limit exceeded'],
                403,
                [
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset' => (string) $resetTime,
                ]
            ),
        ]);

        $this->expectException(RateLimitExceededException::class);
        $this->service->fetchMetadata('owner', 'repo');
    }

    public function test_fetch_metadata_parses_response(): void
    {
        Http::fake([
            'https://api.github.com/repos/owner/repo' => Http::response([
                'description' => 'A test repository',
                'default_branch' => 'main',
                'stargazers_count' => 150,
                'license' => ['spdx_id' => 'MIT', 'name' => 'MIT License'],
                'created_at' => '2022-01-01T12:00:00Z',
            ], 200),
        ]);

        $meta = $this->service->fetchMetadata('owner', 'repo');

        $this->assertEquals('A test repository', $meta['description']);
        $this->assertEquals('main', $meta['default_branch']);
        $this->assertEquals(150, $meta['stars']);
        $this->assertEquals('MIT', $meta['license']);
        $this->assertInstanceOf(Carbon::class, $meta['repo_created_at']);
    }

    public function test_fetch_commits_handles_pagination_over_100_commits(): void
    {
        $page1Commits = [];
        for ($i = 1; $i <= 100; $i++) {
            $page1Commits[] = [
                'sha' => "sha-page1-{$i}",
                'commit' => [
                    'message' => "Commit {$i}",
                    'author' => ['name' => "Author {$i}", 'email' => "author{$i}@test.com", 'date' => '2023-01-01T00:00:00Z'],
                ],
            ];
        }

        $page2Commits = [];
        for ($i = 101; $i <= 125; $i++) {
            $page2Commits[] = [
                'sha' => "sha-page2-{$i}",
                'commit' => [
                    'message' => "Commit {$i}",
                    'author' => ['name' => "Author {$i}", 'email' => "author{$i}@test.com", 'date' => '2023-01-02T00:00:00Z'],
                ],
            ];
        }

        Http::fake([
            'https://api.github.com/repos/owner/repo/commits*' => function (\Illuminate\Http\Client\Request $request) use ($page1Commits, $page2Commits) {
                $page = $request->data()['page'] ?? (parse_url($request->url(), PHP_URL_QUERY) ? (int) (explode('page=', $request->url())[1] ?? 1) : 1);
                if ($page == 1) {
                    return Http::response($page1Commits, 200, [
                        'Link' => '<https://api.github.com/repos/owner/repo/commits?page=2>; rel="next"',
                    ]);
                }
                if ($page == 2) {
                    return Http::response($page2Commits, 200);
                }
                return Http::response([], 200);
            },
        ]);

        $commits = $this->service->fetchCommits('owner', 'repo');

        $this->assertCount(125, $commits);
        $this->assertEquals('sha-page1-1', $commits[0]['sha']);
        $this->assertEquals('sha-page2-125', $commits[124]['sha']);
    }

    public function test_fetch_languages_calculates_confidence(): void
    {
        Http::fake([
            'https://api.github.com/repos/owner/repo/languages' => Http::response([
                'PHP' => 6000,
                'Vue' => 3000,
                'CSS' => 1000,
            ], 200),
        ]);

        $languages = $this->service->fetchLanguages('owner', 'repo');

        $this->assertCount(3, $languages);
        $this->assertEquals('PHP', $languages[0]['name']);
        $this->assertEquals(60.0, $languages[0]['confidence']);
        $this->assertEquals('Vue', $languages[1]['name']);
        $this->assertEquals(30.0, $languages[1]['confidence']);
        $this->assertEquals('CSS', $languages[2]['name']);
        $this->assertEquals(10.0, $languages[2]['confidence']);
    }

    public function test_full_ingestion_persists_to_database(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/sample-repo',
            'owner' => 'owner',
            'name' => 'sample-repo',
            'default_branch' => 'main',
            'status' => 'pending',
        ]);

        Http::fake([
            'https://api.github.com/repos/owner/sample-repo' => Http::response([
                'description' => 'A wonderful repo',
                'default_branch' => 'main',
                'stargazers_count' => 42,
                'license' => ['spdx_id' => 'MIT'],
                'created_at' => '2023-01-01T00:00:00Z',
            ], 200),
            'https://api.github.com/repos/owner/sample-repo/commits*' => Http::response([
                [
                    'sha' => 'c1111111',
                    'commit' => [
                        'message' => 'Initial commit',
                        'author' => ['name' => 'dev1', 'email' => 'dev1@example.com', 'date' => '2023-01-01T10:00:00Z'],
                    ],
                ],
                [
                    'sha' => 'c2222222',
                    'commit' => [
                        'message' => 'Second commit',
                        'author' => ['name' => 'dev1', 'email' => 'dev1@example.com', 'date' => '2023-01-02T10:00:00Z'],
                    ],
                ],
            ], 200),
            'https://api.github.com/repos/owner/sample-repo/contributors*' => Http::response([
                [
                    'login' => 'dev1',
                    'contributions' => 2,
                ],
            ], 200),
            'https://api.github.com/repos/owner/sample-repo/languages' => Http::response([
                'PHP' => 8000,
                'Blade' => 2000,
            ], 200),
        ]);

        $this->service->ingest($repository);

        $repository->refresh();
        $this->assertEquals('completed', $repository->status);
        $this->assertEquals('A wonderful repo', $repository->description);
        $this->assertEquals(42, $repository->stars);
        $this->assertEquals('MIT', $repository->license);
        $this->assertNotNull($repository->last_analyzed_at);

        $this->assertDatabaseCount('repo_commits', 2);
        $this->assertDatabaseHas('repo_commits', ['sha' => 'c1111111', 'repository_id' => $repository->id]);
        $this->assertDatabaseHas('repo_commits', ['sha' => 'c2222222', 'repository_id' => $repository->id]);

        $this->assertDatabaseCount('repo_contributors', 1);
        $this->assertDatabaseHas('repo_contributors', [
            'repository_id' => $repository->id,
            'github_username' => 'dev1',
            'commit_count' => 2,
        ]);

        $this->assertDatabaseCount('repo_tech_stack', 2);
        $this->assertDatabaseHas('repo_tech_stack', [
            'repository_id' => $repository->id,
            'name' => 'PHP',
            'confidence' => 80.0,
        ]);
    }
}
