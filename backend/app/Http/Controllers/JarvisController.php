<?php

namespace App\Http\Controllers;

use App\Http\Requests\JarvisChatRequest;
use App\Http\Requests\JarvisCommandRequest;
use App\Services\N8nGatewayService;
use Illuminate\Http\JsonResponse;

class JarvisController extends Controller
{
    public function __construct(
        private readonly N8nGatewayService $n8nGatewayService
    ) {}

    public function command(JarvisCommandRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->n8nGatewayService->sendCommand(
            command: $validated['command'],
            notifyDiscord: (bool) ($validated['notify_discord'] ?? false)
        );

        return response()->json($result);
    }

    public function chatHistory(): JsonResponse
    {
        return response()->json($this->n8nGatewayService->getChatHistory());
    }

    public function chat(JarvisChatRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->n8nGatewayService->sendChatMessage($validated['text']);

        return response()->json($result);
    }

    public function triggerBriefing(): JsonResponse
    {
        $result = $this->n8nGatewayService->triggerMorningBriefing();

        return response()->json($result);
    }
}
