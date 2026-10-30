<?php

declare(strict_types=1);

namespace MyPortfolio;

use DI\ContainerBuilder;
use MyPortfolio\Infrastructure\Cache\RedisFactory;
use MyPortfolio\Infrastructure\Cache\RedisProbe;
use MyPortfolio\Infrastructure\Clock\UtcClock;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\ApiScopeMiddleware;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Middleware\CsrfMiddleware;
use MyPortfolio\Middleware\FirstRunLockMiddleware;
use MyPortfolio\Middleware\IpAllowlistMiddleware;
use MyPortfolio\Middleware\RateLimitMiddleware;
use MyPortfolio\Middleware\RequireMfaMiddleware;
use MyPortfolio\Middleware\RoleMiddleware;
use MyPortfolio\Middleware\ShareContextMiddleware;
use MyPortfolio\Middleware\SessionLockMiddleware;
use MyPortfolio\Middleware\WebAuthnBodyLimitMiddleware;
use MyPortfolio\Service\IpMatcher;
use MyPortfolio\Service\Auth\PasskeyService;
use MyPortfolio\Service\Auth\DatabaseSecurityClock;
use MyPortfolio\Service\Auth\SecurityClock;
use MyPortfolio\Service\Auth\WebAuthnConfigProvider;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

final class Bootstrap
{
    public static function rootDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return App<ContainerInterface|null> */
    public static function buildApp(): App
    {
        $rootDir = self::rootDir();
        $config  = Config::load($rootDir);

        // Bezpečnostní guard: v produkci pepper musí být nastavený (jinak hesla nemají druhotnou ochranu)
        $env    = (string) $config->get('app.env', 'production');
        $pepper = (string) $config->get('app.pepper', '');
        if ($env === 'production' && $pepper === '') {
            throw new \RuntimeException('cfg.app.pepper není nastaven (vygeneruj: openssl rand -base64 32). V produkci je povinný.');
        }

        date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Prague'));

        // PHP error log → log/php-errors.log (jinak by warnings/notices padaly do
        // system php_errors.log, který je mimo repo). Display_errors v dev=on, prod=off.
        // Pokud je nastaven MYPORTFOLIO_DATA_DIR, ukládáme i tento log do data_dir
        // (drží všechen state pod jediným perzistentním volume).
        $logDir = ($config->dataDir() ?? $rootDir) . '/log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        ini_set('log_errors', '1');
        ini_set('error_log', $logDir . '/php-errors.log');
        // NIKDY display_errors=on pro API endpoints — JSON response by byla kontaminována
        // deprecation/notice warningy (typicky vendor 3rd-party kód). Logujeme do souboru.
        // Dev env: warnings se objeví v log/php-errors.log + log/app-YYYY-MM-DD.log.
        ini_set('display_errors', '0');
        // Reporting: E_ALL minus E_DEPRECATED (PHP 8.5 deprecates older patterns ve vendoru,
        // které nemůžeme fixnout — nechceme je v error log spamovat).
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $builder = new ContainerBuilder();
        $builder->useAttributes(false);
        $builder->addDefinitions([
            Config::class => $config,
            ClockInterface::class => fn () => new UtcClock(),
            SecurityClock::class => fn () => new DatabaseSecurityClock(),

            LoggerInterface::class => function (ContainerInterface $c) use ($config): LoggerInterface {
                $logger = new Logger('myportfolio');
                // Bez logging.path v cfg výchozí log/app.log (jinak by Monolog psal relativně k CWD procesu).
                $path   = (string) $config->get('logging.path', '') ?: \MyPortfolio\Infrastructure\Config\RuntimePaths::log('app.log');
                $level  = self::resolveLogLevel((string) $config->get('logging.level', 'info'));
                $maxFiles = (int) $config->get('logging.max_files', 90);
                if (!is_dir(dirname($path))) {
                    @mkdir(dirname($path), 0755, true);
                }
                $logger->pushHandler(new RotatingFileHandler($path, $maxFiles, $level));
                return $logger;
            },

            ResponseFactory::class => fn () => new ResponseFactory(),
            Connection::class      => fn (ContainerInterface $c) => new Connection($c->get(Config::class), $c->get(LoggerInterface::class)),
            RedisProbe::class      => fn (ContainerInterface $c) => new RedisProbe($c->get(Config::class)),
            RedisFactory::class    => fn (ContainerInterface $c) => new RedisFactory($c->get(Config::class)),
            PasskeyService::class  => fn (ContainerInterface $c) => new PasskeyService(
                $c->get(WebAuthnConfigProvider::class),
            ),
            // IpMatcher má v konstruktoru volitelný `?Config $config = null`. Autowiring
            // takový parametr neresolvuje (dosadí default null), takže clientIpFromRequest()
            // by ignorovalo cfg.ip_allowlist.trusted_proxies a vždy vracelo REMOTE_ADDR.
            // Za reverse proxy → audit log a brute-force lockout vidí IP proxy místo
            // reálného klienta. Explicitní injekce Configu to opravuje.
            IpMatcher::class       => fn (ContainerInterface $c) => new IpMatcher($c->get(Config::class)),

            // Kurzy a ceny — externí zdroje za rozhraním (v testech fake s fixtures).
            \MyPortfolio\Service\Market\HttpFetcher::class => fn (ContainerInterface $c) => $c->get(\MyPortfolio\Service\Market\GuzzleHttpFetcher::class),
            \MyPortfolio\Service\Exchange\ExchangeHttp::class => fn (ContainerInterface $c) => $c->get(\MyPortfolio\Service\Exchange\GuzzleExchangeHttp::class),
            // Logo z webu instituce — vlastní HTTP klient s kontrolou přesměrování (SSRF), ne klient cen.
            \MyPortfolio\Service\Catalog\WebsiteIconFetcher::class => fn (ContainerInterface $c) => new \MyPortfolio\Service\Catalog\WebsiteIconFetcher(
                $c->get(\MyPortfolio\Service\Catalog\GuzzleIconHttp::class),
                $c->get(\MyPortfolio\Service\Catalog\PublicUrlGuard::class),
            ),
            \MyPortfolio\Service\Price\XtbWidgetTransport::class => fn () => new \MyPortfolio\Service\Price\WebSocketXtbTransport(),
            \MyPortfolio\Service\BankNotice\ImapClientInterface::class => fn (ContainerInterface $c) => $c->get(\MyPortfolio\Service\BankNotice\WebklexImapClient::class),
            \MyPortfolio\Service\Price\PriceService::class => fn (ContainerInterface $c) => new \MyPortfolio\Service\Price\PriceService(
                [
                    $c->get(\MyPortfolio\Service\Price\CoinGeckoProvider::class),
                    $c->get(\MyPortfolio\Service\Price\XtbProvider::class),
                    $c->get(\MyPortfolio\Service\Price\YahooProvider::class),
                ],
                $c->get(\MyPortfolio\Repository\InstrumentRepository::class),
                $c->get(\MyPortfolio\Repository\PriceRepository::class),
                $c->get(\MyPortfolio\Repository\TransactionFxRepository::class),
                $c->get(ClockInterface::class),
                $c->get(\MyPortfolio\Repository\SnapshotMaintenanceRepository::class),
            ),
        ]);

        $container = $builder->build();
        AppFactory::setContainer($container);

        $app = AppFactory::create();

        Routes::register($app);

        // Slim 4 LIFO: poslední `add()` = NEJVĚTŠÍ vrstva = běží JAKO PRVNÍ.
        // Cílový order běhu (outside → inside):
        //   IpAllowlist → FirstRunLock → Auth → SessionLock → RequireMfa → Role → ApiScope → RateLimit → CSRF → WebAuthnBodyLimit → Routing → BodyParsing → Action
        // → add() v opačném pořadí (innermost první):
        $app->addBodyParsingMiddleware();                            // innermost
        $app->addRoutingMiddleware();
        $app->add($container->get(WebAuthnBodyLimitMiddleware::class)); // limit raw WebAuthn credential před JSON parsingem
        $app->add($container->get(CsrfMiddleware::class));           // potřebuje session z Auth (bearer skip)
        $app->add($container->get(RateLimitMiddleware::class));      // chrání forgot/setup/login + per-user/per-token limity
        $app->add($container->get(ShareContextMiddleware::class));    // X-Owner: kontext vlastníka sdílených dat
        $app->add($container->get(ApiScopeMiddleware::class));       // bearer-only: enforce read / read_write scope
        $app->add($container->get(RoleMiddleware::class));           // RBAC — kontrola role po Auth
        $app->add($container->get(RequireMfaMiddleware::class));     // assurance + povinný MFA setup (bearer skip)
        $app->add($container->get(SessionLockMiddleware::class));    // autoritativní idle/manual lock browser session
        $app->add($container->get(AuthMiddleware::class));           // načte session nebo bearer token
        $app->add($container->get(FirstRunLockMiddleware::class));   // 423 pokud users prázdná
        $app->add($container->get(IpAllowlistMiddleware::class));    // outermost user mw

        $displayErrors = (bool) $config->get('app.debug', false);
        $app->addErrorMiddleware($displayErrors, true, true, $container->get(LoggerInterface::class));

        return $app;
    }

    private static function resolveLogLevel(string $level): \Monolog\Level
    {
        return match (strtolower($level)) {
            'debug'   => \Monolog\Level::Debug,
            'info'    => \Monolog\Level::Info,
            'notice'  => \Monolog\Level::Notice,
            'warning' => \Monolog\Level::Warning,
            'error'   => \Monolog\Level::Error,
            default   => \Monolog\Level::Info,
        };
    }
}
