<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\JarvisController;
use App\Http\Controllers\MemoryRuleController;
use App\Http\Controllers\TrackerController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/trackers', [TrackerController::class, 'index']);
    Route::get('/trackers/{trackerId}', [TrackerController::class, 'show']);
    Route::post('/trackers/{trackerId}/records', [TrackerController::class, 'storeRecord']);
    Route::patch('/trackers/{trackerId}/records/{recordId}', [TrackerController::class, 'updateRecord']);
    Route::delete('/trackers/{trackerId}/records/{recordId}', [TrackerController::class, 'destroyRecord']);
    Route::post('/trackers/{trackerId}/cycle', [TrackerController::class, 'cycle']);

    Route::get('/memory', [MemoryRuleController::class, 'index']);
    Route::post('/memory/rules', [MemoryRuleController::class, 'store']);
    Route::delete('/memory/rules/{ruleId}', [MemoryRuleController::class, 'destroy']);

    Route::post('/jarvis/command', [JarvisController::class, 'command']);
    Route::get('/jarvis/chat/history', [JarvisController::class, 'chatHistory']);
    Route::post('/jarvis/chat', [JarvisController::class, 'chat']);
    Route::post('/jarvis/briefing/trigger', [JarvisController::class, 'triggerBriefing']);
    Route::post('/jarvis/live/session', [JarvisController::class, 'liveSession']);
    Route::post('/jarvis/live/tool', [JarvisController::class, 'liveTool']);
});
