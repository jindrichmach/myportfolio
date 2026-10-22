<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Catalog;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Service\Catalog\CatalogIcons;
use MyPortfolio\Service\Catalog\IconStore;
use MyPortfolio\Service\Portfolio\DomainError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Ikony katalogu:
 *   POST   /api/{institutions|instruments}/{id}/icon — nahrání (multipart pole `icon`; admin nebo autor)
 *   DELETE /api/{institutions|instruments}/{id}/icon — odebrání
 *   GET    /api/icons/{hash}.png                      — obrázek (obsah se nemění → immutable cache)
 */
final class IconAction
{
    public function __construct(
        private readonly CatalogIcons $icons,
        private readonly IconStore $store,
    ) {}

    /** @param array{kind:string,id:string} $args */
    public function upload(Request $request, Response $response, array $args): Response
    {
        try {
            $file = $request->getUploadedFiles()['icon'] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                throw DomainError::validation(['icon' => [
                    $file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                        ? 'Obrázek musí mít nejvýše 1 MB.'
                        : 'Vyber obrázek.',
                ]]);
            }
            if (($file->getSize() ?? 0) > IconStore::MAX_BYTES) {
                throw DomainError::validation(['icon' => ['Obrázek musí mít nejvýše 1 MB.']]);
            }
            $name = $this->icons->set($args['kind'], (int) $args['id'], (string) $file->getStream(),
                CurrentUser::id($request), CurrentUser::isAdmin($request));
            return Json::ok($response, ['icon_url' => CatalogIcons::url($name)]);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{kind:string,id:string} $args */
    public function remove(Request $request, Response $response, array $args): Response
    {
        try {
            $this->icons->remove($args['kind'], (int) $args['id'], CurrentUser::id($request), CurrentUser::isAdmin($request));
            return $response->withStatus(204);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{file:string} $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $path = $this->store->path($args['file']);
        if (!is_file($path)) {
            return Json::error($response, 'not_found', 'Ikona nenalezena.', 404);
        }
        $response->getBody()->write((string) file_get_contents($path));
        return $response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, max-age=31536000, immutable');
    }
}
