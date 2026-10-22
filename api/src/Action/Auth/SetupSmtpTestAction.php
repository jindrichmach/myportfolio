<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Auth;

use MyPortfolio\Http\Json;
use MyPortfolio\Middleware\FirstRunLockMiddleware;
use MyPortfolio\Service\Mail\Mailer;
use MyPortfolio\Service\Mail\SmtpSettingsInput;
use MyPortfolio\Service\Portfolio\DomainError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/auth/setup/smtp-test — zkušební e-mail z úvodního průvodce.
 * Jen dokud neexistuje žádný uživatel (pak 409, test je v administraci); rate limit per IP.
 */
final class SetupSmtpTestAction
{
    public function __construct(
        private readonly FirstRunLockMiddleware $firstRun,
        private readonly Mailer $mailer,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!$this->firstRun->needsSetup()) {
            return Json::error($response, 'setup_already_done', 'Setup již proběhl.', 409);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $data = SmtpSettingsInput::parse((array) ($body['smtp'] ?? []));
            $to = trim((string) ($body['to'] ?? ''));
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                throw DomainError::validation(['to' => ['Zadej platný e-mail příjemce.']]);
            }
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
        $locale = ($body['locale'] ?? 'cs') === 'en' ? 'en' : 'cs';
        try {
            $reply = $this->mailer->sendTest(SmtpSettingsInput::forTest($data, (string) ($body['smtp']['password'] ?? '')), $to, $locale);
            return Json::ok($response, ['ok' => true, 'message' => $reply]);
        } catch (\Throwable $e) {
            return Json::ok($response, ['ok' => false, 'message' => $e->getMessage()]);
        }
    }
}
