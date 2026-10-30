<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Catalog;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Service\Catalog\CatalogIcons;
use MyPortfolio\Service\Catalog\IconLibrary;
use MyPortfolio\Service\Catalog\IconStore;
use MyPortfolio\Service\Catalog\WebsiteIconFetcher;
use MyPortfolio\Service\Portfolio\DomainError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Ikony katalogu:
 *   POST   /api/{institutions|instruments}/{id}/icon — nahrání (multipart pole `icon`; admin nebo autor)
 *   DELETE /api/{institutions|instruments}/{id}/icon — odebrání
 *   GET    /api/icons/{hash}.png                      — obrázek (obsah se nemění → immutable cache)
 *   GET    /api/icon-library                          — přibalené ikony krypta, správci ETF, české instituce s webem
 *   GET    /api/icon-library/{crypto|etf|institutions}/{slug}.png — náhled ikony z knihovny
 *   POST   /api/{institutions|instruments}/{id}/icon/library — ikona z knihovny (`symbol` krypta, `etf` nebo `institution` = slug)
 *   POST   /api/institutions/{id}/icon/website        — logo stažené z webu instituce (`url`, výchozí web položky)
 */
final class IconAction
{
    public function __construct(
        private readonly CatalogIcons $icons,
        private readonly IconStore $store,
        private readonly IconLibrary $library,
        private readonly WebsiteIconFetcher $website,
    ) {}

    public function library(Request $request, Response $response): Response
    {
        return Json::ok($response, [
            'crypto' => array_map(static fn (array $c) => [
                'symbol' => $c['symbol'], 'name' => $c['name'], 'url' => '/api/icon-library/crypto/' . strtolower($c['symbol']) . '.png',
            ], $this->library->crypto()),
            'etf' => array_map(static fn (array $e) => [
                'slug' => $e['slug'], 'name' => $e['name'], 'url' => '/api/icon-library/etf/' . $e['slug'] . '.png',
            ], $this->library->etfIssuers()),
            'institutions' => array_map(static fn (array $i) => [
                'name' => $i['name'], 'type' => $i['type'], 'website' => $i['website'],
                'icon' => isset($i['icon']) ? basename($i['icon'], '.png') : null,
                'url'  => isset($i['icon']) ? '/api/icon-library/' . $i['icon'] : null,
            ], $this->library->institutions()),
        ]);
    }

    /** @param array{set:string,slug:string} $args */
    public function libraryImage(Request $request, Response $response, array $args): Response
    {
        try {
            $response->getBody()->write(match ($args['set']) {
                'crypto' => $this->library->cryptoIcon($args['slug']),
                'etf'    => $this->library->etfIcon($args['slug']),
                default  => $this->library->institutionIcon($args['slug']),
            });
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
        return $response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, max-age=86400');
    }

    /** @param array{kind:string,id:string} $args */
    public function fromLibrary(Request $request, Response $response, array $args): Response
    {
        try {
            $body = (array) $request->getParsedBody();
            $bytes = match (true) {
                isset($body['institution']) => $this->library->institutionIcon((string) $body['institution']),
                isset($body['etf'])         => $this->library->etfIcon((string) $body['etf']),
                default                     => $this->library->cryptoIcon((string) ($body['symbol'] ?? '')),
            };
            $name = $this->icons->set($args['kind'], (int) $args['id'], $bytes,
                CurrentUser::id($request), CurrentUser::isAdmin($request));
            return Json::ok($response, ['icon_url' => CatalogIcons::url($name)]);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function fromWebsite(Request $request, Response $response, array $args): Response
    {
        try {
            $url = trim((string) (((array) $request->getParsedBody())['url'] ?? ''));
            $url = $url !== '' ? $url : ($this->icons->institutionWebsite((int) $args['id'])
                ?? throw DomainError::validation(['website' => ['Instituce nemá vyplněný web — vyber ji ze seznamu nebo nahraj obrázek.']]));
            $name = $this->icons->set('institutions', (int) $args['id'], $this->website->fetch($url),
                CurrentUser::id($request), CurrentUser::isAdmin($request));
            return Json::ok($response, ['icon_url' => CatalogIcons::url($name)]);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

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
