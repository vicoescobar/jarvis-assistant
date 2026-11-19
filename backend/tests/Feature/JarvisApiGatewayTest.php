<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JarvisApiGatewayTest extends TestCase
{
    use RefreshDatabase;

    private string $tempUserDataDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempUserDataDir = sys_get_temp_dir().'/jarvis_test_user_data_'.uniqid();
        File::makeDirectory($this->tempUserDataDir, 0775, true);

        config([
            'jarvis.user_data_path' => $this->tempUserDataDir,
            'jarvis.n8n_internal_url' => 'http://jarvis-n8n:5678',
            'jarvis.owner_email' => 'owner@example.com',
            'jarvis.owner_password' => 'SuperSecretPassword123!',
        ]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempUserDataDir)) {
            File::deleteDirectory($this->tempUserDataDir);
        }

        parent::tearDown();
    }

    public function test_health_endpoint_is_public_and_reports_user_data_readable(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'user_data_readable' => true,
            ])
            ->assertJsonStructure(['status', 'user_data_readable', 'timestamp']);
    }

    public function test_seed_owner_command_and_sanctum_authentication_flow(): void
    {
        $this->artisan('jarvis:seed-owner')->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'owner@example.com',
        ]);

        // Unauthenticated requests are rejected
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/trackers')->assertUnauthorized();

        // Invalid login fails
        $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        // Valid login returns Sanctum token and user profile
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'SuperSecretPassword123!',
        ]);

        $loginResponse->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email'],
            ]);

        $token = $loginResponse->json('token');

        // Authenticated /api/auth/me
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson([
                'email' => 'owner@example.com',
            ]);

        // Logout revokes token
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJson(['status' => 'logged_out']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_tracker_crud_archive_and_path_traversal_protection(): void
    {
        Sanctum::actingAs(User::factory()->create());

        File::put($this->tempUserDataDir.'/trackers_registry.json', json_encode([
            'trackers' => [
                'job_applications' => [
                    'display_name' => 'Candidatures Stage',
                    'type' => 'COLLECTION_TABLE',
                    'include_in_morning_briefing' => true,
                    'fields_schema' => [
                        ['name' => 'company', 'type' => 'string', 'required' => true],
                        ['name' => 'status', 'type' => 'status', 'required' => true],
                    ],
                ],
            ],
        ]));
        File::put($this->tempUserDataDir.'/data_job_applications.json', json_encode([]));
        File::put($this->tempUserDataDir.'/archive_job_applications.json', json_encode([
            ['month' => '2026-10', 'summary' => '3 candidatures archivées'],
        ]));

        // List trackers
        $this->getJson('/api/trackers')
            ->assertOk()
            ->assertJsonPath('trackers.0.id', 'job_applications')
            ->assertJsonPath('trackers.0.record_count', 0);

        // Create record
        $createResponse = $this->postJson('/api/trackers/job_applications/records', [
            'company' => 'Acme Corp',
            'status' => 'pending',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('record.company', 'Acme Corp')
            ->assertJsonPath('record.status', 'pending');

        $recordId = $createResponse->json('record.id');
        $this->assertNotEmpty($recordId);

        // Update record
        $this->patchJson("/api/trackers/job_applications/records/{$recordId}", [
            'status' => 'interview',
        ])->assertOk()
            ->assertJsonPath('record.status', 'interview')
            ->assertJsonPath('record.company', 'Acme Corp');

        // Get tracker with archive
        $this->getJson('/api/trackers/job_applications?include_archive=true')
            ->assertOk()
            ->assertJsonPath('record_count', 1)
            ->assertJsonPath('records.0.status', 'interview')
            ->assertJsonPath('archive.0.month', '2026-10');

        // Delete record
        $this->deleteJson("/api/trackers/job_applications/records/{$recordId}")
            ->assertOk()
            ->assertJson(['status' => 'deleted', 'id' => $recordId]);

        $this->getJson('/api/trackers/job_applications')
            ->assertOk()
            ->assertJsonPath('record_count', 0);

        // Path traversal / invalid snake_case ID is rejected
        $this->getJson('/api/trackers/INVALID-ID!')
            ->assertStatus(422);
    }

    public function test_cycle_tracker_advance_skip_with_volatile_rule_and_set(): void
    {
        Sanctum::actingAs(User::factory()->create());

        File::put($this->tempUserDataDir.'/trackers_registry.json', json_encode([
            'trackers' => [
                'workout_cycle' => [
                    'display_name' => 'Cycle Sport',
                    'type' => 'CYCLE_STATE_MACHINE',
                    'include_in_morning_briefing' => true,
                    'cycle_config' => [
                        'steps' => ['Push', 'Pull', 'Legs', 'Repos'],
                        'current_index' => 0,
                        'last_updated' => '2026-11-18',
                    ],
                ],
            ],
        ]));
        File::put($this->tempUserDataDir.'/data_workout_cycle.json', json_encode([]));
        File::put($this->tempUserDataDir.'/memory_rules.json', json_encode([
            'morning_rules' => [],
            'custom_scopes' => [],
        ]));

        // 1. Advance cycle (0 -> 1: Pull)
        $this->postJson('/api/trackers/workout_cycle/cycle', [
            'action' => 'advance',
        ])->assertOk()
            ->assertJsonPath('current_index', 1)
            ->assertJsonPath('current_step', 'Pull')
            ->assertJsonPath('last_updated', Carbon::now()->toDateString());

        // 2. Skip cycle (keeps index 1: Pull AND creates volatile override rule expiring tomorrow)
        $skipResponse = $this->postJson('/api/trackers/workout_cycle/cycle', [
            'action' => 'skip',
        ]);

        $tomorrow = Carbon::now()->addDay()->toDateString();
        $skipResponse->assertOk()
            ->assertJsonPath('current_index', 1)
            ->assertJsonPath('current_step', 'Pull')
            ->assertJsonPath('volatile_rule.scope', 'cycle_overrides')
            ->assertJsonPath('volatile_rule.volatile', true)
            ->assertJsonPath('volatile_rule.expires_at', $tomorrow);

        // Verify volatile rule is persisted in memory_rules.json
        $memory = json_decode(File::get($this->tempUserDataDir.'/memory_rules.json'), true);
        $this->assertNotEmpty($memory['custom_scopes']['cycle_overrides']);
        $this->assertSame($tomorrow, $memory['custom_scopes']['cycle_overrides'][0]['expires_at']);

        // 3. Set cycle explicitly to index 3 (Repos)
        $this->postJson('/api/trackers/workout_cycle/cycle', [
            'action' => 'set',
            'target_index' => 3,
        ])->assertOk()
            ->assertJsonPath('current_index', 3)
            ->assertJsonPath('current_step', 'Repos');
    }

    public function test_memory_rules_crud_and_expired_volatile_auto_purge(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $yesterday = Carbon::now()->subDay()->toDateString();
        $tomorrow = Carbon::now()->addDay()->toDateString();

        File::put($this->tempUserDataDir.'/memory_rules.json', json_encode([
            'morning_rules' => [
                [
                    'id' => 'rule_permanent',
                    'instruction' => 'Toujours prioriser les offres de stage Java/Vue.',
                    'volatile' => false,
                    'expires_at' => null,
                    'created_at' => Carbon::now()->toIso8601String(),
                ],
            ],
            'custom_scopes' => [
                'temporary_context' => [
                    [
                        'id' => 'rule_expired',
                        'instruction' => 'Règle expirée hier',
                        'volatile' => true,
                        'expires_at' => $yesterday,
                        'created_at' => Carbon::now()->subDays(2)->toIso8601String(),
                    ],
                    [
                        'id' => 'rule_active_volatile',
                        'instruction' => 'Règle active jusqu à demain',
                        'volatile' => true,
                        'expires_at' => $tomorrow,
                        'created_at' => Carbon::now()->toIso8601String(),
                    ],
                ],
            ],
        ]));

        // GET /api/memory auto-purges expired volatile rule
        $getResponse = $this->getJson('/api/memory');
        $getResponse->assertOk()
            ->assertJsonCount(1, 'morning_rules')
            ->assertJsonCount(1, 'custom_scopes.temporary_context')
            ->assertJsonPath('custom_scopes.temporary_context.0.id', 'rule_active_volatile');

        // POST /api/memory/rules adds a new rule
        $createResponse = $this->postJson('/api/memory/rules', [
            'scope' => 'morning_rules',
            'instruction' => 'Signaler tout mail bancaire urgent.',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('rule.scope', 'morning_rules');

        $newRuleId = $createResponse->json('rule.id');

        // DELETE /api/memory/rules/{ruleId}
        $this->deleteJson("/api/memory/rules/{$newRuleId}")
            ->assertOk()
            ->assertJson(['status' => 'deleted', 'id' => $newRuleId]);
    }

    public function test_n8n_gateway_endpoints_proxy_and_shared_chat_history(): void
    {
        Sanctum::actingAs(User::factory()->create());

        File::put($this->tempUserDataDir.'/discord_chat_history.json', json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'Bonjour Jarvis', 'timestamp' => '2026-11-19T08:30:00Z'],
                ['role' => 'assistant', 'content' => 'Bonjour Victor. Prêt pour le point du jour.', 'timestamp' => '2026-11-19T08:30:02Z'],
            ],
        ]));

        Http::fake([
            'http://jarvis-n8n:5678/webhook/voice-command' => Http::response([
                'status' => 'executed',
                'output' => 'Tâche ajoutée dans Google Tasks.',
            ], 200),
            'http://jarvis-n8n:5678/webhook/discord-chat' => Http::response([
                'reply' => 'Cycle sport mis à jour.',
            ], 200),
            'http://jarvis-n8n:5678/webhook/morning-briefing' => Http::response([
                'status' => 'dispatched',
                'sections' => ['Agenda', 'Mails importants', 'Tâches en cours', 'Trackers', 'Suggestion'],
            ], 200),
        ]);

        // 1. Shared chat history
        $this->getJson('/api/jarvis/chat/history')
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.content', 'Bonjour Jarvis');

        // 2. Voice/command proxy
        $this->postJson('/api/jarvis/command', [
            'command' => 'Ajoute une tâche appeler le maître de stage',
        ])->assertOk()
            ->assertJsonPath('status', 'executed');

        Http::assertSent(fn ($request) => $request->url() === 'http://jarvis-n8n:5678/webhook/voice-command'
            && $request['command'] === 'Ajoute une tâche appeler le maître de stage'
            && $request['notify_discord'] === false);

        // 3. Chat proxy
        $this->postJson('/api/jarvis/chat', [
            'text' => 'Décale ma séance de sport à demain',
        ])->assertOk()
            ->assertJsonPath('reply', 'Cycle sport mis à jour.');

        Http::assertSent(fn ($request) => $request->url() === 'http://jarvis-n8n:5678/webhook/discord-chat'
            && $request['channel_id'] === 'web-dashboard'
            && $request['author'] === 'owner');

        // 4. Morning briefing trigger proxy
        $this->postJson('/api/jarvis/briefing/trigger')
            ->assertOk()
            ->assertJsonPath('status', 'dispatched');
    }
}
