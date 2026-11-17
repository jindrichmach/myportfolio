<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Update;

use Closure;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Config\RuntimePaths;

/**
 * Předběžná kontrola, zda lze nativní instalaci aktualizovat z webu. Vrací kódy důvodů,
 * proč ne (prázdný seznam = lze). Příkazy spouští přes injektovatelný runner.
 */
final class WebUpdatePreflight
{
    /** @var Closure(string, string): array{0:int,1:string} */
    private readonly Closure $runner;

    /**
     * @param (callable(string $command, string $cwd): array{0:int,1:string})|null $runner vrací [exit kód, výstup]
     */
    public function __construct(
        private readonly Config $config,
        private readonly string $rootDir,
        private readonly CommandLocator $locator = new CommandLocator(),
        ?callable $runner = null,
        private readonly ?string $lockFile = null,
    ) {
        $this->runner = Closure::fromCallable($runner ?? self::exec(...));
    }

    public function lockFile(): string
    {
        return $this->lockFile ?? RuntimePaths::storage('update.lock');
    }

    /**
     * @return list<string>
     */
    public function check(): array
    {
        if ($this->config->dataDir() !== null) {
            return ['docker'];
        }
        if ($this->config->get('update.web_update', false) !== true) {
            return ['disabled'];
        }

        $reasons = [];
        $root = rtrim($this->rootDir, '/\\');
        $isGit = file_exists($root . '/.git');
        if (!$isGit) {
            $reasons[] = 'not_git';
        }

        $git = $this->locator->resolve('git', (string) $this->config->get('update.git_bin', ''));
        if ($git === null) {
            $reasons[] = 'no_git';
        } elseif ($isGit) {
            [$code, $out] = ($this->runner)(escapeshellarg($git) . ' status --porcelain', $root);
            if ($code !== 0 || trim($out) !== '') {
                $reasons[] = 'dirty_tree';
            }
        }

        foreach (['', '/.git', '/api/vendor', '/web/node_modules', '/web/dist'] as $sub) {
            if (!self::writable($root . $sub)) {
                $reasons[] = 'not_writable';
                break;
            }
        }

        if ($this->locator->resolve('composer', (string) $this->config->get('update.composer_bin', '')) === null) {
            $reasons[] = 'no_composer';
        }
        if ($this->locator->resolve('pnpm', (string) $this->config->get('update.pnpm_bin', '')) === null) {
            $reasons[] = 'no_pnpm';
        }
        if ($this->isRunning()) {
            $reasons[] = 'running';
        }

        return $reasons;
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

    /** @return array{0:int,1:string} */
    private static function exec(string $command, string $cwd): array
    {
        $out = [];
        $code = 1;
        $prev = getcwd();
        if (@chdir($cwd)) {
            exec($command . ' 2>&1', $out, $code);
            if ($prev !== false) {
                chdir($prev);
            }
        }
        return [$code, implode("\n", $out)];
    }
}
