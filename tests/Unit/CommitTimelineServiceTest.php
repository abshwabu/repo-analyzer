<?php

namespace Tests\Unit;

use App\Models\RepoCommit;
use App\Models\RepoContributor;
use App\Models\Repository;
use App\Services\CommitTimelineService;
use App\Services\GithubIngestionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommitTimelineServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CommitTimelineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $ingestionService = new GithubIngestionService();
        $this->service = new CommitTimelineService($ingestionService);
    }

    public function test_compute_monthly_volume_groups_commits_by_month(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/monthly-repo',
            'owner' => 'owner',
            'name' => 'monthly-repo',
        ]);

        RepoCommit::create([
            'repository_id' => $repository->id,
            'sha' => 'sha1',
            'message' => 'commit 1',
            'committed_at' => Carbon::parse('2024-01-10 12:00:00'),
        ]);

        RepoCommit::create([
            'repository_id' => $repository->id,
            'sha' => 'sha2',
            'message' => 'commit 2',
            'committed_at' => Carbon::parse('2024-01-20 12:00:00'),
        ]);

        RepoCommit::create([
            'repository_id' => $repository->id,
            'sha' => 'sha3',
            'message' => 'commit 3',
            'committed_at' => Carbon::parse('2024-02-05 12:00:00'),
        ]);

        $commits = RepoCommit::where('repository_id', $repository->id)->orderBy('committed_at')->get();
        $volume = $this->service->computeMonthlyVolume($commits);

        $this->assertCount(2, $volume);
        $this->assertEquals('2024-01', $volume[0]['period']);
        $this->assertEquals(2, $volume[0]['count']);
        $this->assertEquals('2024-02', $volume[1]['period']);
        $this->assertEquals(1, $volume[1]['count']);
    }

    public function test_analyze_commit_significance_matches_rules(): void
    {
        $feat = $this->service->analyzeCommitSignificance('feat: add user login');
        $this->assertTrue($feat['is_significant']);
        $this->assertEquals('feature', $feat['primary_reason']);

        $breaking = $this->service->analyzeCommitSignificance('BREAKING CHANGE: drop legacy endpoints');
        $this->assertTrue($breaking['is_significant']);
        $this->assertEquals('breaking_change', $breaking['primary_reason']);

        $breakingPrefix = $this->service->analyzeCommitSignificance('breaking: change config key');
        $this->assertTrue($breakingPrefix['is_significant']);

        $refactor = $this->service->analyzeCommitSignificance('refactor(auth): simplify guard checks');
        $this->assertTrue($refactor['is_significant']);

        $perf = $this->service->analyzeCommitSignificance('perf: cache query results');
        $this->assertTrue($perf['is_significant']);

        $chore = $this->service->analyzeCommitSignificance('chore: bump deps version');
        $this->assertFalse($chore['is_significant']);

        $docs = $this->service->analyzeCommitSignificance('docs: update readme typo');
        $this->assertFalse($docs['is_significant']);
    }

    public function test_identify_significant_commits_fetches_and_persists_diff_stats(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/diff-repo',
            'owner' => 'owner',
            'name' => 'diff-repo',
        ]);

        $commit = RepoCommit::create([
            'repository_id' => $repository->id,
            'sha' => 'abcdef1234567890',
            'message' => 'feat: implement checkout flow',
            'author_name' => 'Developer',
            'author_email' => 'dev@test.com',
            'committed_at' => Carbon::parse('2024-03-01 10:00:00'),
            'additions' => 0,
            'deletions' => 0,
        ]);

        Http::fake([
            'https://api.github.com/repos/owner/diff-repo/commits/abcdef1234567890' => Http::response([
                'stats' => [
                    'additions' => 150,
                    'deletions' => 30,
                    'total' => 180,
                ],
                'files' => array_fill(0, 5, ['filename' => 'file.php']),
            ], 200),
        ]);

        $commits = collect([$commit]);
        $significant = $this->service->identifySignificantCommits($repository, $commits, true);

        $this->assertCount(1, $significant);
        $this->assertEquals('abcdef1234567890', $significant[0]['sha']);
        $this->assertEquals('abcdef1', $significant[0]['short_sha']);
        $this->assertEquals(150, $significant[0]['additions']);
        $this->assertEquals(30, $significant[0]['deletions']);

        $commit->refresh();
        $this->assertEquals(150, $commit->additions);
        $this->assertEquals(30, $commit->deletions);
    }

    public function test_get_contributors_summary_calculates_share_percentages(): void
    {
        $repository = Repository::create([
            'github_url' => 'https://github.com/owner/contrib-repo',
            'owner' => 'owner',
            'name' => 'contrib-repo',
        ]);

        RepoContributor::create([
            'repository_id' => $repository->id,
            'github_username' => 'alice',
            'commit_count' => 75,
            'first_commit_at' => Carbon::parse('2023-01-01'),
            'last_commit_at' => Carbon::parse('2024-01-01'),
        ]);

        RepoContributor::create([
            'repository_id' => $repository->id,
            'github_username' => 'bob',
            'commit_count' => 25,
            'first_commit_at' => Carbon::parse('2023-06-01'),
            'last_commit_at' => Carbon::parse('2023-12-01'),
        ]);

        $summary = $this->service->getContributorsSummary($repository);

        $this->assertEquals(2, $summary['total_contributors']);
        $this->assertEquals(100, $summary['total_commits']);
        $this->assertEquals('alice', $summary['contributors'][0]['github_username']);
        $this->assertEquals(75.0, $summary['contributors'][0]['percentage_share']);
        $this->assertEquals('bob', $summary['contributors'][1]['github_username']);
        $this->assertEquals(25.0, $summary['contributors'][1]['percentage_share']);
    }
}
