<?php

declare(strict_types=1);

namespace MyPortfolio\Service;

use MyPortfolio\Bootstrap;

/**
 * Fire-and-forget spuštění PHP CLI skriptu na pozadí (import worker, cron skripty).
 *
 * Jednotný a ověřený mechanismus (shodný s funkčním admin/cron-jobs spawnem):
 *   - Windows: `popen("start /B /D <cwd> \"\" <php> <script> <args> >> <log> 2>&1")`.
 *     popen sám obaluje příkaz do `cmd.exe /c`, takže `start /B` proces odpojí
 *     a vrátí se hned. POZOR: NEpřidávat vlastní `cmd /c` prefix (dvojitý cmd +
 *     ruční uvozovky proces nespustí — to byl bug import workeru).
 *   - POSIX: `nohup … &`.
 *
 * php se hledá přes PhpCliLocator (pod IIS/FastCGI je PHP_BINARY = php-cgi.exe
 * a holé `php` není na PATH).
 */
final class BackgroundProcess
{
    /**
     * @param list<string|int> $args argumenty skriptu (např. ['--job-id=3'])
     * @param string|null      $diag krátký diagnostický popis (out-param, pro log)
     */
    public static function spawnPhp(
        string $scriptPath,
        array $args = [],
        ?string $logPath = null,
        ?string $cwd = null,
        ?string &$diag = null,
    ): bool {
        $diag = null;
        $php = PhpCliLocator::resolve();
        if ($php === null) {
            $diag = 'php cli not found (PHP_BINARY=' . PHP_BINARY . ')';
            return false;
        }
        $cwd = $cwd ?? Bootstrap::rootDir();
        $logPath = $logPath ?? ($cwd . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'background.log');

        $argStr = '';
        foreach ($args as $a) {
            $argStr .= ' ' . escapeshellarg((string) $a);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf(
                'start /B /D %s "" %s %s%s >> %s 2>&1',
                escapeshellarg($cwd),
                escapeshellarg($php),
                escapeshellarg($scriptPath),
                $argStr,
                escapeshellarg($logPath),
            );
            $proc = @popen($cmd, 'r');
            if ($proc === false) {
                $diag = 'popen returned false';
                return false;
            }
            @pclose($proc);
            $diag = 'popen ok';
            return true;
        }

        $cmd = sprintf(
            'cd %s && nohup %s %s%s >> %s 2>&1 &',
            escapeshellarg($cwd),
            escapeshellarg($php),
            escapeshellarg($scriptPath),
            $argStr,
            escapeshellarg($logPath),
        );
        @exec($cmd, $_out, $rc);
        $diag = 'exec rc=' . $rc;
        return $rc === 0;
    }
}
