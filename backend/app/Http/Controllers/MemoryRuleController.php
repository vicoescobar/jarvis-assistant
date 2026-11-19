<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMemoryRuleRequest;
use App\Services\MemoryRuleService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MemoryRuleController extends Controller
{
    public function __construct(
        private readonly MemoryRuleService $memoryRuleService
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->memoryRuleService->getMemory());
    }

    public function store(StoreMemoryRuleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $rule = $this->memoryRuleService->addRule(
            scope: $validated['scope'],
            instruction: $validated['instruction'],
            volatile: (bool) ($validated['volatile'] ?? false),
            expiresAt: $validated['expires_at'] ?? null
        );

        return response()->json([
            'rule' => $rule,
        ], 201);
    }

    public function destroy(string $ruleId): JsonResponse
    {
        $deleted = $this->memoryRuleService->deleteRule($ruleId);

        if (! $deleted) {
            throw new NotFoundHttpException("Memory rule [{$ruleId}] not found.");
        }

        return response()->json([
            'status' => 'deleted',
            'id' => $ruleId,
        ]);
    }
}
