<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $userDataPath = rtrim((string) config('jarvis.user_data_path'), '/');

        return response()->json([
            'status' => 'ok',
            'user_data_readable' => is_dir($userDataPath) && is_readable($userDataPath),
            'timestamp' => Carbon::now()->toIso8601String(),
        ]);
    }
}
