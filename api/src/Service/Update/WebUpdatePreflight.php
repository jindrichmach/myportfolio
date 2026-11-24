<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Update;

use Closure;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Config\RuntimePaths;

/**
 * Předběžná kontrola, zda lze nativní instalaci aktualizovat z webu. Vrací kódy důvodů,
 * proč ne (prázdný seznam = lze); kontroly prostředí dělá {@see BundleUpdater::preflightReasons()}.
 * Neblokující upozornění vrací `warnings()`.
 */
final class WebUpdatePreflight
{
    private readonly BundleUpdater $updater;

    /**
     * @param (Closure(string $key):(string|false))|null $ini čtečka php.ini (výchozí `ini_get`)
     */
    public function __construct(
        private readonly Config $config,
        private readonly string $rootDir,
        private readonly UpdateChecker $checker,
        private readonly ?string $lockFile = null,
        ?string $stateDir = null,
        ?string $php = null,
        private readonly ?Closure $ini = null,
        ?Closure $diskFree = null,
    ) {
        $this->updater = new BundleUpdater(
            $rootDir,
            stateDir: $stateDir ?? RuntimePaths::base(),
            php: $php,
            diskFree: $diskFree,
        );
    }

    public function lockFile(): string
    {
        return $this->lockFile ?? RuntimePaths::storage('update.lock');
    }

    /**
     * @return list<string> `docker`, `disabled`, `no_zlib`, `no_http`, `no_php_cli`, `not_writable`,
     *                      `disk_space`, `no_bundle`, `running`
     */
    public function check(): array
    {
        if ($this->config->dataDir() !== null) {
            return ['docker'];
        }
        if ($this->config->get('update.web_update', false) !== true) {
            return ['disabled'];
        }

        $reasons = $this->updater->preflightReasons();
        $check = $this->checker->check();
        if ($check['update_available'] && ($check['latest']['bundle'] ?? false) !== true) {
            $reasons[] = 'no_bundle';
        }
        if ($this->isRunning()) {
            $reasons[] = 'running';
        }

        return $reasons;
    }

    /**
     * Upozornění, která aktualizaci neblokují.
     *
     * @return list<string> `git_checkout`, `opcache_no_validate`
     */
    public function warnings(): array
    {
        $warnings = [];
        if (file_exists(rtrim($this->rootDir, '/\\') . '/.git')) {
            $warnings[] = 'git_checkout';
        }
        $ini = $this->ini ?? static fn (string $key): string|false => ini_get($key);
        $on = static fn (string|false $v): bool => $v !== false && filter_var($v, FILTER_VALIDATE_BOOLEAN);
        if ($on($ini('opcache.enable')) && !$on($ini('opcache.validate_timestamps'))) {
            $warnings[] = 'opcache_no_validate';
        }

        return $warnings;
    }

    private function isRunning(): bool
    {
        $file = $this->lockFile();
        if (!is_file($file)) {
            return false;
        }
        $h = @fopen($file, 'c');
        if ($h === false) {
            return false;
        }
        $locked = !flock($h, LOCK_EX | LOCK_NB);
        if (!$locked) {
            flock($h, LOCK_UN);
        }
        fclose($h);

        return $locked;
    }
}
