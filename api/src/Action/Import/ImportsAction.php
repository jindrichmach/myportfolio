<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Import;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\ImportBatchRepository;
use MyPortfolio\Service\Exchange\ExchangeSyncService;
use MyPortfolio\Service\Import\CsvImportService;
use MyPortfolio\Service\Import\ImportParsers;
use MyPortfolio\Service\Import\Reader\TabularReader;
use MyPortfolio\Service\Portfolio\DomainError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Importy z exportů burz a brokerů:
 *   GET  /api/imports/sources                — podporované zdroje
 *   POST /api/imports/preview                — multipart: file, account_id, portfolio_id, source? → náhled
 *   GET  /api/imports/preview/{token}        — náhled znovu
 *   POST /api/imports/preview/{token}/confirm — rozhodnutí (assets, transfers) → dávka
 *   GET  /api/imports                        — dávky importu
 *   POST /api/imports/{id}/revert            — vrácení dávky
 */
final class ImportsAction
{
    public function __construct(
        private readonly CsvImportService $imports,
        private readonly ImportParsers $parsers,
        private readonly ImportBatchRepository $batches,
        private readonly ExchangeSyncService $exchangeSync,
    ) {}

    public function sources(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->parsers->list());
    }

    public function preview(Request $request, Response $response): Response
    {
        try {
            $file = $request->getUploadedFiles()['file'] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                throw DomainError::validation(['file' => ['Vyber soubor s exportem.']]);
            }
            if (($file->getSize() ?? 0) > TabularReader::MAX_BYTES) {
                throw DomainError::validation(['file' => ['Soubor je větší než 20 MB.']]);
            }
            $body = (array) ($request->getParsedBody() ?? []);
            return Json::ok($response, $this->imports->preview(
                CurrentUser::owner($request),
                (string) $file->getStream(),
                (string) ($file->getClientFilename() ?? 'import.csv'),
                (int) ($body['account_id'] ?? 0),
                (int) ($body['portfolio_id'] ?? 0),
                isset($body['source']) ? (string) $body['source'] : null,
            ));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{token:string} $args */
    public function view(Request $request, Response $response, array $args): Response
    {
        try {
            return Json::ok($response, $this->imports->view(CurrentUser::owner($request), $args['token']));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{token:string} $args */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        try {
            $body = (array) ($request->getParsedBody() ?? []);
            $pending = $this->exchangeSync->pendingExternalIds(CurrentUser::owner($request), $args['token']);
            $result = $this->imports->confirm(CurrentUser::owner($request), $args['token'], [
                'assets'    => is_array($body['assets'] ?? null) ? $body['assets'] : [],
                'transfers' => is_array($body['transfers'] ?? null) ? $body['transfers'] : [],
            ]);
            $this->exchangeSync->confirmed(CurrentUser::owner($request), $args['token'], $pending);  // náhled ze synchronizace burzy
            return Json::ok($response, $result);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function batches(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->batches->list(CurrentUser::owner($request)));
    }

    /** @param array{id:string} $args */
    public function revert(Request $request, Response $response, array $args): Response
    {
        try {
            return Json::ok($response, ['deleted' => $this->imports->revert(CurrentUser::owner($request), (int) $args['id'])]);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }
}
