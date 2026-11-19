<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MemoryRuleService
{
    public function getUserDataPath(): string
    {
        return rtrim((string) config('jarvis.user_data_path'), '/');
    }

    public function getMemoryFilePath(): string
    {
        return $this->getUserDataPath().'/memory_rules.json';
    }

    /**
     * Read memory_rules.json and auto-purge expired volatile rules (expires_at < today).
     *
     * @return array{morning_rules: array<int, array<string, mixed>>, custom_scopes: array<string, array<int, array<string, mixed>>>}
     */
    public function getMemory(): array
    {
        $path = $this->getMemoryFilePath();

        if (! is_file($path)) {
            return [
                'morning_rules' => [],
                'custom_scopes' => [],
            ];
        }

        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        $data = [
            'morning_rules' => is_array($decoded['morning_rules'] ?? null) ? array_values($decoded['morning_rules']) : [],
            'custom_scopes' => is_array($decoded['custom_scopes'] ?? null) ? $decoded['custom_scopes'] : [],
        ];

        $today = Carbon::now()->toDateString();
        $dirty = false;

        $filteredMorning = [];
        foreach ($data['morning_rules'] as $rule) {
            if ($this->isExpired($rule, $today)) {
                $dirty = true;
                continue;
            }
            $filteredMorning[] = $rule;
        }
        $data['morning_rules'] = $filteredMorning;

        $filteredScopes = [];
        foreach ($data['custom_scopes'] as $scope => $rules) {
            if (! is_array($rules)) {
                $dirty = true;
                continue;
            }

            $kept = [];
            foreach ($rules as $rule) {
                if ($this->isExpired($rule, $today)) {
                    $dirty = true;
                    continue;
                }
                $kept[] = $rule;
            }

            $filteredScopes[$scope] = $kept;
        }
        $data['custom_scopes'] = $filteredScopes;

        if ($dirty) {
            $this->writeMemory($data);
        }

        return $data;
    }

    /**
     * Add a memory rule to morning_rules or a custom_scopes key.
     *
     * @return array<string, mixed>
     */
    public function addRule(
        string $scope,
        string $instruction,
        bool $volatile = false,
        ?string $expiresAt = null
    ): array {
        $memory = $this->getMemory();

        $rule = [
            'id' => 'rule_'.Str::uuid()->toString(),
            'instruction' => $instruction,
            'volatile' => $volatile,
            'expires_at' => $volatile ? $expiresAt : null,
            'created_at' => Carbon::now()->toIso8601String(),
        ];

        if ($scope === 'morning_rules') {
            $memory['morning_rules'][] = $rule;
        } else {
            if (! isset($memory['custom_scopes'][$scope]) || ! is_array($memory['custom_scopes'][$scope])) {
                $memory['custom_scopes'][$scope] = [];
            }
            $memory['custom_scopes'][$scope][] = $rule;
        }

        $this->writeMemory($memory);

        return array_merge($rule, ['scope' => $scope]);
    }

    /**
     * Remove a rule by ID from morning_rules or any custom_scopes entry.
     */
    public function deleteRule(string $ruleId): bool
    {
        $memory = $this->getMemory();
        $found = false;

        $morningBefore = count($memory['morning_rules']);
        $memory['morning_rules'] = array_values(array_filter(
            $memory['morning_rules'],
            fn (array $rule): bool => ($rule['id'] ?? null) !== $ruleId
        ));

        if (count($memory['morning_rules']) < $morningBefore) {
            $found = true;
        }

        foreach ($memory['custom_scopes'] as $scope => $rules) {
            if (! is_array($rules)) {
                continue;
            }
            $before = count($rules);
            $filtered = array_values(array_filter(
                $rules,
                fn (array $rule): bool => ($rule['id'] ?? null) !== $ruleId
            ));

            if (count($filtered) < $before) {
                $found = true;
                $memory['custom_scopes'][$scope] = $filtered;
            }
        }

        if ($found) {
            $this->writeMemory($memory);
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function isExpired(array $rule, string $today): bool
    {
        $isVolatile = (bool) ($rule['volatile'] ?? false);
        $expiresAt = $rule['expires_at'] ?? null;

        if (! $isVolatile || ! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        return strcmp(substr($expiresAt, 0, 10), $today) < 0;
    }

    /**
     * @param  array<string, mixed>  $memory
     */
    private function writeMemory(array $memory): void
    {
        $dir = $this->getUserDataPath();
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            $this->getMemoryFilePath(),
            json_encode($memory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
