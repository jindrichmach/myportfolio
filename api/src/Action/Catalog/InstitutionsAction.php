<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Catalog;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\InstitutionRepository;
use MyPortfolio\Service\Catalog\CatalogIcons;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Katalog institucí:
 *   GET    /api/institutions              — seznam (?all=1 i neaktivní)
 *   POST   /api/institutions              — založení (kterýkoli uživatel)
 *   PUT    /api/admin/institutions/{id}   — úprava (admin)
 *   DELETE /api/admin/institutions/{id}   — smazání, jen pokud se nepoužívá (admin)
 */
final class InstitutionsAction
{
    public function __construct(
        private readonly InstitutionRepository $institutions,
        private readonly CatalogIcons $icons,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $all = ($request->getQueryParams()['all'] ?? '') === '1';
        return Json::ok($response, array_map(fn (array $r) => $this->present($request, $r), $this->institutions->list($all)));
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $data = $this->input((array) ($request->getParsedBody() ?? []));
            if ($this->institutions->nameExists($data['name'])) {
                throw DomainError::duplicate('Instituce s tímto názvem už existuje.');
            }
            $id = $this->institutions->create($data, CurrentUser::id($request));
            return Json::ok($response, $this->present($request, $this->institutions->find($id)), 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $this->assertAdmin($request);
            $id = (int) $args['id'];
            if ($this->institutions->find($id) === null) {
                throw DomainError::notFound('Instituce nenalezena.');
            }
            $body = (array) ($request->getParsedBody() ?? []);
            $data = $this->input($body);
            if ($this->institutions->nameExists($data['name'], $id)) {
                throw DomainError::duplicate('Instituce s tímto názvem už existuje.');
            }
            $this->institutions->update($id, [...$data, 'is_active' => Input::bool($body, 'is_active', true)]);
            return Json::ok($response, $this->present($request, $this->institutions->find($id)));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $this->assertAdmin($request);
            $id = (int) $args['id'];
            $row = $this->institutions->find($id) ?? throw DomainError::notFound('Instituce nenalezena.');
            if ($this->institutions->isUsed($id)) {
                throw DomainError::inUse('Instituce má účty — místo smazání ji deaktivuj.');
            }
            $this->institutions->delete($id);
            $this->icons->release($row['icon']);
            return $response->withStatus(204);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /**
     * @param array<string,mixed> $body
     * @return array{name:string,type:string,website:?string}
     */
    private function input(array $body): array
    {
        $errors = [];
        $name = Input::string($body, 'name', 120, true, $errors);
        $type = Input::enum($body, 'type', InstitutionRepository::TYPES, $errors);
        $website = Input::string($body, 'website', 190, false, $errors);
        if ($website !== null && filter_var($website, FILTER_VALIDATE_URL) === false) {
            $errors['website'][] = 'Neplatná URL.';
        }
        Input::assertValid($errors);
        return ['name' => (string) $name, 'type' => (string) $type, 'website' => $website];
    }

    /** @param array<string,mixed>|null $row */
    private function present(Request $request, ?array $row): ?array
    {
        return $row !== null ? CatalogIcons::present($row, CurrentUser::id($request), CurrentUser::isAdmin($request)) : null;
    }

    private function assertAdmin(Request $request): void
    {
        if (!CurrentUser::isAdmin($request)) {
            throw new DomainError('forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
    }
}
