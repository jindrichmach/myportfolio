<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Settings;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\BankNoticeRepository;
use MyPortfolio\Repository\ImapAccountRepository;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\BankNotice\ImapClientInterface;
use MyPortfolio\Service\BankNotice\ImapHostGuard;
use MyPortfolio\Service\BankNotice\NoticeScanner;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Bankovní e-mailová avíza:
 *   GET|POST /api/settings/imap-accounts, PUT|DELETE /api/settings/imap-accounts/{id}
 *   POST /api/settings/imap-accounts/{id}/test — připojení + seznam složek
 *   POST /api/settings/imap-accounts/{id}/scan — načíst avíza hned
 *   GET  /api/bank-notices                     — protokol zpracovaných avíz
 * Heslo se jen zapisuje (šifrovaně), nikdy nevrací.
 */
final class BankNoticesAction
{
    public function __construct(
        private readonly ImapAccountRepository $accounts,
        private readonly BankNoticeRepository $notices,
        private readonly SecretEncryption $secrets,
        private readonly ImapClientInterface $imap,
        private readonly NoticeScanner $scanner,
        private readonly ImapHostGuard $hostGuard,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->accounts->list(CurrentUser::id($request)));
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::id($request);
            $id = $this->accounts->create($userId, $this->input((array) ($request->getParsedBody() ?? []), true));
            return Json::ok($response, $this->accounts->find($userId, $id), 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::id($request);
            $id = $this->owned($userId, $args);
            $this->accounts->update($userId, $id, $this->input((array) ($request->getParsedBody() ?? []), false));
            return Json::ok($response, $this->accounts->find($userId, $id));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::id($request);
            $this->accounts->delete($userId, $this->owned($userId, $args));
            return $response->withStatus(204);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function test(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::id($request);
            $settings = $this->accounts->withSecret($userId, $this->owned($userId, $args)) ?? throw DomainError::notFound();
            $settings['password'] = $this->secrets->decrypt((string) $settings['password_enc']);
            return Json::ok($response, $this->imap->test($settings));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function scan(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::id($request);
            $id = $this->owned($userId, $args);
            if (!($this->accounts->find($userId, $id)['enabled'] ?? false)) {
                throw new DomainError('imap_disabled', 'Schránka je vypnutá.', 409);
            }
            return Json::ok($response, $this->scanner->scanUser($userId, $id)[0] ?? []);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function notices(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->notices->list(CurrentUser::id($request)));
    }

    /** @param array{id:string} $args */
    private function owned(int $userId, array $args): int
    {
        $id = (int) $args['id'];
        $this->accounts->find($userId, $id) ?? throw DomainError::notFound('Schránka nenalezena.');
        return $id;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function input(array $body, bool $creating): array
    {
        $errors = [];
        $data = [
            'name'                 => Input::string($body, 'name', 120, true, $errors),
            'enabled'              => Input::bool($body, 'enabled', true),
            'host'                 => Input::string($body, 'host', 190, true, $errors),
            'port'                 => (int) ($body['port'] ?? 993),
            'encryption'           => Input::enum($body, 'encryption', ['ssl', 'tls', 'none'], $errors, 'ssl'),
            'validate_cert'        => Input::bool($body, 'validate_cert', true),
            'username'             => Input::string($body, 'username', 190, true, $errors),
            'folder'               => Input::string($body, 'folder', 190, false, $errors) ?? 'INBOX',
            'max_messages_per_run' => (int) ($body['max_messages_per_run'] ?? 50),
            'require_email_auth'   => Input::bool($body, 'require_email_auth', true),
            'auth_serv_id'         => Input::string($body, 'auth_serv_id', 190, false, $errors),
        ];
        if ($data['host'] !== null && ($denied = $this->hostGuard->check($data['host'])) !== null) {
            $errors['host'][] = $denied;
        }
        if ($data['port'] < 1 || $data['port'] > 65535) {
            $errors['port'][] = 'Port musí být 1–65535.';
        }
        if ($data['max_messages_per_run'] < 1 || $data['max_messages_per_run'] > 500) {
            $errors['max_messages_per_run'][] = 'Počet zpráv musí být 1–500.';
        }
        $password = (string) ($body['password'] ?? '');
        if ($creating && $password === '') {
            $errors['password'][] = 'Povinné pole.';
        }
        Input::assertValid($errors);
        $data['password_enc'] = $password !== '' ? $this->secrets->encrypt($password) : null;
        return $data;
    }
}
