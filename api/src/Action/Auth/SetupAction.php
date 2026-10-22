<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Auth;

use MyPortfolio\Bootstrap;
use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\PasswordHasher;
use MyPortfolio\Service\Auth\MfaPolicyService;
use MyPortfolio\Service\Auth\SessionAuthContext;
use MyPortfolio\Service\Auth\SessionCookieFactory;
use MyPortfolio\Service\Auth\WebAuthnConfig;
use MyPortfolio\Service\Auth\SessionManager;
use MyPortfolio\Service\Config\CfgLocalWriter;
use MyPortfolio\Service\Demo\DemoDataSeeder;
use MyPortfolio\Service\Mail\SmtpSettingsInput;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\BackgroundProcess;
use MyPortfolio\Infrastructure\Config\RuntimePaths;
use MyPortfolio\Repository\SmtpSettingsRepository;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * First-run setup. Funguje **jen pokud users je prázdná** (race-safe přes UNIQUE constraint).
 *
 * Průvodce posílá kromě admina i jazyk a měnu zobrazení, volitelně SMTP
 * (`smtp` = formulář jako v administraci) a `demo_data` — ukázková data do účtu admina; kurzy a ceny
 * se k nim stahují na pozadí (api/bin/backfill-market.php).
 */
final class SetupAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $hasher,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly SessionManager $sessions,
        private readonly Config $config,
        private readonly SessionCookieFactory $sessionCookies,
        private readonly SmtpSettingsRepository $smtpSettings,
        private readonly SecretEncryption $secrets,
        private readonly DemoDataSeeder $demo,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $admin = (array) ($body['admin'] ?? []);
        $requireTotp = !empty($body['require_totp']);
        if (array_key_exists('require_mfa', $body) && !is_bool($body['require_mfa'])) {
            return Json::error($response, 'validation_failed', 'require_mfa musí být boolean.', 400);
        }
        $usesLegacyRequest = !array_key_exists('require_mfa', $body);
        $requireMfa = $usesLegacyRequest ? $requireTotp : (bool) $body['require_mfa'];
        $methodsProvided = array_key_exists('allowed_mfa_methods', $body);
        // Když volající seznam neposlal, platí to, co je v configu — ne domněnka
        // wizardu. Odpověď pak nese reálnou politiku, kterou vzápětí potvrdí /me.
        $methods = $methodsProvided
            ? $body['allowed_mfa_methods']
            : ($usesLegacyRequest
                ? ['totp']
                : $this->config->get('auth.allowed_mfa_methods', ['passkey', 'totp']));
        try {
            // Striktně — vstup z wizardu musí chybu vidět, runtime politika je
            // naopak fail-soft, aby překlep v cfg neshodil celou aplikaci.
            $allowedMfaMethods = MfaPolicyService::validateMethods($methods);
        } catch (\InvalidArgumentException $e) {
            if ($methodsProvided || $usesLegacyRequest) {
                return Json::error($response, 'validation_failed', $e->getMessage(), 400);
            }
            // Překlep v cfg.php nesmí zablokovat první spuštění; stejný fail-soft
            // fallback jako v MfaPolicyService.
            $allowedMfaMethods = ['passkey', 'totp'];
        }

        $detectedUrl = $this->detectAppUrl($request);
        $willWriteDetectedUrl = $detectedUrl !== null && $this->shouldOverwriteAppUrl();
        if ($requireMfa && $allowedMfaMethods === ['passkey']) {
            $canonicalUrl = $willWriteDetectedUrl
                ? $detectedUrl
                : (string) $this->config->get('app.url', '');
            try {
                new WebAuthnConfig(new Config(['app' => ['url' => $canonicalUrl]]));
            } catch (\InvalidArgumentException $e) {
                return Json::error(
                    $response,
                    'webauthn_configuration_invalid',
                    $e->getMessage(),
                    400,
                );
            }
        }

        $errors = $this->validate($admin);
        $smtp = null;
        if (is_array($body['smtp'] ?? null)) {
            try {
                $smtp = SmtpSettingsInput::parse($body['smtp']);
                $smtpPassword = (string) ($body['smtp']['password'] ?? '');
                if ($smtp['auth_enabled'] && $smtpPassword === '') {
                    $errors['smtp.password'][] = 'Zadej heslo k SMTP serveru.';
                }
                $smtp['password_enc'] = $smtpPassword !== '' ? $this->secrets->encrypt($smtpPassword) : null;
            } catch (DomainError $e) {
                foreach ((array) ($e->extra['fields'] ?? []) as $field => $messages) {
                    $errors['smtp.' . $field] = $messages;
                }
            } catch (\Throwable $e) {
                $errors['smtp.password'][] = 'Heslo nejde zašifrovat — v cfg.php chybí app.secret_encryption_key.';
            }
        }
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }
        $locale = in_array($admin['locale'] ?? 'cs', ['cs', 'en'], true) ? (string) ($admin['locale'] ?? 'cs') : 'cs';
        $displayCurrency = in_array($admin['display_currency'] ?? 'CZK', ['CZK', 'EUR', 'USD'], true) ? (string) ($admin['display_currency'] ?? 'CZK') : 'CZK';

        $pdo = $this->db->pdo();

        try {
            $passwordHash = $this->hasher->hash((string) $admin['password']);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400, [
                'fields' => ['admin.password' => [$e->getMessage()]],
            ]);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        // Race-safe: jedna transakce s SELECT FOR UPDATE — dva souběžné setup requesty
        // se serializují, druhý vidí prvního usera a odmítne setup.
        $pdo->beginTransaction();
        try {
            $count = (int) self::queryScalar($pdo, 'SELECT COUNT(*) FROM users FOR UPDATE');
            if ($count > 0) {
                $pdo->rollBack();
                return Json::error($response, 'setup_already_done', 'Setup již proběhl.', 409);
            }
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, role, locale, display_currency, is_active)
                                   VALUES (?, ?, ?, "admin", ?, ?, 1)');
            $stmt->execute([
                trim((string) $admin['email']),
                $passwordHash,
                trim((string) $admin['name']),
                $locale,
                $displayCurrency,
            ]);
            $userId = (int) $pdo->lastInsertId();
            if ($smtp !== null) {
                $this->smtpSettings->save($smtp, $userId);
            }

            $this->logger->log('setup.completed', $userId, 'user', $userId, [
                'email' => $admin['email'],
                'require_totp' => $requireTotp,
                'require_mfa' => $requireMfa,
                'allowed_mfa_methods' => $allowedMfaMethods,
                'smtp' => $smtp !== null,
                'demo_data' => !empty($body['demo_data']),
            ], $ip, $request->getHeaderLine('User-Agent'));

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            return Json::error($response, 'setup_failed', $e->getMessage(), 500);
        }

        // Zapiš obecnou MFA politiku, legacy TOTP flag a případně detekované app.url.
        // `app.url` přepisujeme JEN pokud je v configu prázdné nebo některý ze známých
        // placeholderů (Docker `http://localhost:8080`, sample `https://dev.example.com`, `https://example.com`).
        // To umožní dokončit Docker setup z LAN IP a zároveň ušetří uživateli krok ruční konfigurace
        // (důležité pro odkazy na reset hesla v emailech).
        // Pokud uživatel app.url už nastavil přes MYPORTFOLIO_APP_URL env nebo cfg.php, neperepíšeme.
        // `auth.allowed_mfa_methods` zapisujeme jen když ho volající vážně poslal
        // (nebo jde o legacy tvar požadavku, kde je seznam odvozený z require_totp).
        // Wizard ho záměrně neposílá — ať zůstane platná hodnota z cfg.php a per-instance
        // override nevznikne omylem.
        $keysToWrite = [
            'auth.require_mfa' => $requireMfa,
            'auth.require_totp' => $requireTotp,
        ];
        if ($methodsProvided || $usesLegacyRequest) {
            $keysToWrite['auth.allowed_mfa_methods'] = $allowedMfaMethods;
        }
        if ($willWriteDetectedUrl) {
            $keysToWrite['app.url'] = $detectedUrl;
        }
        $cfgLocalWritten = false;
        try {
            // V single-volume Docker layoutu (MYPORTFOLIO_DATA_DIR=/data) zapisujeme
            // do volumu, ne do image — jinak by per-instance overrides nepřežily image update.
            CfgLocalWriter::setKeys(CfgLocalWriter::resolveTargetDir(Bootstrap::rootDir()), $keysToWrite);
            $cfgLocalWritten = true;
        } catch (\Throwable $e) {
            $this->logger->log('setup.cfg_local_write_failed', $userId, 'user', $userId, [
                'error' => $e->getMessage(),
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        // Auto-login: vytvoř session pro nově vzniklého admina
        $userAgent = $request->getHeaderLine('User-Agent');
        $session = $this->sessions->create(
            $userId,
            $ip,
            $userAgent,
            $requireMfa ? SessionAuthContext::setup('password') : SessionAuthContext::basic('password'),
        );

        $demo = !empty($body['demo_data']) ? $this->seedDemo($userId, $ip, $userAgent) : null;

        $response = $response->withHeader(
            'Set-Cookie',
            $this->sessionCookies->create($session['token'], $session['expires_at']),
        );
        return Json::ok($response, [
            'user' => [
                'id'    => $userId,
                'email' => $admin['email'],
                'name'  => $admin['name'],
                'role'  => 'admin',
                'totp_enabled' => false,
                'must_setup_totp' => $requireTotp,
                'mfa_enabled' => false,
                'mfa_methods' => [],
                'passkey_count' => 0,
                'must_setup_mfa' => $requireMfa,
            ],
            'csrf_token' => $session['csrf_token'],
            'next' => $requireMfa ? '/setup-mfa' : '/',
            'require_totp' => $requireTotp,
            'require_mfa' => $requireMfa,
            'allowed_mfa_methods' => $allowedMfaMethods,
            'cfg_local_written' => $cfgLocalWritten,
            'demo' => $demo,
        ], 201);
    }

    /**
     * Ukázková data do účtu admina + stažení kurzů a cen na pozadí. Chyba setup neshodí — admin už existuje.
     *
     * @return array{ok:bool,transactions?:int,backfill_started?:bool,message?:string}
     */
    private function seedDemo(int $userId, string $ip, string $userAgent): array
    {
        try {
            $r = $this->demo->seed($userId);
        } catch (\Throwable $e) {
            $this->logger->log('setup.demo_failed', $userId, 'user', $userId, ['error' => $e->getMessage()], $ip, $userAgent);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $started = BackgroundProcess::spawnPhp(Bootstrap::rootDir() . '/api/bin/backfill-market.php', ['--from=' . $r['from']],
            RuntimePaths::log('backfill-market.log'));
        $this->logger->log('setup.demo_seeded', $userId, 'user', $userId, $r + ['backfill_started' => $started], $ip, $userAgent);
        return ['ok' => true, 'transactions' => $r['transactions'], 'backfill_started' => $started];
    }

    /**
     * Detekuje veřejnou URL aplikace z hostiteleho requestu. Respektuje X-Forwarded-Proto/Host
     * (PSR-7 Uri už typicky tyto headery zohledňuje, ale Slim default ne — proto manual fallback).
     * Vrací null pokud Host header chybí (degeneruje na nedělání nic).
     */
    private function detectAppUrl(Request $request): ?string
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        if ($host === '') {
            return null;
        }

        $fwdProto = trim(strtolower($request->getHeaderLine('X-Forwarded-Proto')));
        $scheme = $fwdProto !== '' ? $fwdProto : $uri->getScheme();
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'http';
        }

        $port = $uri->getPort();
        $isStandard = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

        return $scheme . '://' . $host . ($port !== null && !$isStandard ? ':' . $port : '');
    }

    /**
     * True pokud aktuální app.url je prázdná nebo známý placeholder z vzorových configů.
     */
    private function shouldOverwriteAppUrl(): bool
    {
        $current = rtrim((string) $this->config->get('app.url', ''), '/');
        if ($current === '') {
            return true;
        }
        $placeholders = [
            'http://localhost:8080',  // cfg.docker.php default
            'https://dev.example.com',// cfg.sample.php default
            'https://example.com',    // generic sample
        ];
        return in_array($current, $placeholders, true);
    }

    /**
     * @param array<string,mixed> $admin
     * @return array<string,list<string>>
     */
    private function validate(array $admin): array
    {
        $errors = [];

        if (empty($admin['name']) || !is_string($admin['name'])) {
            $errors['admin.name'][] = 'Jméno je povinné';
        }
        if (empty($admin['email']) || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['admin.email'][] = 'Platný email je povinný';
        }
        if (empty($admin['password']) || !is_string($admin['password'])) {
            $errors['admin.password'][] = 'Heslo je povinné';
        }

        return $errors;
    }

    private static function queryScalar(\PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Setup dotaz se nepodařilo provést.');
        }
        return $statement->fetchColumn();
    }
}
