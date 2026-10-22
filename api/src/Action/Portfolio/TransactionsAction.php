<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Portfolio;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\TransactionRepository;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Portfolio\TransactionService;
use MyPortfolio\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Transakce uživatele:
 *   GET  /api/transactions   (?position_id=&portfolio_id=&account_id=&type=&from=&to=&page=&per_page=&sort=)
 *   POST /api/transactions   { position_id, type, executed_at, quantity?, unit_price?, amount?, … }
 *   GET|PUT|DELETE /api/transactions/{id}   (DELETE nohy převodu smaže celý převod)
 *   POST /api/transfers      { from_position_id, to_position_id, quantity, executed_at, fee?, note? }
 */
final class TransactionsAction
{
    private const TYPES = ['buy', 'sell', 'deposit', 'withdrawal', 'transfer_in', 'transfer_out',
        'reward', 'dividend', 'interest', 'fee', 'split'];

    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly TransactionService $service,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $date = static fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
        $type = (string) ($q['type'] ?? '');
        $perPage = max(5, min(200, (int) ($q['per_page'] ?? 50)));
        return Json::ok($response, $this->transactions->list(
            CurrentUser::owner($request),
            [
                'position_id'  => (int) ($q['position_id'] ?? 0),
                'portfolio_id' => (int) ($q['portfolio_id'] ?? 0),
                'account_id'   => (int) ($q['account_id'] ?? 0),
                'type'         => in_array($type, self::TYPES, true) ? $type : null,
                'from'         => $date($q['from'] ?? null),
                'to'           => $date($q['to'] ?? null),
            ],
            max(1, (int) ($q['page'] ?? 1)),
            $perPage,
            (string) ($q['sort'] ?? '-executed_at'),
        ));
    }

    /** @param array{id:string} $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        $tx = $this->transactions->find(CurrentUser::owner($request), (int) $args['id']);
        return $tx === null
            ? Json::domainError($response, DomainError::notFound('Transakce nenalezena.'))
            : Json::ok($response, $tx);
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $body = (array) ($request->getParsedBody() ?? []);
            $errors = [];
            $positionId = Input::id($body, 'position_id', true, $errors);
            Input::assertValid($errors);
            $id = $this->service->create($userId, (int) $positionId, $body);
            return Json::ok($response, $this->transactions->find($userId, $id), 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $this->service->update($userId, (int) $args['id'], (array) ($request->getParsedBody() ?? []));
            return Json::ok($response, $this->transactions->find($userId, (int) $args['id']));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $this->service->delete(CurrentUser::owner($request), (int) $args['id']);
            return $response->withStatus(204);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function transfer(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $result = $this->service->transfer($userId, (array) ($request->getParsedBody() ?? []));
            return Json::ok($response, [
                'transfer_group_id' => $result['transfer_group_id'],
                'transactions'      => array_map(fn (int $id) => $this->transactions->find($userId, $id), $result['ids']),
            ], 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }
}
