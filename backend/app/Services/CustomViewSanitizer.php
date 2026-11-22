<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class CustomViewSanitizer
{
    public const MAX_HTML_BYTES = 16384;

    public const MAX_CSS_BYTES = 8192;

    /**
     * Sanitize custom tracker HTML by stripping executable tags, event handlers, and dangerous protocols.
     */
    public function sanitizeHtml(string $html): string
    {
        $clean = substr($html, 0, self::MAX_HTML_BYTES);

        // Strip paired forbidden elements and their contents
        $clean = (string) preg_replace(
            '/<(script|iframe|object|embed|form|link|meta|base|applet)\b[^>]*>[\s\S]*?<\/\1>/i',
            '',
            $clean
        );

        // Strip self-closing or unclosed forbidden elements
        $clean = (string) preg_replace(
            '/<\/?(script|iframe|object|embed|form|link|meta|base|applet)\b[^>]*\/?>/i',
            '',
            $clean
        );

        // Strip inline on* event attributes (onclick, onload, onerror, etc.)
        $clean = (string) preg_replace(
            '/\s+on[a-z0-9_-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            '',
            $clean
        );

        // Neutralize javascript:, vbscript:, and data:text/html protocols
        $clean = (string) preg_replace(
            '/(javascript|vbscript|data\s*:\s*text\/html)\s*:/i',
            'blocked:',
            $clean
        );

        return trim($clean);
    }

    /**
     * Sanitize custom tracker CSS by stripping @import, expression(), and dangerous URLs.
     */
    public function sanitizeCss(string $css): string
    {
        $clean = substr($css, 0, self::MAX_CSS_BYTES);

        $clean = (string) preg_replace('/@import\b[^;]*;?/i', '', $clean);
        $clean = (string) preg_replace('/expression\s*\([^)]*\)/i', '', $clean);
        $clean = (string) preg_replace(
            '/url\s*\(\s*[\'"]?\s*(javascript|vbscript|data\s*:\s*text\/html)[^)]*\)/i',
            'url()',
            $clean
        );
        $clean = (string) preg_replace('/<\/?style\b[^>]*>/i', '', $clean);

        return trim($clean);
    }

    /**
     * Normalize and sanitize a custom_view object.
     *
     * @param  array<string, mixed>|null  $customView
     * @return array{enabled: bool, html: string, css: string, updated_at: string}|null
     */
    public function sanitizeCustomView(?array $customView): ?array
    {
        if (! is_array($customView)) {
            return null;
        }

        $html = $this->sanitizeHtml((string) ($customView['html'] ?? ''));
        $css = $this->sanitizeCss((string) ($customView['css'] ?? ''));
        $enabled = array_key_exists('enabled', $customView)
            ? (bool) $customView['enabled']
            : ($html !== '');

        return [
            'enabled' => $enabled,
            'html' => $html,
            'css' => $css,
            'updated_at' => is_string($customView['updated_at'] ?? null) && $customView['updated_at'] !== ''
                ? $customView['updated_at']
                : Carbon::now()->toIso8601String(),
        ];
    }
}
