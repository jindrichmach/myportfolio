<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Cron;

/**
 * Katalog plánovaných úloh — jeden zdroj pravdy pro:
 *   - api/bin/cron-*.php (jméno běhu)
 *   - cmd/cron-*.sh (wrappery)
 *   - UI Systém → Plánované úlohy (doporučená frekvence, max stáří)
 *
 * Pokud poslední úspěšný běh (`last_ok_at`) je starší než `max_age_hours`,
 * UI hlásí varování ("cron nejede" nebo "selhává"). Hodnoty mají bezpečnou
 * rezervu (víkend, výpadek hostingu, holiday).
 */
final class CronCatalog
{
    /**
     * @return array<int,array{
     *   script:string,
     *   recommended:string,
     *   linux_cron:string,
     *   max_age_hours:int,
     *   weekdays_only:bool,
     *   critical:bool,
     *   requires_config?:string
     * }>
     *
     * `requires_config` (volitelné) = cfg klíč adresáře, bez kterého úloha nemá
     * co dělat (scan vypnutý). UI ji pak skryje, dokud není nastaven (CronJobsAction).
     */
    public static function all(): array
    {
        return [
            [
                'script' => 'cron-cleanup',
                'recommended' => 'daily_0300',
                'linux_cron' => '0 3 * * *',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-backup',
                'recommended' => 'daily_0200',
                'linux_cron' => '0 2 * * *',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => true,
            ],
            [
                'script' => 'cron-fx',
                'recommended' => 'daily_1445',
                'linux_cron' => '45 14 * * 1-5',
                'max_age_hours' => 96,
                'weekdays_only' => true,
                'critical' => true,
            ],
            [
                'script' => 'cron-prices',
                'recommended' => 'hourly',
                'linux_cron' => '5 * * * *',
                'max_age_hours' => 3,
                'weekdays_only' => false,
                'critical' => true,
            ],
            [
                'script' => 'cron-snapshot',
                'recommended' => 'daily_0030',
                'linux_cron' => '30 0 * * *',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-bank-notices',
                'recommended' => 'every_15min',
                'linux_cron' => '*/15 * * * *',
                'max_age_hours' => 2,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-weekly-report',
                'recommended' => 'weekly_mon_0800',
                'linux_cron' => '0 8 * * 1',
                'max_age_hours' => 24 * 8,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-period-report',
                'recommended' => 'monthly_1st_0805',
                'linux_cron' => '5 8 1 * *',
                'max_age_hours' => 24 * 32,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-exchange-sync',
                'recommended' => 'hourly',
                'linux_cron' => '20 * * * *',
                'max_age_hours' => 6,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-cpi',
                'recommended' => 'daily_0640',
                'linux_cron' => '40 6 * * *',
                'max_age_hours' => 24 * 8,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-alerts',
                'recommended' => 'daily_0730',
                'linux_cron' => '30 7 * * *',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function scripts(): array
    {
        return array_map(static fn ($e) => (string) $e['script'], self::all());
    }
}
