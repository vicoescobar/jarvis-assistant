<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class N8nGatewayService
{
    public function getBaseUrl(): string
    {
        return rtrim((string) config('jarvis.n8n_internal_url', 'http://jarvis-n8n:5678'), '/');
    }

    public function getUserDataPath(): string
    {
        return rtrim((string) config('jarvis.user_data_path'), '/');
    }

    /**
     * Proxy a natural language command to POST /webhook/voice-command.
     *
     * @return array<string, mixed>
     */
    public function sendCommand(string $command, bool $notifyDiscord = false): array
    {
        $response = Http::timeout(45)->post($this->getBaseUrl().'/webhook/voice-command', [
            'command' => $command,
            'notify_discord' => $notifyDiscord,
        ]);

        $response->throw();

        $json = $response->json();

        return is_array($json) ? $json : ['output' => $response->body()];
    }

    /**
     * Read shared persistent chat history from discord_chat_history.json.
     *
     * @return array{messages: array<int, array<string, mixed>>}
     */
    public function getChatHistory(): array
    {
        $path = $this->getUserDataPath().'/discord_chat_history.json';
        if (! is_file($path)) {
            return ['messages' => []];
        }

        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return [
            'messages' => is_array($decoded['messages'] ?? null) ? array_values($decoded['messages']) : [],
        ];
    }

    /**
     * Proxy a chat message to POST /webhook/discord-chat.
     *
     * @return array<string, mixed>
     */
    public function sendChatMessage(string $text): array
    {
        $response = Http::timeout(45)->post($this->getBaseUrl().'/webhook/discord-chat', [
            'text' => $text,
            'channel_id' => 'web-dashboard',
            'author' => 'owner',
        ]);

        $response->throw();

        $json = $response->json();
        if (is_array($json)) {
            $reply = $json['reply'] ?? $json['output'] ?? $json['message'] ?? '';

            return array_merge($json, ['reply' => (string) $reply]);
        }

        return ['reply' => $response->body()];
    }

    /**
     * Trigger the morning briefing workflow via POST /webhook/morning-briefing.
     *
     * @return array<string, mixed>
     */
    public function triggerMorningBriefing(): array
    {
        $response = Http::timeout(45)->post($this->getBaseUrl().'/webhook/morning-briefing');

        $response->throw();

        $json = $response->json();

        return is_array($json) ? $json : ['output' => $response->body()];
    }
}
