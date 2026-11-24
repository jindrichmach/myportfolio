<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Update;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use MyPortfolio\Service\PhpCliLocator;
use RuntimeException;
use Throwable;

/**
 * Aktualizace instalace z balíčku vydání — fáze swap a rollback.
 *
 * Swap nasazuje rozbalený `stage` přes instalaci PŘESUNEM (`rename`), ne kopií:
 * původní soubor odchází do zálohy, nový přichází ze stage, takže počet souborů
 * na svazku během swapu neroste (sdílený hosting s kvótou inodů). Obsahově
 * shodné soubory se nepřepisují.
 *
 * `run()` je celá pipeline (preflight, záloha DB, údržba, stažení, ověření SHA-256,
 * rozbalení, swap, migrace, zápis VERSION), spouští ji `api/bin/native-update.php`.
 * Selhání před migrací vrací soubory zpět; selhání migrace rollback NEspouští
 * (schéma už může být částečně změněné, kód pod starým schématem by škodil víc).
 * `VERSION` se píše až po migraci, takže přerušená aktualizace nevypadá jako dokončená.
 */
final class BundleUpdater
{
    /** Cesty, které swap nikdy nepřepíše ani nesmaže. */
    public const PROTECTED_PATHS = [
        'cfg.php', 'cfg.local.php', 'cfg.docker.php', '.env',
        'storage', 'log', 'private', '.git',
    ];

    /** Kroky pipeline v pořadí (hodnoty `step` / `error_step` ve stavu). */
    public const STEPS = ['preflight', 'backup', 'maintenance', 'download', 'verify', 'extract', 'swap', 'migrate', 'finish'];

    /** Odkud smí balíček přijít (i pro každý redirect). */
    public const ALLOWED_HOSTS = [
        'api.github.com', 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com',
    ];

    private const MAX_BUNDLE_BYTES = 600 * 1024 * 1024;
    private const MAX_META_BYTES = 1024 * 1024;
    private const MIN_FREE_BYTES = 512 * 1024 * 1024;
    private const MAX_REDIRECTS = 5;
    private const HTTP_TIMEOUT = 1800;
    private const API_TIMEOUT = 20;

    /** Adresář, ze kterého se mažou soubory, jež nové vydání už nemá. */
    private const PRUNED_DIR = 'web/dist';

    /** @var list<string> přepsané soubory (původní verze je v záloze) */
    private array $replaced = [];
    /** @var list<string> nově přidané soubory (v záloze nic není) */
    private array $added = [];
    /** @var list<string> soubory přesunuté do zálohy bez náhrady (prune) */
    private array $pruned = [];

    private string $target = '';
    private string $step = 'preflight';
    private bool $maintenance = false;

