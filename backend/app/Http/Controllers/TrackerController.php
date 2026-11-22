<?php

namespace App\Http\Controllers;

use App\Http\Requests\CycleTrackerRequest;
use App\Http\Resources\TrackerResource;
use App\Services\TrackerStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TrackerController extends Controller
{
    public function __construct(
        private readonly TrackerStorageService $trackerStorageService
    ) {}

    public function index(): JsonResponse
    {
        $trackers = $this->trackerStorageService->listTrackers();

        return response()->json([
            'trackers' => TrackerResource::collection(collect($trackers)),
        ]);
    }

    public function show(Request $request, string $trackerId): JsonResponse
    {
        try {
            $includeArchive = filter_var($request->query('include_archive', false), FILTER_VALIDATE_BOOLEAN);
            $tracker = $this->trackerStorageService->getTracker($trackerId, $includeArchive);

            return response()->json(new TrackerResource($tracker));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function storeRecord(Request $request, string $trackerId): JsonResponse
    {
        try {
            $record = $this->trackerStorageService->insertRecord($trackerId, $request->all());

            return response()->json([
                'record' => $record,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function updateRecord(Request $request, string $trackerId, string $recordId): JsonResponse
    {
        try {
            $record = $this->trackerStorageService->updateRecord($trackerId, $recordId, $request->all());

            return response()->json([
                'record' => $record,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroyRecord(string $trackerId, string $recordId): JsonResponse
    {
        try {
            $this->trackerStorageService->deleteRecord($trackerId, $recordId);

            return response()->json([
                'status' => 'deleted',
                'id' => $recordId,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cycle(CycleTrackerRequest $request, string $trackerId): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->trackerStorageService->mutateCycle(
                trackerId: $trackerId,
                action: $validated['action'],
                targetIndex: isset($validated['target_index']) ? (int) $validated['target_index'] : null
            );

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function updateView(Request $request, string $trackerId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'enabled' => ['sometimes', 'boolean'],
                'html' => ['sometimes', 'nullable', 'string', 'max:24576'],
                'css' => ['sometimes', 'nullable', 'string', 'max:12288'],
                'js' => ['sometimes', 'nullable', 'string', 'max:24576'],
            ]);

            $result = $this->trackerStorageService->updateCustomView($trackerId, $validated);

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroyView(string $trackerId): JsonResponse
    {
        try {
            $this->trackerStorageService->resetCustomView($trackerId);

            return response()->json([
                'status' => 'reset',
                'tracker_id' => $trackerId,
                'custom_view' => null,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
