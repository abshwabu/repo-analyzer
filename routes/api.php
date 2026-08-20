<?php

use App\Http\Controllers\Api\V1\AiSummaryController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ReadmeController;
use App\Http\Controllers\Api\V1\RepositoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class)->name('api.v1.health');

    // Repository Analysis & Ingestion
    Route::post('/repositories/analyze', [RepositoryController::class, 'analyze'])->name('api.v1.repositories.analyze');
    Route::get('/repositories/{id}/status', [RepositoryController::class, 'status'])->name('api.v1.repositories.status');
    Route::get('/repositories/{id}/timeline', [RepositoryController::class, 'timeline'])->name('api.v1.repositories.timeline');
    Route::get('/repositories/{id}/contributors', [RepositoryController::class, 'contributors'])->name('api.v1.repositories.contributors');
    Route::get('/repositories/{id}/contributing', [RepositoryController::class, 'contributing'])->name('api.v1.repositories.contributing');
    Route::post('/repositories/{id}/summarize', [AiSummaryController::class, 'summarize'])->name('api.v1.repositories.summarize');

    // README Generator
    Route::post('/repositories/{id}/generate-readme', [ReadmeController::class, 'generate'])->name('api.v1.repositories.generate-readme');
    Route::get('/repositories/{id}/readme', [ReadmeController::class, 'show'])->name('api.v1.repositories.readme');
    Route::get('/repositories/{id}/readme/download', [ReadmeController::class, 'download'])->name('api.v1.repositories.readme.download');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        })->name('api.v1.user');
    });
});