    /**
     * @param Closure(string):void|null $beforeMove hák volaný po záloze souboru (u prune i u nového souboru
     *                                              bez zálohy), těsně před nasazením nové verze (relativní
     *                                              cesta); výjimka simuluje selhání přesunu
     * @param string|null $stateDir adresář se `storage/` a `log/` (výchozí kořen instalace)
     * @param Closure(string $url, ?string $destFile, int $maxBytes):array{0:int,1:?string,2:string}|null $transport
     *        jeden HTTP GET bez následování redirectů: [status, Location, tělo]; s `$destFile` se tělo zapíše do něj
     *        a vrací se ''
     * @param Closure(string $command, string $cwd, string $logFile):int|null $runner spuštění podprocesu, vrací exit kód
     * @param Closure(string $dir):(float|int|false)|null $diskFree volné místo v bajtech (výchozí `disk_free_space`)
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly ?Closure $beforeMove = null,
        private readonly ?string $stateDir = null,
        private readonly ?UpdateState $state = null,
        private readonly string $repo = '',
        private readonly ?Closure $transport = null,
        private readonly ?Closure $runner = null,
        private readonly ?string $php = null,
        private readonly ?Closure $diskFree = null,
    ) {}

    /**
     * Nasadí `$stage` do instalace, původní soubory uloží do `$backupDir`.
     * Při chybě provede rollback a vyhodí RuntimeException.
     * `VERSION` se přeskakuje (píše ho až závěr pipeline).
     *
     * @return int počet nasazených souborů
     */
    public function swap(string $stage, string $backupDir): int
    {
        $this->replaced = [];
        $this->added = [];
        $this->pruned = [];

        // Cesty se sbírají předem — swap staged soubory přesouvá a iterátor by si podřezával větev.
        $entries = [];
        $stageFiles = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        /** @var \SplFileInfo $item */
        foreach ($it as $item) {
            $rel = $this->relativePath($stage, $item->getPathname());
            if ($rel === null) {
                continue;
            }
            $isDir = $item->isDir();
            $entries[] = [$item->getPathname(), $rel, $isDir];
            if (!$isDir) {
                $stageFiles[$rel] = true;
            }
        }

        $files = 0;
        try {
            foreach ($entries as [$path, $rel, $isDir]) {
                if ($rel === 'VERSION' || $this->isProtected($rel)) {
                    continue;
                }
                $dest = $this->rootDir . '/' . $rel;
                if ($isDir) {
                    $this->ensureDir($dest);
                    continue;
                }
                if (!is_file($path)) {
                    continue;
                }
                $exists = is_file($dest);
                if ($exists && $this->sameContent($path, $dest)) {
                    $this->syncExecBit($path, $dest);
                    @unlink($path);
                    continue;
                }
                if ($exists) {
                    $this->backupFile($rel, $dest, $backupDir);
                    $this->replaced[] = $rel;
                } else {
                    $this->added[] = $rel;
                }
                $this->afterBackup($rel);
                $this->moveIntoPlace($path, $dest);
                $files++;
            }
            $this->prune($stageFiles, $backupDir);
        } catch (Throwable $e) {
            $rb = $this->rollback($backupDir);
            $msg = 'Nasazení souborů selhalo (' . $e->getMessage() . '). Rollback vrátil ' . $rb['restored'] . ' souborů ze zálohy.';
            if ($rb['failed'] !== []) {
                $msg .= ' NEPODAŘILO SE vrátit (instalace je nekonzistentní): ' . implode(', ', array_slice($rb['failed'], 0, 10))
                    . (count($rb['failed']) > 10 ? ' … (+' . (count($rb['failed']) - 10) . ')' : '') . '.';
            }
            throw new RuntimeException($msg, 0, $e);
        }

        return $files;
    }

    /**
     * Vrátí přepsané i smazané soubory ze zálohy a odstraní nově přidané.
     *
     * @return array{restored:int, failed:list<string>} počet obnovených souborů a relativní cesty,
     *                                                  které se obnovit nepodařilo (instalace je pak nekonzistentní)
     */
    public function rollback(string $backupDir): array
    {
        foreach ($this->added as $rel) {
            @unlink($this->rootDir . '/' . $rel);
        }
        $restored = 0;
        $failed = [];
        foreach ([...$this->replaced, ...$this->pruned] as $rel) {
            $src = $backupDir . '/' . $rel;
            if (!is_file($src)) {
                $failed[] = $rel;
                continue;
            }
            try {
                $this->restoreFile($src, $this->rootDir . '/' . $rel);
                $restored++;
            } catch (Throwable) {
                $failed[] = $rel;
            }
        }
        $this->added = [];
        $this->replaced = [];
        $this->pruned = [];

        return ['restored' => $restored, 'failed' => $failed];
    }

    // ---------- pipeline ---------------------------------------------------

    /** Texty důvodů z {@see preflightReasons()} pro log a CLI. */
    private const REASON_MESSAGES = [
        'no_zlib' => 'Chybí rozšíření zlib — nelze rozbalit tar.gz balíček.',
        'no_http' => 'Není dostupné ani cURL, ani allow_url_fopen — balíček nelze stáhnout.',
        'no_php_cli' => 'Nenalezena PHP CLI binárka — nelze spustit zálohu a migrace.',
        'not_writable' => 'Některý z adresářů instalace (kořen, api, web, db, manual, storage/update) není zapisovatelný.',
        'disk_space' => 'Na disku je málo volného místa.',
    ];

