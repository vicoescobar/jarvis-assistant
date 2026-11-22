<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class CustomViewSanitizer
{
    public const MAX_HTML_BYTES = 24576;

    public const MAX_CSS_BYTES = 12288;

    public const MAX_JS_BYTES = 24576;

    /**
     * Sanitize custom tracker HTML by stripping external resource tags, embedded frames, and dangerous protocols.
     */
    public function sanitizeHtml(string $html): string
    {
        $clean = substr($html, 0, self::MAX_HTML_BYTES);

        // Strip paired forbidden elements and their contents
        $clean = (string) preg_replace(
            '/<(script|iframe|object|embed|link|meta|base|applet)\b[^>]*>[\s\S]*?<\/\1>/i',
            '',
            $clean
        );

        // Strip self-closing or unclosed forbidden elements
        $clean = (string) preg_replace(
            '/<\/?(script|iframe|object|embed|link|meta|base|applet)\b[^>]*\/?>/i',
            '',
            $clean
        );

        // Neutralize javascript:, vbscript:, and data:text/html protocols in href/src
        $clean = (string) preg_replace(
            '/(javascript|vbscript|data\s*:\s*text\/html)\s*:/i',
            'blocked:',
            $clean
        );

        return trim($clean);
    }

    /**
     * Sanitize custom tracker CSS by stripping @import, expression(), and external URLs.
     */
    public function sanitizeCss(string $css): string
    {
        $clean = substr($css, 0, self::MAX_CSS_BYTES);

        $clean = (string) preg_replace('/@import\b[^;]*;?/i', '', $clean);
        $clean = (string) preg_replace('/expression\s*\([^)]*\)/i', '', $clean);
        $clean = (string) preg_replace(
            '/url\s*\(\s*[\'"]?\s*(https?:|\/\/|javascript:|vbscript:|data\s*:\s*text\/html)[^)]*\)/i',
            'url()',
            $clean
        );
        $clean = (string) preg_replace('/<\/?style\b[^>]*>/i', '', $clean);

        return trim($clean);
    }

    /**
     * Sanitize custom tracker JavaScript (executed strictly inside <iframe sandbox="allow-scripts"> with default-src 'none' CSP).
     */
    public function sanitizeJs(string $js): string
    {
        $clean = substr($js, 0, self::MAX_JS_BYTES);

        // Strip any accidental <script> or </script> wrapper tags so srcdoc script block cannot be broken out of
        $clean = (string) preg_replace('/<\/?script\b[^>]*>/i', '', $clean);

        return trim($clean);
    }

    /**
     * Normalize and sanitize a custom_view object.
     *
     * @param  array<string, mixed>|null  $customView
     * @return array{enabled: bool, html: string, css: string, js: string, updated_at: string}|null
     */
    public function sanitizeCustomView(?array $customView): ?array
    {
        if (! is_array($customView)) {
            return null;
        }

        $html = $this->sanitizeHtml((string) ($customView['html'] ?? ''));
        $css = $this->sanitizeCss((string) ($customView['css'] ?? ''));
        $js = $this->sanitizeJs((string) ($customView['js'] ?? ''));
        $enabled = array_key_exists('enabled', $customView)
            ? (bool) $customView['enabled']
            : ($html !== '' || $js !== '');

        return [
            'enabled' => $enabled,
            'html' => $html,
            'css' => $css,
            'js' => $js,
            'updated_at' => is_string($customView['updated_at'] ?? null) && $customView['updated_at'] !== ''
                ? $customView['updated_at']
                : Carbon::now()->toIso8601String(),
        ];
    }
}
