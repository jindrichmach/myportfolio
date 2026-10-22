<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Settings;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Repository\AccountRepository;
use MyPortfolio\Repository\ExchangeConnectionRepository;
use MyPortfolio\Repository\PortfolioRepository;
use MyPortfolio\Repository\TransactionRepository;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\Exchange\ExchangeClient;
use MyPortfolio\Service\Exchange\ExchangeClients;
use MyPortfolio\Service\Exchange\ExchangeException;
use MyPortfolio\Service\Exchange\ExchangeSyncService;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Napojení na API burz — jen session, ne API token (tajné klíče):
 *   GET    /api/settings/exchanges              — dostupné burzy a napojení
 *   POST   /api/settings/exchanges/test         — ověřit klíč před uložením
 *   POST   /api/settings/exchanges              — napojit (klíč se ověří; start: continue | date | all)
 *   PUT    /api/settings/exchanges/{id}         — účet, portfolio, zapnutí, volitelně nový klíč
 *   DELETE /api/settings/exchanges/{id}
 *   POST   /api/settings/exchanges/{id}/sync    — synchronizovat teď
 */
final class ExchangesAction
{
    public function __construct(
        private readonly ExchangeConnectionRepository $connections,
        private readonly ExchangeClients $clients,
        private readonly ExchangeSyncService $sync,
        private readonly SecretEncryption $secrets,
        private readonly AccountRepository $accounts,
        private readonly PortfolioRepository $portfolios,
        private readonly TransactionRepository $transactions,
        private readonly ActivityLogger $activity,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return Json::ok($response, [
            'providers'   => $this->clients->list(($user['locale'] ?? 'cs') === 'en' ? 'en' : 'cs'),
            'connections' => $this->connections->list(CurrentUser::id($request)),
        ]);
    }

    public function test(Request $request, Response $response): Response
    {
        try {
            $body = (array) ($request->getParsedBody() ?? []);
            $client = $this->client((string) ($body['provider'] ?? ''));
            return Json::ok($response, $this->check($client, $this->credentials($client, $body, true)));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::id($request);
            $body = (array) ($request->getParsedBody() ?? []);
            $client = $this->client((string) ($body['provider'] ?? ''));
            [$accountId, $portfolioId] = $this->links($userId, $body);
            $credentials = $this->credentials($client, $body, true);
            $errors = [];
            $start = Input::enum($body, 'start', ['continue', 'date', 'all'], $errors, 'continue');
            $date = null;
            if ($start === 'date') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($body['start_date'] ?? ''));
                if ($date === false) {
                    $errors['start_date'][] = 'Zadej datum (RRRR-MM-DD).';
                }
            }
            Input::assertValid($errors);
            $check = $this->check($client, $credentials);
            if (!$check['ok']) {
                throw DomainError::validation(['credentials' => [$check['message']]]);
            }
            $syncFrom = match ($start) {
                'date'     => $date->format('Y-m-d H:i:s'),
                'continue' => ($last = $this->transactions->lastExecutedAtForAccount($userId, $accountId)) !== null
                    ? (new \DateTimeImmutable($last))->modify('+1 second')->format('Y-m-d H:i:s') : null,
                default    => null,
            };
            $id = $this->connections->create($userId, [
                'provider' => $client->provider(), 'account_id' => $accountId, 'portfolio_id' => $portfolioId,
                'credentials_enc' => $this->secrets->encrypt(json_encode($credentials, JSON_THROW_ON_ERROR)),
                'key_hint' => self::hint($credentials), 'enabled' => Input::bool($body, 'enabled', true), 'sync_from' => $syncFrom,
            ]);
            $this->activity->log('exchange.connected', $userId, 'exchange_connection', $id, ['provider' => $client->provider(), 'start' => $start, 'sync_from' => $syncFrom]);
            return Json::ok($response, ['connection' => $this->connections->find($userId, $id), 'warnings' => $check['warnings']], 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::id($request);
            $id = (int) $args['id'];
            $existing = $this->connections->find($userId, $id) ?? throw DomainError::notFound('Napojení nenalezeno.');
            $body = (array) ($request->getParsedBody() ?? []);
            $client = $this->client($existing['provider']);
            [$accountId, $portfolioId] = $this->links($userId, $body);
            $credentials = $this->credentials($client, $body, false);
            $warnings = [];
            if ($credentials !== null) {
                $check = $this->check($client, $credentials);
                if (!$check['ok']) {
                    throw DomainError::validation(['credentials' => [$check['message']]]);
                }
                $warnings = $check['warnings'];
            }
            $this->connections->update($userId, $id, [
                'account_id' => $accountId, 'portfolio_id' => $portfolioId, 'enabled' => Input::bool($body, 'enabled', true),
                'credentials_enc' => $credentials !== null ? $this->secrets->encrypt(json_encode($credentials, JSON_THROW_ON_ERROR)) : null,
                'key_hint' => $credentials !== null ? self::hint($credentials) : null,
            ]);
            return Json::ok($response, ['connection' => $this->connections->find($userId, $id), 'warnings' => $warnings]);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = CurrentUser::id($request);
        $existing = $this->connections->find($userId, (int) $args['id']);
        if ($existing === null) {
            return Json::domainError($response, DomainError::notFound('Napojení nenalezeno.'));
        }
        $this->connections->delete($userId, (int) $args['id']);
        $this->activity->log('exchange.disconnected', $userId, 'exchange_connection', (int) $args['id'], ['provider' => $existing['provider']]);
        return $response->withStatus(204);
    }

