<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Catalog;

use MyPortfolio\Service\Market\HttpFetcher;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Service\Portfolio\DomainError;

/**
 * Logo instituce z jejího webu: z úvodní stránky vezme `apple-touch-icon` / `icon` (největší PNG/JPEG/WebP),
 * jinak zkusí `/apple-touch-icon.png` a `/favicon.ico` (z ICO jen vložený PNG — GD ICO ani SVG neumí). Jen `https` na veřejné adresy (ochrana proti SSRF na interní síť);
 * výsledek projde {@see IconStore} jako běžný upload.
 */
final class WebsiteIconFetcher
{
    private const FORMATS = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    public function __construct(
        private readonly HttpFetcher $http,
        private readonly PublicUrlGuard $guard,
    ) {}

    public function fetch(string $website): string
    {
        $base = $this->guard->assert($website);
        $candidates = [];
        try {
            $html = $this->http->get($base, ['Accept' => 'text/html']);
            $candidates = self::iconLinks(substr($html, 0, 512_000), $base);
        } catch (MarketDataException) {
            // web nedostupný → zkusí se aspoň výchozí cesty
        }
        $candidates[] = self::absolute('/apple-touch-icon.png', $base);
        $candidates[] = self::absolute('/favicon.png', $base);
        $candidates[] = self::absolute('/favicon.ico', $base);

        foreach (array_unique($candidates) as $url) {
            try {
                $this->guard->assert($url);
                $bytes = $this->http->get($url, ['Accept' => 'image/*']);
            } catch (MarketDataException|DomainError) {
                continue;
            }
            $bytes = self::pngFromIco($bytes) ?? $bytes;
            $info = strlen($bytes) <= IconStore::MAX_BYTES ? @getimagesizefromstring($bytes) : false;
            if ($info !== false && in_array($info[2], self::FORMATS, true)) {
                return $bytes;
            }
        }
        throw DomainError::validation(['website' => ['Na webu se nepodařilo najít logo (PNG, JPEG, WebP). Nahraj obrázek ručně.']]);
    }

    /**
     * Odkazy na ikony z HTML, od největší (apple-touch-icon a `sizes` mají přednost před favicon).
     *
     * @return list<string>
     */
    public static function iconLinks(string $html, string $base): array
    {
        $found = [];
        preg_match_all('/<link\b[^>]*>/i', $html, $tags);
        foreach ($tags[0] as $tag) {
            $rel = strtolower(self::attr($tag, 'rel') ?? '');
            $href = self::attr($tag, 'href');
            if ($href === null || !preg_match('/(^|\s)(apple-touch-icon(-precomposed)?|icon)(\s|$)/', $rel)) {
                continue;
            }
            if (preg_match('/\.svg(\?|$)/i', $href) || str_contains(strtolower(self::attr($tag, 'type') ?? ''), 'svg')) {
                continue; // GD neumí SVG
            }
            preg_match('/(\d+)x\d+/', self::attr($tag, 'sizes') ?? '', $m);
            // ICO až nakonec (jde použít jen s vloženým PNG)
            $ico = preg_match('/\.ico(\?|$)/i', $href) === 1 || str_contains(strtolower(self::attr($tag, 'type') ?? ''), 'icon');
            $size = (int) ($m[1] ?? 0) + (str_contains($rel, 'apple-touch-icon') ? 1000 : 0) - ($ico ? 10_000 : 0);
            $found[] = ['url' => self::absolute(html_entity_decode($href), $base), 'size' => $size];
        }
        usort($found, static fn (array $a, array $b) => $b['size'] <=> $a['size']);
        return array_values(array_unique(array_column($found, 'url')));
    }

    /** Největší PNG vložený v ICO souboru; null = není ICO nebo v něm PNG není (jen BMP). */
    public static function pngFromIco(string $bytes): ?string
    {
        if (strlen($bytes) < 6 || substr($bytes, 0, 4) !== "\x00\x00\x01\x00") {
            return null;
        }
        $count = unpack('v', $bytes, 4)[1];
        $best = null;
        $bestSize = -1;
        for ($i = 0; $i < $count && 6 + ($i + 1) * 16 <= strlen($bytes); $i++) {
            $e = unpack('Cw/Ch/x6/Vsize/Voffset', $bytes, 6 + $i * 16);
            $data = substr($bytes, $e['offset'], $e['size']);
            $px = $e['w'] === 0 ? 256 : $e['w'];
            if (str_starts_with($data, "\x89PNG") && $px > $bestSize) {
                $best = $data;
                $bestSize = $px;
            }
        }
        return $best;
    }

    private static function attr(string $tag, string $name): ?string
    {
        return preg_match('/\b' . $name . '\s*=\s*(["\'])(.*?)\1/i', $tag, $m) ? $m[2] : null;
    }

    private static function absolute(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $p = parse_url($base);
        $origin = 'https://' . $p['host'];
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $path = $p['path'] ?? '/';
        $dir = rtrim(str_ends_with($path, '/') ? $path : dirname($path), '/');
        return $origin . $dir . '/' . $href;
    }
}
