<?php

declare(strict_types=1);

namespace MyPortfolio\Middleware;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\ShareRepository;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Sharing\AccessContext;
use MyPortfolio\Service\Sharing\ShareAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Kontext vlastníka pro sdílení. Hlavička `X-Owner: <id>` přepne čtení (a u sdílení
 * pro zápis i zápis) na data vlastníka, který přihlášenému sdílí:
 *
 *   - jen session (API token → 403), aktivní sdílení vlastník → přihlášený (jinak 404, neprozrazuje existenci);
 *   - cesta musí být v allowlistu {@see ShareAccess} (jinak 403 not_shared), zápis jen se sdílením pro zápis,
 *     sekce (čisté jmění, daně, příjmy) jen když je ve sdílení;
 *   - osobní cesty (nastavení, přihlášení, sdílení, číselníky) hlavičku ignorují.
 *
 * Nastaví atribut {@see self::ATTR_OWNER} (čte `CurrentUser::owner`) a {@see AccessContext} pro repository.
 * Úspěšný zápis partnera se zapíše do logu aktivit vlastníka.
 */
final class ShareContextMiddleware implements MiddlewareInterface
{
    public const ATTR_OWNER = 'share.owner';
    public const ATTR_SHARE = 'share.share';
    public const HEADER = 'X-Owner';

    public function __construct(
        private readonly ShareRepository $shares,
        private readonly AccessContext $context,
        private readonly ActivityLogger $activity,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $this->context->reset();
        $header = trim($request->getHeaderLine(self::HEADER));
        $actor = CurrentUser::id($request);
        $path = $request->getUri()->getPath();
        if ($header === '' || $actor === 0 || ShareAccess::isPersonal($path) || (int) $header === $actor) {
            return $handler->handle($request);
        }

        $deny = fn (string $code, string $message, int $status) => Json::error($this->responseFactory->createResponse($status), $code, $message, $status);
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return $deny('owner_context_session_only', 'Sdílená data jdou číst jen v přihlášené aplikaci, ne API tokenem.', 403);
        }
        $owner = filter_var($header, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $share = $owner !== false ? $this->shares->active($owner, $actor) : null;
        if ($share === null) {
            return $deny('not_found', 'Záznam nenalezen.', 404);
        }
        $rule = ShareAccess::rule($request->getMethod(), $path);
        if ($rule === null) {
            return $deny('not_shared', 'Tato část se ve sdílení nezobrazuje.', 403);
        }
        if ($rule['write'] && $share['permission'] !== 'write') {
            return $deny('read_only', 'Sdílení je jen pro čtení.', 403);
        }
        if ($rule['section'] !== null && ($share['scope'] !== 'all' || !in_array($rule['section'], $share['sections'], true))) {
            return $deny('section_not_shared', 'Tato část se ve sdílení nezobrazuje.', 403);
        }

        $portfolios = $share['scope'] === 'portfolios' ? array_column($share['portfolios'], 'id') : null;
        $this->context->set((int) $owner, $actor, $portfolios);
        try {
            $response = $handler->handle($request->withAttribute(self::ATTR_OWNER, (int) $owner)->withAttribute(self::ATTR_SHARE, $share));
        } finally {
            $this->context->reset();
        }
        if ($rule['write'] && $response->getStatusCode() < 400 && strtoupper($request->getMethod()) !== 'GET') {
            $this->activity->log('share.write', (int) $owner, 'share', $share['id'], [
                'actor_user_id' => $actor, 'actor_name' => $share['grantee_name'], 'method' => strtoupper($request->getMethod()), 'path' => $path,
                'status' => $response->getStatusCode(),
            ]);
        }
        return $response;
    }
}
