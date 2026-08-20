<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\RepositoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class)->name('api.v1.health');

    // Repository Analysis & Ingestion
    Route::post('/repositories/analyze', [RepositoryController::class, 'analyze'])->name('api.v1.repositories.analyze');
    Route::get('/repositories/{id}/status', [RepositoryController::class, 'status'])->name('api.v1.repositories.status');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        })->name('api.v1.user');
    });
});

