<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('default_branch');
            $table->text('error_message')->nullable()->after('status');
            $table->text('description')->nullable()->after('error_message');
            $table->integer('stars')->default(0)->after('description');
            $table->string('license')->nullable()->after('stars');
            $table->timestamp('repo_created_at')->nullable()->after('license');
            $table->unique('github_url');
        });

        Schema::table('repo_commits', function (Blueprint $table) {
            $table->unique(['repository_id', 'sha']);
        });

        Schema::table('repo_contributors', function (Blueprint $table) {
            $table->unique(['repository_id', 'github_username']);
        });

        Schema::table('repo_tech_stack', function (Blueprint $table) {
            $table->unique(['repository_id', 'category', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repo_tech_stack', function (Blueprint $table) {
            $table->dropUnique(['repository_id', 'category', 'name']);
        });

        Schema::table('repo_contributors', function (Blueprint $table) {
            $table->dropUnique(['repository_id', 'github_username']);
        });

        Schema::table('repo_commits', function (Blueprint $table) {
            $table->dropUnique(['repository_id', 'sha']);
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropUnique(['github_url']);
            $table->dropColumn([
                'status',
                'error_message',
                'description',
                'stars',
                'license',
                'repo_created_at',
            ]);
        });
    }
};
