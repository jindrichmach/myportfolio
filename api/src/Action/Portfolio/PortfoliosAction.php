<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Portfolio;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\PortfolioRepository;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Portfolia uživatele:
 *   GET /api/portfolios (?archived=1), POST /api/portfolios
 *   GET|PUT|DELETE /api/portfolios/{id}
 */
final class PortfoliosAction
{
    public function __construct(private readonly PortfolioRepository $portfolios) {}

    public function list(Request $request, Response $response): Response
    {
        $archived = ($request->getQueryParams()['archived'] ?? '') === '1';
        return Json::ok($response, $this->portfolios->list(CurrentUser::owner($request), $archived));
    }

    /** @param array{id:string} $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        $portfolio = $this->portfolios->find(CurrentUser::owner($request), (int) $args['id']);
        return $portfolio === null
            ? Json::domainError($response, DomainError::notFound('Portfolio nenalezeno.'))
            : Json::ok($response, $portfolio);
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $data = $this->input((array) ($request->getParsedBody() ?? []));
            if ($this->portfolios->nameExists($userId, $data['name'])) {
                throw DomainError::duplicate('Portfolio s tímto názvem už máš.');
            }
            $id = $this->portfolios->create($userId, $data);
            return Json::ok($response, $this->portfolios->find($userId, $id), 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $id = (int) $args['id'];
            $this->portfolios->find($userId, $id) ?? throw DomainError::notFound('Portfolio nenalezeno.');
            $body = (array) ($request->getParsedBody() ?? []);
            $data = $this->input($body);
            if ($this->portfolios->nameExists($userId, $data['name'], $id)) {
                throw DomainError::duplicate('Portfolio s tímto názvem už máš.');
            }
            $this->portfolios->update($userId, $id, [...$data, 'archived' => Input::bool($body, 'archived')]);
            return Json::ok($response, $this->portfolios->find($userId, $id));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $id = (int) $args['id'];
            $this->portfolios->find($userId, $id) ?? throw DomainError::notFound('Portfolio nenalezeno.');
            if ($this->portfolios->isUsed($userId, $id)) {
                throw DomainError::inUse('Portfolio má pozice — místo smazání ho archivuj.');
            }
            $this->portfolios->delete($userId, $id);
            return $response->withStatus(204);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /**
     * @param array<string,mixed> $body
     * @return array{name:string,sort:int}
     */
    private function input(array $body): array
    {
        $errors = [];
        $name = Input::string($body, 'name', 120, true, $errors);
        $sort = $body['sort'] ?? 0;
        if (!is_numeric($sort)) {
            $errors['sort'][] = 'Neplatné pořadí.';
        }
        Input::assertValid($errors);
        return ['name' => (string) $name, 'sort' => (int) $sort];
    }
}
