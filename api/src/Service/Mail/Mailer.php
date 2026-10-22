<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Mail;

use MyPortfolio\Infrastructure\Config\Config;
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
 */
final class Mailer
{
    /** @var array<string,TransportInterface> DSN → transport */
    private array $transports = [];
    private ?Environment $twig = null;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly SmtpSettings $smtp,
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
        return $this->deliver($this->render($code, $locale, $vars, $subjectOverride), $to, $this->smtp->effective(), $code, $locale);
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
            'from_email' => $settings['from_email'], 'sent_at' => date('Y-m-d H:i:s')];
        return $this->deliver($this->render('smtp_test', $locale, $vars), [$to], $settings, 'smtp_test', $locale);
    }

    /**
     * @param array{subject:string,html:string,text:string} $rendered
     * @param string[] $to
     * @param array<string,mixed> $settings
     */
    private function deliver(array $rendered, array $to, array $settings, string $code, string $locale): string
    {
        $email = (new Email())
            ->from(new Address((string) $settings['from_email'], (string) $settings['from_name']))
            ->subject($rendered['subject'])
            ->html($rendered['html'])
            ->text($rendered['text']);

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

    private function twig(): Environment
    {
        return $this->twig ??= new Environment(
            new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email'),
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
