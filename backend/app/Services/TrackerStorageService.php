<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class TrackerStorageService
{
    public function __construct(
        private readonly MemoryRuleService $memoryRuleService,
        private readonly CustomViewSanitizer $customViewSanitizer
    ) {}

    public function getUserDataPath(): string
    {
        return rtrim((string) config('jarvis.user_data_path'), '/');
    }

    public function validateTrackerId(string $trackerId): void
    {
        if (! preg_match('/^[a-z0-9_]+$/', $trackerId)) {
            throw new InvalidArgumentException("Invalid tracker ID [{$trackerId}]. Must be strict snake_case.");
        }
    }

    /**
     * List all trackers enriched with record_count, current_step, custom_view, and recent_records.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTrackers(): array
    {
        $registry = $this->readRegistry();
        $trackers = $registry['trackers'] ?? [];
        $result = [];

        foreach ($trackers as $trackerId => $schema) {
            if (! is_string($trackerId) || ! is_array($schema)) {
                continue;
            }
            if (! preg_match('/^[a-z0-9_]+$/', $trackerId)) {
                continue;
            }

            $records = $this->filterActiveRecords($this->readTrackerRecords($trackerId));
            $result[] = $this->enrichTracker($trackerId, $schema, $records);
        }

        return $result;
    }

    /**
     * Get a single tracker schema along with active records (and optional archive).
     *
     * @return array<string, mixed>
     */
    public function getTracker(string $trackerId, bool $includeArchive = false): array
    {
        $this->validateTrackerId($trackerId);
        $registry = $this->readRegistry();

        if (! isset($registry['trackers'][$trackerId]) || ! is_array($registry['trackers'][$trackerId])) {
            throw new NotFoundHttpException("Tracker [{$trackerId}] not found.");
        }

        $schema = $registry['trackers'][$trackerId];
        $records = $this->filterActiveRecords($this->readTrackerRecords($trackerId));
        $tracker = $this->enrichTracker($trackerId, $schema, $records);
        $tracker['records'] = $records;

        if ($includeArchive) {
            $tracker['archive'] = $this->readTrackerArchive($trackerId);
        }

        return $tracker;
    }

    /**
     * Update or toggle the custom HTML/CSS view for a tracker.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateCustomView(string $trackerId, array $payload): array
    {
        $this->validateTrackerId($trackerId);
        $registry = $this->readRegistry();

        if (! isset($registry['trackers'][$trackerId]) || ! is_array($registry['trackers'][$trackerId])) {
            throw new NotFoundHttpException("Tracker [{$trackerId}] not found.");
        }

        $tracker = $registry['trackers'][$trackerId];
        $existingView = is_array($tracker['custom_view'] ?? null) ? $tracker['custom_view'] : [];

        $merged = [
            'enabled' => array_key_exists('enabled', $payload)
                ? (bool) $payload['enabled']
                : true,
            'html' => array_key_exists('html', $payload)
                ? (string) $payload['html']
                : (string) ($existingView['html'] ?? ''),
            'css' => array_key_exists('css', $payload)
                ? (string) $payload['css']
                : (string) ($existingView['css'] ?? ''),
            'js' => array_key_exists('js', $payload)
                ? (string) $payload['js']
                : (string) ($existingView['js'] ?? ''),
            'updated_at' => Carbon::now()->toIso8601String(),
        ];

        $sanitizedView = $this->customViewSanitizer->sanitizeCustomView($merged);
        $tracker['custom_view'] = $sanitizedView;

        $registry['trackers'][$trackerId] = $tracker;
        $this->writeRegistry($registry);

        return [
            'tracker_id' => $trackerId,
            'custom_view' => $sanitizedView,
        ];
    }

    /**
     * Reset/remove a tracker's custom view back to the generic view.
     */
    public function resetCustomView(string $trackerId): void
    {
        $this->validateTrackerId($trackerId);
        $registry = $this->readRegistry();

        if (! isset($registry['trackers'][$trackerId]) || ! is_array($registry['trackers'][$trackerId])) {
            throw new NotFoundHttpException("Tracker [{$trackerId}] not found.");
        }

        unset($registry['trackers'][$trackerId]['custom_view']);
        $this->writeRegistry($registry);
    }

    /**
     * Insert a new record into data_<trackerId>.json.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function insertRecord(string $trackerId, array $payload): array
    {
        $this->assertTrackerExists($trackerId);

        $records = $this->readTrackerRecords($trackerId);
        $now = Carbon::now()->toIso8601String();

        $record = array_merge($payload, [
            'id' => Str::uuid()->toString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $records[] = $record;
        $this->writeTrackerRecords($trackerId, $records);

        return $record;
    }

    /**
     * Partially update an existing record in data_<trackerId>.json.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateRecord(string $trackerId, string $recordId, array $payload): array
    {
        $this->assertTrackerExists($trackerId);

        $records = $this->readTrackerRecords($trackerId);
        $updatedRecord = null;

        foreach ($records as $index => $record) {
            if (($record['id'] ?? null) === $recordId) {
                $sanitizedPayload = $payload;
                unset($sanitizedPayload['id'], $sanitizedPayload['created_at']);

                $updatedRecord = array_merge($record, $sanitizedPayload, [
                    'id' => $record['id'],
                    'created_at' => $record['created_at'] ?? Carbon::now()->toIso8601String(),
                    'updated_at' => Carbon::now()->toIso8601String(),
                ]);
                $records[$index] = $updatedRecord;
                break;
            }
        }

        if ($updatedRecord === null) {
            throw new NotFoundHttpException("Record [{$recordId}] not found in tracker [{$trackerId}].");
        }

        $this->writeTrackerRecords($trackerId, $records);

        return $updatedRecord;
    }

    /**
     * Delete a record by ID from data_<trackerId>.json.
     */
    public function deleteRecord(string $trackerId, string $recordId): void
    {
        $this->assertTrackerExists($trackerId);

        $records = $this->readTrackerRecords($trackerId);
        $before = count($records);

        $filtered = array_values(array_filter(
            $records,
            fn (array $record): bool => ($record['id'] ?? null) !== $recordId
        ));

        if (count($filtered) === $before) {
            throw new NotFoundHttpException("Record [{$recordId}] not found in tracker [{$trackerId}].");
        }

        $this->writeTrackerRecords($trackerId, $filtered);
    }

    /**
     * Advance, skip (with volatile override rule in memory_rules.json), or set a CYCLE_STATE_MACHINE tracker.
     *
     * @return array<string, mixed>
     */
    public function mutateCycle(string $trackerId, string $action, ?int $targetIndex = null): array
    {
        $this->validateTrackerId($trackerId);
        $registry = $this->readRegistry();

        if (! isset($registry['trackers'][$trackerId]) || ! is_array($registry['trackers'][$trackerId])) {
            throw new NotFoundHttpException("Tracker [{$trackerId}] not found.");
        }

        $tracker = $registry['trackers'][$trackerId];
        if (($tracker['type'] ?? null) !== 'CYCLE_STATE_MACHINE') {
            throw new UnprocessableEntityHttpException("Tracker [{$trackerId}] is not a CYCLE_STATE_MACHINE.");
        }

        $steps = $tracker['cycle_config']['steps'] ?? [];
        if (! is_array($steps) || count($steps) === 0) {
            throw new UnprocessableEntityHttpException("Tracker [{$trackerId}] has no configured cycle steps.");
        }

        $steps = array_values($steps);
        $stepCount = count($steps);
        $currentIndex = (int) ($tracker['cycle_config']['current_index'] ?? 0);
        $today = Carbon::now()->toDateString();
        $volatileRule = null;
        $loggedRecord = null;

        if ($action === 'advance') {
            $previousStep = $steps[$currentIndex] ?? null;
            $currentIndex = ($currentIndex + 1) % $stepCount;
            $tracker['cycle_config']['current_index'] = $currentIndex;
            $tracker['cycle_config']['last_updated'] = $today;

            $loggedRecord = $this->insertRecord($trackerId, [
                'action' => 'advance',
                'previous_step' => $previousStep,
                'step' => $steps[$currentIndex],
                'step_index' => $currentIndex,
                'date' => $today,
            ]);
        } elseif ($action === 'skip') {
            $tracker['cycle_config']['current_index'] = $currentIndex;
            $tracker['cycle_config']['last_updated'] = $today;

            $currentStepName = (string) ($steps[$currentIndex] ?? 'current');
            $tomorrow = Carbon::now()->addDay()->toDateString();

            $volatileRule = $this->memoryRuleService->addRule(
                scope: 'cycle_overrides',
                instruction: "Cycle [{$trackerId}] reporté le {$today} : maintenir l'étape '{$currentStepName}' (index {$currentIndex}) sans avancer automatiquement au prochain briefing.",
                volatile: true,
                expiresAt: $tomorrow
            );
        } elseif ($action === 'set') {
            if ($targetIndex === null || $targetIndex < 0 || $targetIndex >= $stepCount) {
                throw new UnprocessableEntityHttpException("target_index must be between 0 and ".($stepCount - 1).'.');
            }
            $currentIndex = $targetIndex;
            $tracker['cycle_config']['current_index'] = $currentIndex;
            $tracker['cycle_config']['last_updated'] = $today;
        } else {
            throw new UnprocessableEntityHttpException("Unsupported cycle action [{$action}].");
        }

        $registry['trackers'][$trackerId] = $tracker;
        $this->writeRegistry($registry);

        $response = [
            'tracker_id' => $trackerId,
            'action' => $action,
            'current_index' => $currentIndex,
            'current_step' => $steps[$currentIndex] ?? null,
            'last_updated' => $today,
        ];

        if ($loggedRecord !== null) {
            $response['record'] = $loggedRecord;
        }

        if ($volatileRule !== null) {
            $response['volatile_rule'] = $volatileRule;
        }

        return $response;
    }

    /**
     * Filter out soft-deleted entries (`deleted === true` or any field set to `"SUPPRIMÉ"` / `"[SUPPRIMÉ]"`).
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function filterActiveRecords(array $records): array
    {
        return array_values(array_filter($records, function (mixed $record): bool {
            if (! is_array($record)) {
                return false;
            }
            if (($record['deleted'] ?? false) === true) {
                return false;
            }

            foreach ($record as $value) {
                if (is_string($value)) {
                    $normalized = mb_strtoupper(trim($value), 'UTF-8');
                    if (in_array($normalized, ['SUPPRIMÉ', '[SUPPRIMÉ]', 'SUPPRIME', '[SUPPRIME]'], true)) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function enrichTracker(string $trackerId, array $schema, array $records): array
    {
        $currentStep = null;
        if (($schema['type'] ?? null) === 'CYCLE_STATE_MACHINE') {
            $steps = $schema['cycle_config']['steps'] ?? [];
            $currentIndex = (int) ($schema['cycle_config']['current_index'] ?? 0);
            if (is_array($steps) && isset($steps[$currentIndex])) {
                $currentStep = $steps[$currentIndex];
            }
        }

        $sanitizedCustomView = isset($schema['custom_view']) && is_array($schema['custom_view'])
            ? $this->customViewSanitizer->sanitizeCustomView($schema['custom_view'])
            : null;

        $recentRecords = array_slice(array_reverse($records), 0, 200);

        $enriched = array_merge(
            ['id' => $trackerId],
            $schema,
            [
                'record_count' => count($records),
                'current_step' => $currentStep,
                'recent_records' => $recentRecords,
            ]
        );

        if ($sanitizedCustomView !== null) {
            $enriched['custom_view'] = $sanitizedCustomView;
        } else {
            unset($enriched['custom_view']);
        }

        return $enriched;
    }

    private function assertTrackerExists(string $trackerId): void
    {
        $this->validateTrackerId($trackerId);
        $registry = $this->readRegistry();

        if (! isset($registry['trackers'][$trackerId]) || ! is_array($registry['trackers'][$trackerId])) {
            throw new NotFoundHttpException("Tracker [{$trackerId}] not found.");
        }
    }

    /**
     * @return array{trackers: array<string, array<string, mixed>>}
     */
    private function readRegistry(): array
    {
        $path = $this->getUserDataPath().'/trackers_registry.json';
        if (! is_file($path)) {
            return ['trackers' => []];
        }

        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return [
            'trackers' => is_array($decoded['trackers'] ?? null) ? $decoded['trackers'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $registry
     */
    private function writeRegistry(array $registry): void
    {
        $this->atomicWriteJson($this->getUserDataPath().'/trackers_registry.json', $registry);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readTrackerRecords(string $trackerId): array
    {
        $path = $this->getUserDataPath()."/data_{$trackerId}.json";
        if (! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function writeTrackerRecords(string $trackerId, array $records): void
    {
        $this->atomicWriteJson($this->getUserDataPath()."/data_{$trackerId}.json", array_values($records));
    }

    /**
     * @return array<int, mixed>
     */
    private function readTrackerArchive(string $trackerId): array
    {
        $path = $this->getUserDataPath()."/archive_{$trackerId}.json";
        if (! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            return [];
        }

        return isset($decoded['archives']) && is_array($decoded['archives'])
            ? array_values($decoded['archives'])
            : array_values($decoded);
    }

    private function atomicWriteJson(string $filePath, mixed $data): void
    {
        $dir = dirname($filePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            $filePath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