    /** @param array{id:string} $args */
    public function syncNow(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::id($request);
            $result = $this->sync->sync($userId, (int) $args['id']);
            return Json::ok($response, $result + ['connection' => $this->connections->find($userId, (int) $args['id'])]);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    private function client(string $provider): ExchangeClient
    {
        return $this->clients->get($provider) ?? throw DomainError::validation(['provider' => ['Neznámá burza.']]);
    }

    /**
     * Přihlašovací údaje z `credentials` podle polí klienta. Při úpravě (`$required` false) prázdné = beze změny.
     *
     * @param array<string,mixed> $body
     * @return array<string,string>|null
     */
    private function credentials(ExchangeClient $client, array $body, bool $required): ?array
    {
        $raw = is_array($body['credentials'] ?? null) ? $body['credentials'] : [];
        $out = [];
        $errors = [];
        foreach ($client->credentialFields() as $f) {
            $value = trim((string) ($raw[$f['key']] ?? ''));
            if ($value === '' && ($required || $raw !== [])) {
                $errors['credentials.' . $f['key']][] = 'Povinné pole.';
            }
            if (strlen($value) > 4000) {
                $errors['credentials.' . $f['key']][] = 'Příliš dlouhá hodnota.';
            }
            $out[$f['key']] = $value;
        }
        if (!$required && implode('', $out) === '') {
            return null;
        }
        Input::assertValid($errors);
        return $out;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:int,1:int}
     */
    private function links(int $userId, array $body): array
    {
        $errors = [];
        $accountId = Input::id($body, 'account_id', true, $errors);
        $portfolioId = Input::id($body, 'portfolio_id', true, $errors);
        if ($accountId !== null && $this->accounts->find($userId, $accountId) === null) {
            $errors['account_id'][] = 'Účet nenalezen.';
        }
        if ($portfolioId !== null && $this->portfolios->find($userId, $portfolioId) === null) {
            $errors['portfolio_id'][] = 'Portfolio nenalezeno.';
        }
        Input::assertValid($errors);
        return [(int) $accountId, (int) $portfolioId];
    }

    /**
     * @param array<string,string> $credentials
     * @return array{ok:bool,message:string,warnings:list<string>}
     */
    private function check(ExchangeClient $client, array $credentials): array
    {
        try {
            return $client->test($credentials);
        } catch (ExchangeException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'warnings' => []];
        }
    }

    /** Začátek veřejné části klíče pro rozpoznání v seznamu. @param array<string,string> $credentials */
    private static function hint(array $credentials): string
    {
        $public = (string) ($credentials['api_key'] ?? $credentials['public_key'] ?? $credentials['key_name'] ?? '');
        $public = (string) preg_replace('#^.*/apiKeys/#', '', $public);
        return mb_substr($public, 0, 6) . '…';
    }
}
