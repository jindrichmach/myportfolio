<?php

declare(strict_types=1);

/**
 * Vypíše obsah /etc/cron.d/myportfolio z CronCatalog. Volá se při Docker buildu:
 *
 *   php tools/generateDockerCrontab.php > /etc/cron.d/myportfolio
 *
 * Tím je vestavěný cron v image vždy v souladu s katalogem úloh (a s UI
 * „Plánované úlohy"), bez ručního opisování frekvencí.
 */

require __DIR__ . '/../api/vendor/autoload.php';

echo \MyPortfolio\Service\Cron\DockerCrontabGenerator::generate();
