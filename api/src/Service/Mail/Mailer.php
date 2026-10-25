<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Mail;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Appearance\ColorSchemes;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Wrapper nad Symfony Mailer + Twig pro renderování šablon.
 *
 * Použití:
 *   $mailer->sendTemplate('password_reset', 'cs', ['user@example.com'], ['name' => 'Jan Novák', 'resetLink' => '...']);
 *
 * Šablony jsou v api/templates/email/<code>.<lang>.{html,txt}.twig.
 * Barvy a logo podle barevného schématu: `user_id` ve vars = schéma toho uživatele, jinak schéma instance.
 */
final class Mailer
{
    /** Logo v hlavičce e-mailu — při odeslání vložené jako příloha (cid:), v náhledu jako data URI. */
    private const LOGO_CID = 'logo';

    /** @var array<string,TransportInterface> DSN → transport */
    private array $transports = [];
    private ?Environment $twig = null;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly SmtpSettings $smtp,
        private readonly ColorSchemes $schemes,
    ) {}

    /**
     * @param string[]            $to
     * @param array<string,mixed> $vars
     * @return string Krátký SMTP server response z poslední odpovědi (např.
     *               „250 2.0.0 Ok: queued as ABCDEF").
     */
    public function sendTemplate(
        string $code,
        string $locale,
        array $to,
        array $vars,
        ?string $subjectOverride = null,
    ): string {
        $vars['color_scheme'] = $this->scheme($vars);
        $vars['logo_src'] = 'cid:' . self::LOGO_CID;
        return $this->deliver($this->render($code, $locale, $vars, $subjectOverride), $to, $this->smtp->effective(), $code, $locale, $vars['color_scheme']);
    }

    /**
     * Zkušební e-mail s explicitním nastavením SMTP (test z administrace, i neuloženého formuláře).
     *
     * @param array<string,mixed> $settings tvar SmtpSettings::effective()
     * @return string odpověď SMTP serveru
     */
    public function sendTest(array $settings, string $to, string $locale): string
    {
        $vars = ['host' => $settings['host'], 'port' => $settings['port'], 'encryption' => $settings['encryption'],
            'from_email' => $settings['from_email'], 'sent_at' => date('Y-m-d H:i:s'), 'logo_src' => 'cid:' . self::LOGO_CID,
            'color_scheme' => $this->schemes->instance()];
        return $this->deliver($this->render('smtp_test', $locale, $vars), [$to], $settings, 'smtp_test', $locale, $vars['color_scheme']);
    }

    /**
     * @param array{subject:string,html:string,text:string} $rendered
     * @param string[] $to
     * @param array<string,mixed> $settings
     */
    private function deliver(array $rendered, array $to, array $settings, string $code, string $locale, string $scheme): string
    {
        $email = (new Email())
            ->from(new Address((string) $settings['from_email'], (string) $settings['from_name']))
            ->subject($rendered['subject'])
            ->html($rendered['html'])
            ->text($rendered['text']);
        if (str_contains($rendered['html'], 'cid:' . self::LOGO_CID)) {
            $email->embedFromPath(self::logoPath($scheme), self::LOGO_CID, 'image/png');
        }

        foreach ($to as $addr) {
            $email->addTo($addr);
        }

        if ((string) $settings['reply_to_email'] !== '') {
            $email->replyTo(new Address((string) $settings['reply_to_email'], (string) $settings['reply_to_name']));
        }

        // Obálku bereme z původní zprávy — DKIM podpis níže vrací novou instanci.
        $snapshot = Envelope::create($email);
        $envelope = new Envelope($snapshot->getSender(), $snapshot->getRecipients());

        if ($this->config->get('smtp.dkim.enabled', false)) {
            $keyPath = (string) $this->config->get('smtp.dkim.private_key_path', '');
            if (is_file($keyPath)) {
                $email = (new DkimSigner(
                    'file://' . $keyPath,
                    (string) $this->config->get('smtp.dkim.domain'),
                    (string) $this->config->get('smtp.dkim.selector'),
                    [],
                    (string) $this->config->get('smtp.dkim.passphrase', ''),
                ))->sign($email);
            } else {
                $this->logger->warning('DKIM enabled, ale private key neexistuje: ' . $keyPath);
            }
        }

        // High-level Symfony Mailer::send() vrací void — pro debug transcript voláme transport.
        $dsn = SmtpSettings::dsn($settings);
        $sent = ($this->transports[$dsn] ??= Transport::fromDsn($dsn))->send($email, $envelope);
        $debug = $sent !== null ? $sent->getDebug() : '';
        $smtpResponse = $this->extractLastServerResponse($debug);

        $this->logger->info('mail.sent', [
            'template'      => $code,
            'locale'        => $locale,
            'to'            => $to,
            'smtp_response' => $smtpResponse,
        ]);
        // Plný transcript obsahuje i AUTH kredence (base64) → jen DEBUG úroveň.
        if ($debug !== '') {
            $this->logger->debug('mail.smtp_transcript', ['template' => $code, 'smtp_debug' => self::redactAuth($debug)]);
        }

        return $smtpResponse;
    }

    /**
     * Vyrenderuje šablonu bez odeslání (odeslání, náhled v aplikaci).
     *
     * @param array<string,mixed> $vars
     * @return array{subject:string,html:string,text:string}
     */
    public function render(string $code, string $locale, array $vars, ?string $subjectOverride = null): array
    {
        $twig = $this->twig();

        $vars['locale'] = $locale;
        $scheme = $this->scheme($vars);
        $vars['c'] = ColorSchemes::EMAIL[$scheme];
        $vars['logo_src'] ??= 'data:image/png;base64,' . base64_encode((string) file_get_contents(self::logoPath($scheme)));
        $htmlTemplate = "{$code}.{$locale}.html.twig";
        $textTemplate = "{$code}.{$locale}.txt.twig";
        if (!$twig->getLoader()->exists($htmlTemplate)) {
            $htmlTemplate = "{$code}.cs.html.twig";
            $textTemplate = "{$code}.cs.txt.twig";
        }
        $vars['subject'] ??= $subjectOverride ?? $this->defaultSubject($code, $locale);

        return [
            'subject' => (string) $vars['subject'],
            'html'    => $twig->render($htmlTemplate, $vars),
            'text'    => $twig->render($textTemplate, $vars),
        ];
    }

    /**
     * Vytáhne z SMTP transcriptu poslední řádek odpovědi serveru (`< 250 …`).
     */
    /**
     * SMTP přepis bez přihlašovacích údajů: příkaz AUTH a další řádky klienta až do odpovědi serveru 2xx / 5xx
     * (base64 jméno a heslo u AUTH LOGIN / PLAIN) se nahradí `[REDACTED]`.
     */
    public static function redactAuth(string $debug): string
    {
        $out = [];
        $inAuth = false;
        foreach (preg_split('/\r?\n/', $debug) ?: [] as $line) {
            $plain = trim((string) preg_replace('/^\[[^\]]+\]\s*/', '', $line));
            if (preg_match('/^>\s*AUTH\b/i', $plain) === 1) {
                $inAuth = true;
                $out[] = (string) preg_replace('/(>\s*AUTH\s+\S+).*/i', '$1 [REDACTED]', $line);
                continue;
            }
            if ($inAuth && str_starts_with($plain, '>')) {
                $out[] = (string) preg_replace('/>.*/', '> [REDACTED]', $line);
                continue;
            }
            if ($inAuth && preg_match('/^<\s*[25]\d\d/', $plain) === 1) {
                $inAuth = false;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    private function extractLastServerResponse(string $debug): string
    {
        if ($debug === '') return '';
        $last = '';
        foreach (preg_split('/\r?\n/', $debug) ?: [] as $line) {
            // Symfony Mailer 8.x prefixuje řádky timestampem `[YYYY-MM-DDTHH:MM:SS] < …`.
            $stripped = trim((string) preg_replace('/^\[[^\]]+\]\s*/', '', $line));
            if (str_starts_with($stripped, '< ') || str_starts_with($stripped, '<<<')) {
                $last = $stripped;
            }
        }
        $last = (string) preg_replace('/^(?:<<<\s*|<\s+)/', '', $last);
        return $last !== '' ? $last : '(no SMTP debug — possibly non-SMTP transport)';
    }

    /** @param array<string,mixed> $vars */
    private function scheme(array $vars): string
    {
        if (ColorSchemes::isValid($vars['color_scheme'] ?? null)) {
            return (string) $vars['color_scheme'];
        }
        return isset($vars['user_id']) ? $this->schemes->forUser((int) $vars['user_id']) : $this->schemes->instance();
    }

    private static function logoPath(string $scheme): string
    {
        return self::templateDir() . '/logo-' . $scheme . '.png';
    }

    private static function templateDir(): string
    {
        return dirname(__DIR__, 3) . '/templates/email';
    }

    private function twig(): Environment
    {
        return $this->twig ??= new Environment(
            new FilesystemLoader(self::templateDir()),
            ['autoescape' => 'html', 'cache' => false, 'strict_variables' => false],
        );
    }

    private function defaultSubject(string $code, string $locale): string
    {
        $subjects = [
            'cs' => [
                'password_reset' => 'Obnova hesla — MyPortfolio',
                'login_otp'      => 'Ověřovací kód pro přihlášení — MyPortfolio',
                'smtp_test'      => 'Zkušební e-mail — MyPortfolio',
            ],
            'en' => [
                'password_reset' => 'Password reset — MyPortfolio',
                'login_otp'      => 'Sign-in verification code — MyPortfolio',
                'smtp_test'      => 'Test e-mail — MyPortfolio',
            ],
        ];
        return $subjects[$locale][$code] ?? ($subjects['cs'][$code] ?? 'MyPortfolio');
    }
}
