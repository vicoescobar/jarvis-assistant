<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class GeminiLiveService
{
    public function __construct(
        private readonly TrackerStorageService $trackerStorageService,
        private readonly MemoryRuleService $memoryRuleService,
        private readonly N8nGatewayService $n8nGatewayService
    ) {}

    /**
     * Mint a short-lived Gemini Multimodal Live API session configuration and ephemeral token.
     *
     * @return array<string, mixed>
     */
    public function createSession(): array
    {
        $apiKey = trim((string) config('jarvis.gemini_api_key', ''));
        if ($apiKey === '') {
            throw new ServiceUnavailableHttpException(
                null,
                'GEMINI_API_KEY non configurée sur le serveur.'
            );
        }

        $model = (string) config('jarvis.gemini_live_model', 'models/gemini-2.0-flash-live-001');
        if (! str_starts_with($model, 'models/')) {
            $model = 'models/'.$model;
        }

        $voiceName = (string) config('jarvis.gemini_live_voice', 'Puck');
        $systemInstruction = $this->buildSystemInstruction();
        $tools = $this->buildFunctionDeclarations();
        $expiresAt = Carbon::now()->addMinutes(15)->toIso8601String();

        $wsUrlOrToken = null;
        $tokenType = 'ephemeral_token';

        try {
            $response = Http::timeout(10)
                ->post("https://generativelanguage.googleapis.com/v1alpha/auth_tokens?key={$apiKey}", [
                    'uses' => 1,
                    'expireTime' => $expiresAt,
                    'newSessionExpireTime' => Carbon::now()->addMinutes(2)->toIso8601String(),
                ]);

            if ($response->successful()) {
                $payload = $response->json();
                $tokenName = $payload['name'] ?? $payload['token'] ?? null;
                if (is_string($tokenName) && $tokenName !== '') {
                    $wsUrlOrToken = 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContentConstrained?access_token='.urlencode($tokenName);
                }
            }
        } catch (\Throwable) {
            // Fall back to direct BidiGenerateContent WebSocket URL for the authenticated Sanctum session
        }

        if ($wsUrlOrToken === null) {
            $tokenType = 'direct_ws_url';
            $wsUrlOrToken = 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContent?key='.urlencode($apiKey);
        }

        return [
            'status' => 'ok',
            'model' => $model,
            'voice_name' => $voiceName,
            'token_type' => $tokenType,
            'ws_url_or_token' => $wsUrlOrToken,
            'expires_at' => $expiresAt,
            'system_instruction' => $systemInstruction,
            'tools' => $tools,
        ];
    }

    /**
     * Execute a tool call triggered by Gemini Live during a real-time voice conversation.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function executeTool(string $toolName, array $arguments = []): array
    {
        $result = match ($toolName) {
            'execute_jarvis_command' => $this->n8nGatewayService->sendCommand(
                command: (string) ($arguments['command'] ?? ''),
                notifyDiscord: (bool) ($arguments['notify_discord'] ?? false)
            ),
            'list_trackers' => [
                'trackers' => $this->trackerStorageService->listTrackers(),
            ],
            'mutate_tracker_cycle' => $this->trackerStorageService->mutateCycle(
                trackerId: (string) ($arguments['tracker_id'] ?? ''),
                action: (string) ($arguments['action'] ?? 'advance'),
                targetIndex: isset($arguments['target_index']) ? (int) $arguments['target_index'] : null
            ),
            'add_tracker_record' => [
                'record' => $this->trackerStorageService->insertRecord(
                    trackerId: (string) ($arguments['tracker_id'] ?? ''),
                    payload: is_array($arguments['record'] ?? null) ? $arguments['record'] : []
                ),
            ],
            'manage_memory_rule' => $this->handleMemoryRuleTool($arguments),
            'trigger_morning_briefing' => $this->n8nGatewayService->triggerMorningBriefing(),
            default => throw new UnprocessableEntityHttpException("Outil Live inconnu [{$toolName}]."),
        };

        return [
            'status' => 'ok',
            'tool_name' => $toolName,
            'result' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function handleMemoryRuleTool(array $arguments): array
    {
        $action = (string) ($arguments['action'] ?? 'add');

        if ($action === 'delete') {
            $ruleId = (string) ($arguments['rule_id'] ?? '');
            $deleted = $this->memoryRuleService->deleteRule($ruleId);

            return [
                'deleted' => $deleted,
                'rule_id' => $ruleId,
            ];
        }

        $rule = $this->memoryRuleService->addRule(
            scope: (string) ($arguments['scope'] ?? 'morning_rules'),
            instruction: (string) ($arguments['instruction'] ?? ''),
            volatile: (bool) ($arguments['volatile'] ?? false),
            expiresAt: isset($arguments['expires_at']) ? (string) $arguments['expires_at'] : null
        );

        return ['rule' => $rule];
    }

    /**
     * Build a compact, live runtime system prompt injecting current trackers and memory rules.
     */
    public function buildSystemInstruction(): string
    {
        $trackers = $this->trackerStorageService->listTrackers();
        $memory = $this->memoryRuleService->getMemory();
        $now = Carbon::now()->setTimezone('Europe/Paris')->format('Y-m-d H:i (l)');

        $trackerLines = [];
        foreach ($trackers as $tr) {
            $id = $tr['id'] ?? '';
            $name = $tr['display_name'] ?? $id;
            $type = $tr['type'] ?? 'TRACKER';
            if ($type === 'CYCLE_STATE_MACHINE') {
                $step = $tr['current_step'] ?? 'N/A';
                $steps = implode(' -> ', $tr['cycle_config']['steps'] ?? []);
                $trackerLines[] = "- [{$id}] {$name} (CYCLE_STATE_MACHINE) : étape active = '{$step}' (cycle: {$steps})";
            } else {
                $count = $tr['record_count'] ?? 0;
                $fields = implode(', ', array_map(fn ($f) => $f['name'] ?? '', $tr['fields_schema'] ?? []));
                $trackerLines[] = "- [{$id}] {$name} ({$type}) : {$count} entrée(s), champs = [{$fields}]";
            }
        }

        $trackerSummary = count($trackerLines) > 0
            ? implode("\n", $trackerLines)
            : '- Aucun tracker enregistré actuellement.';

        $ruleLines = [];
        foreach ($memory['morning_rules'] ?? [] as $rule) {
            $ruleLines[] = "- [morning_rules] ({$rule['id']}) : {$rule['instruction']}";
        }
        foreach ($memory['custom_scopes'] ?? [] as $scope => $rules) {
            foreach ($rules as $rule) {
                $exp = ! empty($rule['expires_at']) ? " (expire {$rule['expires_at']})" : '';
                $ruleLines[] = "- [{$scope}]{$exp} ({$rule['id']}) : {$rule['instruction']}";
            }
        }

        $rulesSummary = count($ruleLines) > 0
            ? implode("\n", $ruleLines)
            : '- Aucune règle mémoire active.';

        return <<<PROMPT
Tu es Jarvis, le chef de cabinet exécutif de Victor (étudiant en informatique & développeur fullstack).
Date et heure actuelles : {$now} (Europe/Paris).

RÈGLES DE TON ET D'ERGONOMIE TDAH (STRICTES) :
1. Ton direct, précis, factuel et exécutif en français. Réponses orales concises (1 à 3 phrases claires).
2. Interdiction absolue d'utiliser des formules infantilisantes ou condescendantes ("zéro pression", "au calme", "sans aucune culpabilité", "si l'énergie n'y est pas", "profite de cette liberté").
3. Ne minimise jamais une alerte urgente, une échéance ou une candidature de stage/alternance.
4. Pour toute session de production musicale sur Ableton Live, cadre-la comme un sandbox chronométré de 45 minutes (Sound Design OU Arrangement) se terminant par un export MP3 de 15 secondes.
5. Utilise immédiatement tes outils (function calling) dès que Victor demande une action sur ses trackers, ses règles mémoire, Google Tasks (@default), Google Calendar, Gmail, Drive, Docs ou Sheets.

TRACKERS DYNAMIQUES ACTUELS (/var/www/user_data) :
{$trackerSummary}

RÈGLES MÉMOIRE ACTIVES :
{$rulesSummary}
PROMPT;
    }

    /**
     * Return Gemini Live functionDeclarations for Jarvis tools.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildFunctionDeclarations(): array
    {
        return [
            [
                'name' => 'execute_jarvis_command',
                'description' => 'Exécuter une action dans Google Workspace (Google Tasks @default, Google Calendar primary, Gmail recherche/brouillons, Google Drive/Docs/Sheets) ou créer un nouveau schéma de tracker via l’orchestrateur n8n 01-voice-agent-core.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'command' => [
                            'type' => 'STRING',
                            'description' => 'Instruction complète en français à exécuter par 01-voice-agent-core.',
                        ],
                    ],
                    'required' => ['command'],
                ],
            ],
            [
                'name' => 'list_trackers',
                'description' => 'Lister tous les trackers dynamiques actifs, leurs compteurs et l’étape courante des cycles.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'mutate_tracker_cycle',
                'description' => 'Faire avancer (advance), maintenir/reporter avec règle volatile jusqu’à demain (skip), ou positionner (set) un tracker de type CYCLE_STATE_MACHINE.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'tracker_id' => [
                            'type' => 'STRING',
                            'description' => 'Identifiant snake_case du tracker de cycle.',
                        ],
                        'action' => [
                            'type' => 'STRING',
                            'description' => 'Action : advance, skip ou set.',
                        ],
                        'target_index' => [
                            'type' => 'INTEGER',
                            'description' => 'Index cible (requis uniquement si action = set).',
                        ],
                    ],
                    'required' => ['tracker_id', 'action'],
                ],
            ],
            [
                'name' => 'add_tracker_record',
                'description' => 'Ajouter une nouvelle entrée dans un tracker COLLECTION_TABLE ou ACTIVITY_LOG.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'tracker_id' => [
                            'type' => 'STRING',
                            'description' => 'Identifiant snake_case du tracker.',
                        ],
                        'record' => [
                            'type' => 'OBJECT',
                            'description' => 'Objet clé/valeur correspondant au fields_schema du tracker.',
                        ],
                    ],
                    'required' => ['tracker_id', 'record'],
                ],
            ],
            [
                'name' => 'manage_memory_rule',
                'description' => 'Ajouter ou supprimer une règle contextuelle dans memory_rules.json (morning_rules ou scope dynamique).',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'action' => [
                            'type' => 'STRING',
                            'description' => 'add ou delete.',
                        ],
                        'scope' => [
                            'type' => 'STRING',
                            'description' => 'morning_rules ou nom snake_case du scope dynamique.',
                        ],
                        'instruction' => [
                            'type' => 'STRING',
                            'description' => 'Texte de la règle mémoire (pour action = add).',
                        ],
                        'volatile' => [
                            'type' => 'BOOLEAN',
                            'description' => 'True si la règle est temporaire.',
                        ],
                        'expires_at' => [
                            'type' => 'STRING',
                            'description' => 'Date d’expiration YYYY-MM-DD si volatile = true.',
                        ],
                        'rule_id' => [
                            'type' => 'STRING',
                            'description' => 'Identifiant de la règle à supprimer (pour action = delete).',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
            [
                'name' => 'trigger_morning_briefing',
                'description' => 'Déclencher immédiatement le briefing matinal exécutif (02-morning-briefing) vers Discord #briefing.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object) [],
                ],
            ],
        ];
    }
}
