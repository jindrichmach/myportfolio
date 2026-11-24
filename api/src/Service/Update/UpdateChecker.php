<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Update;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Config\RuntimePaths;
use MyPortfolio\Service\Market\HttpFetcher;
use MyPortfolio\Service\Market\MarketDataException;

/**
 * Kontrola nové verze proti GitHub releases (veřejné API, bez tokenu — limit 60 dotazů za hodinu,
 * proto výsledek cachujeme ve `storage/update-check.json`). Bere i pre-release (vývojové verze `-dev`)
 * a řadí podle version_compare; drafty přeskočí. Vrací i všechna vydání novější než nainstalovaná verze,
 * aby admin viděl poznámky ke všem verzím, které aktualizací přeskočí.
 */
final class UpdateChecker
{
    private const URL = 'https://api.github.com/repos/%s/releases?per_page=30';
    private const TTL_SECONDS = 21600;

    private readonly string $cacheFile;

    public function __construct(
        private readonly HttpFetcher $http,
        private readonly VersionService $version,
        private readonly Config $config,
        ?string $cacheFile = null,
    ) {
        $this->cacheFile = $cacheFile ?? RuntimePaths::storage('update-check.json');
    }

    /**
     * @return array{current:string,latest:?array{version:string,name:string,published_at:?string,url:string,notes:string,prerelease:bool,bundle:bool},
     *               releases:list<array<string,mixed>>,update_available:bool,checked_at:?string,error:?string,repo:string}
     */
    public function check(bool $refresh = false): array
    {
        $repo = (string) $this->config->get('update.github_repo', '');
        $current = $this->version->getCurrentVersion();
        $cached = $this->readCache($repo);

        if (!$refresh && $cached !== null && strtotime($cached['checked_at']) > time() - self::TTL_SECONDS) {
            return $this->result($current, $cached['releases'], $cached['checked_at'], null, $repo);
        }
        if (preg_match('#^[\w.-]+/[\w.-]+$#', $repo) !== 1) {
            return $this->result($current, [], null, 'not_configured', $repo);
        }

        try {
            $body = $this->http->get(sprintf(self::URL, $repo), ['Accept' => 'application/vnd.github+json']);
            $releases = self::pickReleases($body);
        } catch (MarketDataException) {
            // repozitář soukromý / nedostupný / limit API — ukaž poslední známý výsledek, pokud je
            return $this->result($current, $cached['releases'] ?? [], $cached['checked_at'] ?? null, 'unavailable', $repo);
        }

        $checkedAt = date(\DateTimeInterface::ATOM);
        $this->writeCache($repo, $releases, $checkedAt);
        return $this->result($current, $releases, $checkedAt, null, $repo);
    }

    /**
     * Vydané verze z odpovědi `GET /repos/{repo}/releases`, nejnovější první.
     *
     * @return list<array{version:string,name:string,published_at:?string,url:string,notes:string,prerelease:bool,bundle:bool}>
     */
    public static function pickReleases(string $json): array
    {
        $releases = json_decode($json, true);
        if (!is_array($releases) || !array_is_list($releases)) {
            throw new MarketDataException('GitHub releases: neočekávaná odpověď');
        }
        $out = [];
        foreach ($releases as $r) {
            if (!is_array($r) || ($r['draft'] ?? false) || !is_string($r['tag_name'] ?? null)) {
                continue;
            }
            $version = ltrim($r['tag_name'], 'vV');
            if (preg_match('/^\d+\.\d+\.\d+/', $version) !== 1) {
                continue;
            }
            $out[] = [
                'version'      => $version,
                'name'         => is_string($r['name'] ?? null) && $r['name'] !== '' ? $r['name'] : $version,
                'published_at' => is_string($r['published_at'] ?? null) ? $r['published_at'] : null,
                'url'          => is_string($r['html_url'] ?? null) ? $r['html_url'] : '',
                'notes'        => is_string($r['body'] ?? null) ? $r['body'] : '',
                'prerelease'   => (bool) ($r['prerelease'] ?? false),
                'bundle'       => self::hasBundle($version, $r['assets'] ?? null),
            ];
        }
        usort($out, static fn (array $a, array $b) => version_compare($b['version'], $a['version']));
        return $out;
    }

    /** Vydání má asset `myportfolio-<verze>.tar.gz` i jeho `.sha256`. */
    private static function hasBundle(string $version, mixed $assets): bool
    {
        $names = [];
        foreach (is_array($assets) ? $assets : [] as $a) {
            if (is_array($a) && is_string($a['name'] ?? null)) {
                $names[$a['name']] = true;
            }
        }
        $bundle = 'myportfolio-' . $version . '.tar.gz';

        return isset($names[$bundle], $names[$bundle . '.sha256']);
    }

    /** @param list<array<string,mixed>> $releases nejnovější první */
    private function result(string $current, array $releases, ?string $checkedAt, ?string $error, string $repo): array
    {
        $latest = $releases[0] ?? null;
        return [
            'current'          => $current,
            'latest'           => $latest,
            'releases'         => $current === 'unknown' ? [] : array_values(array_filter($releases,
                static fn (array $r) => version_compare($r['version'], $current, '>'))),
            'update_available' => $latest !== null && $current !== 'unknown' && version_compare($latest['version'], $current, '>'),
            'checked_at'       => $checkedAt,
            'error'            => $error,
            'repo'             => $repo,
        ];
    }

    /** @return array{releases:list<array<string,mixed>>,checked_at:string}|null */
    private function readCache(string $repo): ?array
    {
        $raw = @file_get_contents($this->cacheFile);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        // cache ze starší verze (jen `latest`) se nepoužije — stáhne se znovu
        if (!is_array($data) || ($data['repo'] ?? null) !== $repo || !is_string($data['checked_at'] ?? null)
            || !is_array($data['releases'] ?? null) || !array_is_list($data['releases'])) {
            return null;
        }
        // cache bez pole `bundle` (před balíčkovou aktualizací) se přenačte
        foreach ($data['releases'] as $r) {
            if (!is_array($r) || !is_bool($r['bundle'] ?? null)) {
                return null;
            }
        }
        return ['releases' => $data['releases'], 'checked_at' => $data['checked_at']];
    }

    /** @param list<array<string,mixed>> $releases */
    private function writeCache(string $repo, array $releases, string $checkedAt): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->cacheFile, json_encode(
            ['repo' => $repo, 'checked_at' => $checkedAt, 'releases' => $releases],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }
}
