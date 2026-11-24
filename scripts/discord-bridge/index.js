'use strict';

const { Client, GatewayIntentBits } = require('discord.js');
const http = require('http');
const https = require('https');
const { URL } = require('url');

const DISCORD_BOT_TOKEN = (process.env.DISCORD_BOT_TOKEN || '').trim();
const DISCORD_CHATBOT_CHANNEL_ID = (process.env.DISCORD_CHATBOT_CHANNEL_ID || '').trim();
const N8N_CHATBOT_WEBHOOK_URL = (
  process.env.N8N_CHATBOT_WEBHOOK_URL || 'http://jarvis-n8n:5678/webhook/discord-chat'
).trim();
const JARVIS_WEBHOOK_SECRET = (process.env.JARVIS_WEBHOOK_SECRET || '').trim();

if (!JARVIS_WEBHOOK_SECRET) {
  console.warn(
    '[Jarvis Discord Bridge] WARNING: JARVIS_WEBHOOK_SECRET is missing or empty! Authenticated n8n webhooks will reject requests with 403/401.'
  );
}

const AUDIO_EXTENSIONS = ['.ogg', '.mp3', '.wav', '.m4a', '.webm'];
const MAX_CHUNK_LENGTH = 1900;

if (
  !DISCORD_BOT_TOKEN ||
  DISCORD_BOT_TOKEN === 'your_discord_bot_token_here' ||
  !DISCORD_CHATBOT_CHANNEL_ID ||
  DISCORD_CHATBOT_CHANNEL_ID === '000000000000000000'
) {
  console.log(
    '[Jarvis Discord Bridge] DISCORD_BOT_TOKEN or DISCORD_CHATBOT_CHANNEL_ID is not configured yet. Standing by without crash-looping.'
  );
  setInterval(() => {}, 3600000);
  return;
}

function postToN8nWebhook(targetUrl, payloadObj) {
  return new Promise((resolve, reject) => {
    const parsed = new URL(targetUrl);
    const transport = parsed.protocol === 'https:' ? https : http;
    const bodyStr = JSON.stringify(payloadObj);

    const headers = {
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(bodyStr)
    };
    if (JARVIS_WEBHOOK_SECRET) {
      headers['X-Jarvis-Webhook-Token'] = JARVIS_WEBHOOK_SECRET;
    }

    const req = transport.request(
      {
        hostname: parsed.hostname,
        port: parsed.port || (parsed.protocol === 'https:' ? 443 : 80),
        path: parsed.pathname + parsed.search,
        method: 'POST',
        headers,
        timeout: 120000
      },
      (res) => {
        let rawBody = '';
        res.on('data', (chunk) => {
          rawBody += chunk;
        });
        res.on('end', () => {
          if (res.statusCode >= 200 && res.statusCode < 300) {
            try {
              resolve(JSON.parse(rawBody));
            } catch (e) {
              resolve({ reply: rawBody });
            }
          } else {
            reject(new Error(`n8n HTTP ${res.statusCode}: ${rawBody}`));
          }
        });
      }
    );

    req.on('timeout', () => {
      req.destroy(new Error('n8n webhook request timed out after 120s'));
    });
    req.on('error', reject);
    req.write(bodyStr);
    req.end();
  });
}

function splitMessageChunks(text, maxLen = MAX_CHUNK_LENGTH) {
  const clean = String(text || '').trim();
  if (!clean) return ['(Réponse vide)'];
  if (clean.length <= maxLen) return [clean];

  const chunks = [];
  let remaining = clean;
  while (remaining.length > maxLen) {
    let splitIdx = remaining.lastIndexOf('\n', maxLen);
    if (splitIdx <= 0) {
      splitIdx = remaining.lastIndexOf(' ', maxLen);
    }
    if (splitIdx <= 0) {
      splitIdx = maxLen;
    }
    chunks.push(remaining.slice(0, splitIdx).trim());
    remaining = remaining.slice(splitIdx).trim();
  }
  if (remaining.length > 0) {
    chunks.push(remaining);
  }
  return chunks;
}

function extractFirstAudioAttachment(attachmentsCollection) {
  if (!attachmentsCollection || attachmentsCollection.size === 0) return null;
  for (const att of attachmentsCollection.values()) {
    const contentType = String(att.contentType || '').toLowerCase();
    const fileName = String(att.name || att.url || '').toLowerCase().split('?')[0];
    const isAudioMime = contentType.startsWith('audio/');
    const isAudioExt = AUDIO_EXTENSIONS.some((ext) => fileName.endsWith(ext));
    if (isAudioMime || isAudioExt) {
      let resolvedMime = contentType.split(';')[0].trim();
      if (!resolvedMime || !resolvedMime.startsWith('audio/')) {
        if (fileName.endsWith('.mp3')) resolvedMime = 'audio/mp3';
        else if (fileName.endsWith('.wav')) resolvedMime = 'audio/wav';
        else if (fileName.endsWith('.m4a')) resolvedMime = 'audio/mp4';
        else if (fileName.endsWith('.webm')) resolvedMime = 'audio/webm';
        else resolvedMime = 'audio/ogg';
      }
      return {
        url: att.url,
        content_type: resolvedMime,
        name: att.name || 'voice_message.ogg'
      };
    }
  }
  return null;
}

const client = new Client({
  intents: [
    GatewayIntentBits.Guilds,
    GatewayIntentBits.GuildMessages,
    GatewayIntentBits.MessageContent
  ]
});

client.once('ready', () => {
  console.log(
    `[Jarvis Discord Bridge] Connected as ${client.user.tag} | Listening on channel ${DISCORD_CHATBOT_CHANNEL_ID}`
  );
});

client.on('messageCreate', async (message) => {
  try {
    if (!message || !message.author || message.author.bot) return;
    if (message.channelId !== DISCORD_CHATBOT_CHANNEL_ID) return;

    const text = String(message.content || '').trim();
    const audioAttachment = extractFirstAudioAttachment(message.attachments);

    if (!text && !audioAttachment) return;

    await message.channel.sendTyping();
    const typingInterval = setInterval(() => {
      message.channel.sendTyping().catch(() => {});
    }, 8000);

    try {
      const payload = {
        message_id: message.id,
        channel_id: message.channelId,
        author: message.author.username || message.author.tag || 'user',
        text,
        audio_attachment: audioAttachment,
        timestamp: new Date(message.createdTimestamp || Date.now()).toISOString()
      };

      const response = await postToN8nWebhook(N8N_CHATBOT_WEBHOOK_URL, payload);
      const replyText = String(response?.reply || response?.output || '').trim();

      if (replyText) {
        const chunks = splitMessageChunks(replyText);
        for (let i = 0; i < chunks.length; i++) {
          if (i === 0) {
            await message.reply({ content: chunks[i], allowedMentions: { repliedUser: false } });
          } else {
            await message.channel.send({ content: chunks[i] });
          }
        }
      }
    } finally {
      clearInterval(typingInterval);
    }
  } catch (err) {
    console.error('[Jarvis Discord Bridge] Error processing message:', err.message);
    try {
      await message.reply({
        content: `⚠️ Erreur lors du traitement par Jarvis : ${err.message}`,
        allowedMentions: { repliedUser: false }
      });
    } catch (_) {}
  }
});

client.login(DISCORD_BOT_TOKEN).catch((err) => {
  console.error('[Jarvis Discord Bridge] Failed to login to Discord:', err.message);
  process.exit(1);
});
