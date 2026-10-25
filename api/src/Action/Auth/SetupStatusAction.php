<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Auth;

use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Middleware\FirstRunLockMiddleware;
use MyPortfolio\Service\Appearance\ColorSchemes;
use MyPortfolio\Service\Auth\MfaPolicyService;
use MyPortfolio\Service\Auth\PasskeyService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Always-available endpoint. Frontend ho zavolá při startu, aby věděl, jestli
 * spustit setup wizard, a získal public Turnstile site_key a barevné schéma instance (i pro přihlašovací stránku).
 */
final class SetupStatusAction
{
    public function __construct(
        private readonly FirstRunLockMiddleware $lockProbe,
        private readonly Config $config,
        private readonly PasskeyService $passkeys,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly ColorSchemes $colorSchemes,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        return Json::ok($response, [
            'needs_setup' => $this->lockProbe->needsSetup(),
            'version'     => '0.1.0',
            'timezone' => (string) $this->config->get('app.timezone', 'Europe/Prague'),
            'color_scheme' => $this->colorSchemes->instance(),
            'passwordless_login_enabled' =>
                (bool) $this->config->get('auth.passwordless_login.enabled', false)
                && $this->passkeys->isAvailable()
                && $this->mfaPolicy->isMethodAllowed('passkey'),
            'captcha'     => [
                'provider'   => $this->config->get('captcha.provider', 'none'),
                'site_key'   => $this->config->get('captcha.site_key', ''),
                'script_url' => $this->config->get('captcha.script_url', ''),
            ],
        ]);
    }
}
