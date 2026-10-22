<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Sharing;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\ShareRepository;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Sharing\HouseholdService;
use MyPortfolio\Service\Sharing\ShareService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sdílení pro domácnost — vždy vlastní účet přihlášeného (hlavička X-Owner se ignoruje):
 *   GET    /api/shares                — komu sdílím a kdo sdílí mně
 *   POST   /api/shares                — sdílet s e-mailem (existující uživatel hned, jinak pozvánka)
 *   PUT    /api/shares/{id}           — oprávnění, rozsah, sekce (jen vlastník)
 *   DELETE /api/shares/{id}           — odvolat (vlastník) / opustit (partner)
 *   POST   /api/shares/{id}/resend    — znovu poslat pozvánku
 *   GET    /api/shares/activity       — změny, které v mých datech udělali partneři
 *   GET    /api/household             — souhrn domácnosti
 */
final class SharesAction
{
    public function __construct(
        private readonly ShareRepository $shares,
        private readonly ShareService $service,
        private readonly HouseholdService $household,
        private readonly UserPreferenceRepository $preferences,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $userId = CurrentUser::id($request);
        $strip = static fn (array $s) => array_diff_key($s, ['owner_email' => true]);
        return Json::ok($response, [
            'granted'  => array_map(static fn (array $s) => $strip($s) + ['pending' => $s['grantee_user_id'] === null], $this->shares->granted($userId)),
            'received' => array_map(static fn (array $s) => array_diff_key($s, ['invite_email' => true, 'invite_expires_at' => true]), $this->shares->received($userId)),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            return Json::ok($response, $this->service->create(CurrentUser::id($request), (array) ($request->getParsedBody() ?? [])), 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            return Json::ok($response, $this->service->update(CurrentUser::id($request), (int) $args['id'], (array) ($request->getParsedBody() ?? [])));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $this->service->delete(CurrentUser::id($request), (int) $args['id']);
            return $response->withStatus(204);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function resend(Request $request, Response $response, array $args): Response
    {
        try {
            return Json::ok($response, $this->service->resend(CurrentUser::id($request), (int) $args['id']));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function activity(Request $request, Response $response): Response
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, action, payload, created_at FROM activity_log WHERE user_id = ? AND action = 'share.write' ORDER BY id DESC LIMIT 100"
        );
        $stmt->execute([CurrentUser::id($request)]);
        return Json::ok($response, array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'created_at' => $r['created_at'],
        ] + array_intersect_key((array) json_decode((string) $r['payload'], true), array_flip(['actor_name', 'method', 'path'])),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function household(Request $request, Response $response): Response
    {
        $userId = CurrentUser::id($request);
        return Json::ok($response, $this->household->overview($userId, $this->preferences->displayCurrency($userId)));
    }
}
