<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Cron;

/**
 * Generuje obsah `/etc/cron.d/myportfolio` pro vestavěný cron v Docker image.
 *
 * Zdrojem pravdy je {@see CronCatalog} — stejný seznam úloh + frekvencí, jaký
 * ukazuje UI „Systém → Plánované úlohy". Crontab se tak při Docker buildu generuje
 * z katalogu (tools/generateDockerCrontab.php), místo aby se ručně opisoval a časem
 * se rozešel (např. by chyběl cron-backup-documents).
 *
 * Každý řádek volá wrapper `/usr/local/bin/myportfolio-cron-run`, který načte runtime
 * ENV (cron je v Debianu nedědí) a spustí PHP skript jako www-data s logem do
 * `${MYPORTFOLIO_DATA_DIR}/log/cron`.
 */
final class DockerCrontabGenerator
{
    public const WRAPPER = '/usr/local/bin/myportfolio-cron-run';

    public static function generate(): string
    {
        $lines = [
            '# Vestavěný cron MyPortfolio — GENEROVÁNO z CronCatalog (tools/generateDockerCrontab.php).',
            '# Neupravovat ručně; změny frekvencí patří do api/src/Service/Cron/CronCatalog.php.',
            'SHELL=/bin/sh',
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'CRON_TZ=Europe/Prague',
            'MAILTO=""',
            '',
        ];
        foreach (CronCatalog::all() as $job) {
            $lines[] = sprintf(
                '%s www-data %s api/bin/%s.php',
                $job['linux_cron'],
                self::WRAPPER,
                $job['script'],
            );
        }
        // /etc/cron.d soubory MUSÍ končit novým řádkem, jinak je cron ignoruje.
        return implode("\n", $lines) . "\n";
    }
}
