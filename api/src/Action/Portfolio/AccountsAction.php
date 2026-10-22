<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Portfolio;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\AccountRepository;
use MyPortfolio\Repository\InstitutionRepository;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Účty uživatele:
 *   GET /api/accounts (?archived=1), POST /api/accounts
 *   GET|PUT|DELETE /api/accounts/{id}
 */
final class AccountsAction
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly InstitutionRepository $institutions,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $archived = ($request->getQueryParams()['archived'] ?? '') === '1';
        return Json::ok($response, $this->accounts->list(CurrentUser::owner($request), $archived));
    }

    /** @param array{id:string} $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        $account = $this->accounts->find(CurrentUser::owner($request), (int) $args['id']);
        return $account === null
            ? Json::domainError($response, DomainError::notFound('Účet nenalezen.'))
            : Json::ok($response, $account);
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $data = $this->input((array) ($request->getParsedBody() ?? []), true);
            if ($this->accounts->nameExists($userId, $data['institution_id'], $data['name'])) {
                throw DomainError::duplicate('Účet s tímto názvem u instituce už máš.');
            }
            $id = $this->accounts->create($userId, $data);
            return Json::ok($response, $this->accounts->find($userId, $id), 201);
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
            $existing = $this->accounts->find($userId, $id) ?? throw DomainError::notFound('Účet nenalezen.');
            $body = (array) ($request->getParsedBody() ?? []);
            $data = $this->input($body, $existing['institution_id'] !== (int) ($body['institution_id'] ?? 0));
            if ($this->accounts->nameExists($userId, $data['institution_id'], $data['name'], $id)) {
                throw DomainError::duplicate('Účet s tímto názvem u instituce už máš.');
            }
            $this->accounts->update($userId, $id, [...$data, 'archived' => Input::bool($body, 'archived')]);
            return Json::ok($response, $this->accounts->find($userId, $id));
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
            $this->accounts->find($userId, $id) ?? throw DomainError::notFound('Účet nenalezen.');
            if ($this->accounts->isUsed($userId, $id)) {
                throw DomainError::inUse('Účet má pozice — místo smazání ho archivuj.');
            }
            $this->accounts->delete($userId, $id);
            return $response->withStatus(204);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /**
     * @param array<string,mixed> $body
     * @param bool $requireActiveInstitution nová vazba musí mířit na aktivní instituci
     * @return array{institution_id:int,name:string,currency:string,note:?string}
     */
    private function input(array $body, bool $requireActiveInstitution): array
    {
        $errors = [];
        $institutionId = Input::id($body, 'institution_id', true, $errors);
        $name = Input::string($body, 'name', 120, true, $errors);
        $currency = Input::currency($body, 'currency', true, $errors, 'CZK');
        $note = Input::string($body, 'note', 500, false, $errors);
        if ($institutionId !== null) {
            $institution = $this->institutions->find($institutionId);
            if ($institution === null || ($requireActiveInstitution && !$institution['is_active'])) {
                $errors['institution_id'][] = 'Instituce nenalezena.';
            }
        }
        Input::assertValid($errors);
        return [
            'institution_id' => (int) $institutionId,
            'name'           => (string) $name,
            'currency'       => (string) $currency,
            'note'           => $note,
        ];
    }
}