    /**
     * Kódy důvodů, které brání aktualizaci (prázdný seznam = lze); používá je i WebUpdatePreflight.
     *
     * @param int|null $bundleSize velikost balíčku, je-li známa (volné místo = max(512 MB, 3× velikost))
     * @return list<string> `no_zlib`, `no_http`, `no_php_cli`, `not_writable`, `disk_space`
     */
    public function preflightReasons(?int $bundleSize = null): array
    {
        $reasons = [];
        if (!function_exists('gzopen')) {
            $reasons[] = 'no_zlib';
        }
        if (!function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $reasons[] = 'no_http';
        }
        if ($this->phpBinary() === null) {
            $reasons[] = 'no_php_cli';
        }
        $dirs = [$this->rootDir];
        foreach (['api', 'web', 'db', 'manual'] as $sub) {
            $dirs[] = $this->rootDir . '/' . $sub;
        }
        $dirs[] = $this->workDir();
        foreach ($dirs as $dir) {
            if (!self::writable($dir)) {
                $reasons[] = 'not_writable';
                break;
            }
        }
        if (!$this->hasFreeSpace($bundleSize)) {
            $reasons[] = 'disk_space';
        }

        return $reasons;
    }

    /**
     * Problémy, které brání aktualizaci, jako čitelné texty (prázdný seznam = lze).
     *
     * @return list<string>
     */
    public function preflight(): array
    {
        $problems = [];
        foreach ($this->preflightReasons() as $code) {
            $problems[] = self::REASON_MESSAGES[$code];
        }
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $this->repo) !== 1) {
            $problems[] = 'Neplatné nastavení update.github_repo („' . $this->repo . '").';
        }

        return $problems;
    }

    private static function requiredFreeBytes(?int $bundleSize): int
    {
        return max(self::MIN_FREE_BYTES, 3 * ($bundleSize ?? 0));
    }

    private function hasFreeSpace(?int $bundleSize): bool
    {
        $dir = is_dir($this->storageDir()) ? $this->storageDir() : $this->rootDir;
        $free = $this->diskFree !== null ? ($this->diskFree)($dir) : @disk_free_space($dir);

        return !(is_int($free) || is_float($free)) || $free >= self::requiredFreeBytes($bundleSize);
    }

    /** Existující cesta musí být zapisovatelná, neexistující se posuzuje podle nejbližšího existujícího rodiče. */
    private static function writable(string $path): bool
    {
        while (!file_exists($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                return false;
            }
            $path = $parent;
        }

        return is_writable($path);
    }

    /**
     * Provede celou aktualizaci na `$target`. Průběh se zapisuje do UpdateState, podrobnosti do logu.
     *
     * @return string `succeeded` | `failed` | `locked` (jiná aktualizace běží; stav se nemění)
     */
    public function run(string $target): string
    {
        if (preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/D', $target) !== 1) {
            throw new RuntimeException('Neplatná verze: ' . $target);
        }
        $storage = $this->storageDir();
        $this->ensureDir($storage);
        $lock = fopen($storage . '/update.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return 'locked';
        }

        $state = $this->state ?? new UpdateState();
        $record = [
            'status' => 'running',
            'from' => trim((string) @file_get_contents($this->rootDir . '/VERSION')),
            'target' => $target,
            'step' => null,
            'started_at' => date(\DateTimeInterface::ATOM),
            'finished_at' => null,
            'error_step' => null,
            'pid' => getmypid(),
        ];
        $this->target = $target;
        $this->maintenance = false;
        $failedStep = null;

        try {
            $this->log('=== Aktualizace ' . $record['from'] . ' -> ' . $target . ' ===');
            foreach (self::STEPS as $step) {
                $this->step = $step;
                $record['step'] = $step;
                $state->write($record);
                $this->heartbeat();
                $this->log('--- krok: ' . $step);
                $this->runStep($step, $target);
            }
        } catch (Throwable $e) {
            $failedStep = $this->step;
            $this->log('CHYBA (' . $failedStep . '): ' . $e->getMessage());
        } finally {
            if ($this->maintenance) {
                MaintenanceMode::end($this->stateDir());
            }
            $record['finished_at'] = date(\DateTimeInterface::ATOM);
            unset($record['pid']);
            if ($failedStep === null) {
                $record['status'] = 'succeeded';
                $record['step'] = null;
            } else {
                $record['status'] = 'failed';
                $record['error_step'] = $failedStep;
            }
            $state->write($record);
            $this->log('=== ' . $record['status'] . ' ===');
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $failedStep === null ? 'succeeded' : 'failed';
    }

    private function runStep(string $step, string $target): void
    {
        $work = $this->workDir();
        $bundle = $work . '/myportfolio-' . $target . '.tar.gz';
        $stageRoot = $work . '/stage';
        $stage = $stageRoot . '/myportfolio-' . $target;
        $backup = $work . '/backup';

        switch ($step) {
            case 'preflight':
                $problems = $this->preflight();
                if ($problems !== []) {
                    throw new RuntimeException('Preflight selhal: ' . implode(' ', $problems));
                }
                break;
            case 'backup':
                $this->exec(escapeshellarg((string) $this->phpBinary()) . ' ' . escapeshellarg($this->rootDir . '/api/bin/cron-backup.php'), 'Záloha databáze');
                break;
            case 'maintenance':
                $this->maintenance = true;
                $this->heartbeat();
                $this->log('Režim údržby zapnut.');
                break;
            case 'download':
                $this->removeTree($stageRoot);
                @unlink($bundle);
                $this->ensureDir($work);
                $this->downloadBundle($target, $bundle);
                break;
            case 'verify':
                $this->verifyChecksum($bundle);
                break;
            case 'extract':
                $this->ensureDir($stageRoot);
                $files = (new TarGzExtractor())->extract($bundle, $stageRoot, 'myportfolio-' . $target . '/');
                $this->log('Rozbaleno ' . count($files) . ' souborů');
                $this->assertStagedTreeSane($stage, $target);
                break;
            case 'swap':
                $this->preloadClasses();
                $this->removeTree($backup);
                $this->ensureDir($backup);
                $this->log('Nasazeno ' . $this->swap($stage, $backup) . ' souborů');
                break;
            case 'migrate':
                $script = $this->rootDir . '/api/bin/migrate.php';
                if (!is_file($script)) {
                    throw new RuntimeException('Chybí ' . $script . ' — nasazený balíček je neúplný.');
                }
                $this->exec(escapeshellarg((string) $this->phpBinary()) . ' ' . escapeshellarg($script), 'Migrace');
                break;
            case 'finish':
                $this->replaceFile($stage . '/VERSION', $this->rootDir . '/VERSION');
                $this->log('VERSION -> ' . $target);
                $this->removeTree($stageRoot);
                @unlink($bundle);
                break;
        }
    }

    /**
     * Třídy, které pipeline potřebuje po swapu (rollback, zápis stavu), se musí načíst
     * ještě ze starého kódu — autoloader by je po výměně souborů hledal v nekonzistentním stromu.
     */
    private function preloadClasses(): void
    {
        foreach ([
            UpdateState::class, MaintenanceMode::class, TarGzExtractor::class, self::class, PhpCliLocator::class,
            \MyPortfolio\Infrastructure\Config\RuntimePaths::class,
        ] as $class) {
            class_exists($class);
        }
        ($this->state ?? new UpdateState())->path();
    }

    private function downloadBundle(string $target, string $dest): void
    {
        $name = 'myportfolio-' . $target . '.tar.gz';
        $api = 'https://api.github.com/repos/' . $this->repo . '/releases/tags/v' . $target;
        $release = json_decode($this->fetch($api, null, self::MAX_META_BYTES), true);
        if (!is_array($release)) {
            throw new RuntimeException('GitHub API vrátilo ne-JSON odpověď.');
        }
        $bundleUrl = $shaUrl = null;
        $size = null;
        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $a) {
            $assetName = (string) ($a['name'] ?? '');
            $url = (string) ($a['browser_download_url'] ?? '');
            if ($assetName === $name) {
                $bundleUrl = $url;
                $size = isset($a['size']) ? (int) $a['size'] : null;
            } elseif ($assetName === $name . '.sha256') {
                $shaUrl = $url;
            }
        }
        if ($bundleUrl === null) {
            throw new RuntimeException('Vydání v' . $target . ' neobsahuje asset ' . $name . '.');
        }
        if ($shaUrl === null) {
            throw new RuntimeException('Vydání v' . $target . ' neobsahuje asset ' . $name . '.sha256.');
        }
        if ($size !== null && $size > self::MAX_BUNDLE_BYTES) {
            throw new RuntimeException('Balíček je větší než povolený limit.');
        }
        if (!$this->hasFreeSpace($size)) {
            throw new RuntimeException('Na disku je málo volného místa (potřeba aspoň '
                . intdiv(self::requiredFreeBytes($size), 1048576) . ' MB).');
        }

        $this->log('Stahuji ' . $bundleUrl);
        $this->fetch($bundleUrl, $dest, self::MAX_BUNDLE_BYTES);
        $actual = (int) @filesize($dest);
        if ($actual > self::MAX_BUNDLE_BYTES) {
            @unlink($dest);
            throw new RuntimeException('Stažený balíček překračuje limit velikosti.');
        }
        if ($size !== null && $actual !== $size) {
            @unlink($dest);
            throw new RuntimeException('Velikost balíčku nesouhlasí (čekáno ' . $size . ' B, staženo ' . $actual . ' B).');
        }
        $sha = $this->fetch($shaUrl, null, self::MAX_META_BYTES);
        if (preg_match('/\b([0-9a-f]{64})\b/i', $sha, $m) !== 1) {
            throw new RuntimeException('Asset ' . $name . '.sha256 neobsahuje čitelný SHA-256.');
        }
        file_put_contents($dest . '.sha256', strtolower($m[1]));
    }

    private function verifyChecksum(string $bundle): void
    {
        $expected = trim((string) @file_get_contents($bundle . '.sha256'));
        $actual = hash_file('sha256', $bundle);
        @unlink($bundle . '.sha256');
        if (!is_string($actual) || $expected === '') {
            throw new RuntimeException('SHA-256 balíčku nelze ověřit.');
        }
        $this->log('SHA-256 ' . $actual);
        if (!hash_equals($expected, strtolower($actual))) {
            @unlink($bundle);
            throw new RuntimeException('Kontrolní součet nesouhlasí; balíček byl smazán.');
        }
    }

    private function assertStagedTreeSane(string $stage, string $target): void
    {
        if (!is_file($stage . '/VERSION')) {
            throw new RuntimeException('Balíček neobsahuje soubor VERSION.');
        }
        $staged = trim((string) @file_get_contents($stage . '/VERSION'));
        if ($staged !== $target) {
            throw new RuntimeException('Balíček hlásí verzi „' . $staged . '", čekána „' . $target . '".');
        }
        foreach (['api/vendor/autoload.php', 'api/public/index.php', 'api/bin/migrate.php', 'api/src', 'web/dist/index.html', 'db/migrations'] as $required) {
            if (!file_exists($stage . '/' . $required)) {
                throw new RuntimeException('Balíček neobsahuje ' . $required . ' — není kompletní.');
            }
        }
    }

    /**
     * GET s ručním sledováním redirectů; schéma i host se kontrolují u každého kroku.
     * Se `$dest` se tělo zapíše do souboru.
     */
    private function fetch(string $url, ?string $dest, int $maxBytes): string
    {
        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            $this->assertAllowedUrl($url);
            if ($dest !== null) {
                @unlink($dest);
            }
            [$status, $location, $body] = ($this->transport ?? $this->httpTransport(...))($url, $dest, $maxBytes);
            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== null && $location !== '') {
                $url = $this->resolveLocation($url, $location);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('HTTP ' . $status . ' pro ' . $url . '.');
            }

            return $body;
        }

        throw new RuntimeException('Příliš mnoho přesměrování.');
    }

    private function resolveLocation(string $base, ?string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', (string) $location) === 1) {
            return (string) $location;
        }
        $p = parse_url($base);

        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . '/' . ltrim((string) $location, '/');
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('Povolené je jen HTTPS: ' . $url);
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new RuntimeException('Nepovolený host pro stahování: ' . $host);
        }
    }

    /** @return array{0:int,1:?string,2:string} */
    private function httpTransport(string $url, ?string $dest, int $maxBytes): array
    {
        $fh = $dest !== null ? @fopen($dest, 'wb') : null;
        if ($dest !== null && $fh === false) {
            throw new RuntimeException('Nelze zapisovat do ' . $dest . '.');
        }
        try {
            return function_exists('curl_init')
                ? $this->curlGet($url, $fh ?: null, $maxBytes)
                : $this->streamGet($url, $fh ?: null, $maxBytes);
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }
    }

    /** JSON jen pro API; balíček a `.sha256` jsou binární assety. */
    private static function acceptFor(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST)) === 'api.github.com'
            ? 'application/vnd.github+json'
            : 'application/octet-stream';
    }

    /**
     * @param resource|null $fh
     * @return array{0:int,1:?string,2:string}
     */
    private function curlGet(string $url, $fh, int $maxBytes): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init selhal.');
        }
        $location = null;
        $lastBeat = time();
        $opts = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => $fh !== null ? self::HTTP_TIMEOUT : self::API_TIMEOUT,
            CURLOPT_USERAGENT => 'MyPortfolio/bundle-update',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: ' . self::acceptFor($url)],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_HEADERFUNCTION => static function ($_c, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            },
            CURLOPT_PROGRESSFUNCTION => function ($_c, $_total, $now) use ($maxBytes, &$lastBeat): int {
                if ($now > $maxBytes) {
                    return 1;
                }
                if (time() - $lastBeat >= 5) {
                    $lastBeat = time();
                    $this->heartbeat();
                }

                return 0;
            },
        ];
        $opts += defined('CURLOPT_PROTOCOLS_STR')
            ? [CURLOPT_PROTOCOLS_STR => 'https']
            : [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
        $opts += $fh !== null ? [CURLOPT_FILE => $fh] : [CURLOPT_RETURNTRANSFER => true];
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        unset($ch);
        if ($body === false) {
            throw new RuntimeException('Stažení ' . $url . ' selhalo: ' . ($err !== '' ? $err : 'přerušeno'));
        }

        return [$status, $location, is_string($body) ? $body : ''];
    }

    /**
     * @param resource|null $fh
     * @return array{0:int,1:?string,2:string}
     */
    private function streamGet(string $url, $fh, int $maxBytes): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "User-Agent: MyPortfolio/bundle-update\r\nAccept: " . self::acceptFor($url) . "\r\n",
            'timeout' => $fh !== null ? self::HTTP_TIMEOUT : self::API_TIMEOUT,
            'follow_location' => 0,
            'ignore_errors' => true,
        ]]);
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in === false) {
            throw new RuntimeException('Nelze otevřít ' . $url . '.');
        }
        $status = 0;
        $location = null;
        $meta = stream_get_meta_data($in);
        $wrapper = $meta['wrapper_data'] ?? [];
        foreach (is_array($wrapper) ? $wrapper : [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', (string) $line, $m) === 1) {
                $status = (int) $m[1];
                $location = null;
            } elseif (stripos((string) $line, 'location:') === 0) {
                $location = trim(substr((string) $line, 9));
            }
        }
        $body = '';
        $total = 0;
        $lastBeat = time();
        while (!feof($in)) {
            $chunk = fread($in, 1048576);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $total += strlen($chunk);
            if ($total > $maxBytes) {
                fclose($in);
                throw new RuntimeException('Stahovaný soubor překračuje limit velikosti.');
            }
            if ($fh !== null) {
                fwrite($fh, $chunk);
            } else {
                $body .= $chunk;
            }
            if (time() - $lastBeat >= 5) {
                $lastBeat = time();
                $this->heartbeat();
            }
        }
        fclose($in);

        return [$status, $location, $body];
    }

    private function exec(string $command, string $label): void
    {
        $this->log('$ ' . $command);
        $code = ($this->runner ?? $this->procRunner(...))($command, $this->rootDir, $this->logFile());
        if ($code !== 0) {
            throw new RuntimeException($label . ' skončila s kódem ' . $code . '.');
        }
    }

    private function procRunner(string $command, string $cwd, string $logFile): int
    {
        $log = @fopen($logFile, 'a');
        if ($log === false) {
            throw new RuntimeException('Nelze zapisovat do logu ' . $logFile . '.');
        }
        $proc = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, $cwd);
        $code = is_resource($proc) ? proc_close($proc) : 127;
        fclose($log);

        return $code;
    }

    /** Prodlouží značku údržby (má expiraci) a ponechá aktuální krok. */
    private function heartbeat(): void
    {
        if ($this->maintenance) {
            MaintenanceMode::begin($this->stateDir(), 'MyPortfolio', $this->target, $this->step);
        }
    }

    private function phpBinary(): ?string
    {
        return $this->php ?? PhpCliLocator::resolve();
    }

    private function stateDir(): string
    {
        return rtrim($this->stateDir ?? $this->rootDir, '/\\');
    }

    private function storageDir(): string
    {
        return $this->stateDir() . '/storage';
    }

    private function workDir(): string
    {
        return $this->storageDir() . '/update';
    }

    private function logFile(): string
    {
        return $this->stateDir() . '/log/update.log';
    }

    private function log(string $line): void
    {
        $file = $this->logFile();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $item */
        foreach ($it as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** Leží relativní cesta v chráněné množině? */
    public function isProtected(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        foreach (self::PROTECTED_PATHS as $p) {
            if ($rel === $p || str_starts_with($rel, $p . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Soubory z `web/dist`, které ve stage nejsou, přesune do zálohy.
     * Bez jakéhokoli `web/dist` souboru ve stage se neprunuje (neúplný balíček).
     *
     * @param array<string, true> $stageFiles
     */
    private function prune(array $stageFiles, string $backupDir): void
    {
        $prefix = self::PRUNED_DIR . '/';
        $hasDist = false;
        foreach ($stageFiles as $rel => $_) {
            if (str_starts_with($rel, $prefix)) {
                $hasDist = true;
                break;
            }
        }
        $dir = $this->rootDir . '/' . self::PRUNED_DIR;
        if (!$hasDist || !is_dir($dir)) {
            return;
        }
        $old = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $item */
        foreach ($it as $item) {
            $rel = $this->relativePath($this->rootDir, $item->getPathname());
            if ($rel !== null && !$item->isDir() && !isset($stageFiles[$rel])) {
                $old[] = $rel;
            }
        }
        foreach ($old as $rel) {
            $this->backupFile($rel, $this->rootDir . '/' . $rel, $backupDir);
            $this->pruned[] = $rel;
            $this->afterBackup($rel);
        }
    }

    private function afterBackup(string $rel): void
    {
        if ($this->beforeMove !== null) {
            ($this->beforeMove)($rel);
        }
    }

    /** Obsahově shodný soubor, kterému v novém vydání přibyl/ubyl exec bit, se jen přepne `chmod`em. */
    private function syncExecBit(string $src, string $dest): void
    {
        $want = (int) @fileperms($src) & 0111;
        $have = (int) @fileperms($dest) & 0111;
        if ($want !== $have) {
            @chmod($dest, ((int) @fileperms($dest) & 0666) | $want);
        }
    }

    private function sameContent(string $a, string $b): bool
    {
        $sizeA = @filesize($a);
        $sizeB = @filesize($b);
        if ($sizeA === false || $sizeB === false || $sizeA !== $sizeB) {
            return false;
        }
        $algo = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
        $hashA = @hash_file($algo, $a);
        $hashB = @hash_file($algo, $b);

        return $hashA !== false && $hashA === $hashB;
    }

    /** Odloží původní soubor do zálohy přesunem; kopie jen když přesun nejde (jiný svazek). */
    private function backupFile(string $rel, string $dest, string $backupDir): void
    {
        $target = $backupDir . '/' . $rel;
        $this->ensureDir(dirname($target));
        if (@rename($dest, $target)) {
            return;
        }
        if (!@copy($dest, $target)) {
            throw new RuntimeException('Nelze zazálohovat ' . $rel . ' (došlo místo nebo inody?).');
        }
        if (!@unlink($dest)) {
            throw new RuntimeException('Nelze odstranit ' . $rel . ' po zazálohování.');
        }
    }

    private function moveIntoPlace(string $src, string $dest): void
    {
        $this->ensureDir(dirname($dest));
        if (!file_exists($dest) && @rename($src, $dest)) {
            return;
        }
        $this->replaceFile($src, $dest);
    }

    /** Zápis vedle cíle a přejmenování (záloha přes kopii nebo přesun mezi svazky). */
    private function replaceFile(string $src, string $dest): void
    {
        $tmp = $dest . '.myportfolio-upd';
        if (!@copy($src, $tmp)) {
            throw new RuntimeException('Nelze zapsat ' . $tmp . '.');
        }
        @chmod($tmp, (int) @fileperms($src) & 0777);
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new RuntimeException('Nelze přepsat ' . $dest . '.');
        }
        @unlink($src);
    }

    /** Vrací soubor ze zálohy přesunem — rollback nesmí alokovat nové inody. */
    private function restoreFile(string $src, string $dest): void
    {
        $this->ensureDir(dirname($dest));
        if (@rename($src, $dest)) {
            return;
        }
        $this->replaceFile($src, $dest);
    }

    /** @return string|null relativní cesta s `/`, nebo null když leží mimo base */
    private function relativePath(string $base, string $path): ?string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $base)) {
            return null;
        }
        $rel = substr($path, strlen($base));

        return $rel === '' ? null : $rel;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nelze vytvořit adresář ' . $dir . '.');
        }
    }
}
