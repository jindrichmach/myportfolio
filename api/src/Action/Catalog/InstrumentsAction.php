<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Catalog;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Service\Catalog\CatalogIcons;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Price\PriceService;
use MyPortfolio\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Katalog instrumentů:
 *   GET    /api/instruments              — seznam (?q=, ?asset_class=, ?all=1)
 *   POST   /api/instruments              — založení (kterýkoli uživatel)
 *   PUT    /api/admin/instruments/{id}   — úprava (admin)
 *   DELETE /api/admin/instruments/{id}   — smazání, jen pokud se nepoužívá (admin)
 */
final class InstrumentsAction
{
    public function __construct(
        private readonly InstrumentRepository $instruments,
        private readonly PriceService $prices,
        private readonly CatalogIcons $icons,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $class = (string) ($q['asset_class'] ?? '');
        $rows = $this->instruments->list(
            ($q['all'] ?? '') === '1',
            trim((string) ($q['q'] ?? '')),
            in_array($class, InstrumentRepository::ASSET_CLASSES, true) ? $class : null,
        );
        $last = $this->prices->lastPrices($rows);
        foreach ($rows as &$row) {
            $row['last_price'] = $last[$row['id']] ?? null;
        }
        unset($row);
        return Json::ok($response, array_map(fn (array $r) => $this->present($request, $r), $rows));
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $data = $this->input((array) ($request->getParsedBody() ?? []));
            if ($this->instruments->exists($data['asset_class'], $data['symbol'], $data['quote_currency'])) {
                throw DomainError::duplicate('Instrument s tímto symbolem a měnou už existuje.');
            }
            $id = $this->instruments->create($data, CurrentUser::id($request));
            return Json::ok($response, $this->present($request, $this->instruments->find($id)), 201);
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
            if ($this->instruments->find($id) === null) {
                throw DomainError::notFound('Instrument nenalezen.');
            }
            $body = (array) ($request->getParsedBody() ?? []);
            $data = $this->input($body);
            if ($this->instruments->exists($data['asset_class'], $data['symbol'], $data['quote_currency'], $id)) {
                throw DomainError::duplicate('Instrument s tímto symbolem a měnou už existuje.');
            }
            $this->instruments->update($id, [...$data, 'is_active' => Input::bool($body, 'is_active', true)]);
            return Json::ok($response, $this->present($request, $this->instruments->find($id)));
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
            $row = $this->instruments->find($id) ?? throw DomainError::notFound('Instrument nenalezen.');
            if ($this->instruments->isUsed($id)) {
                throw DomainError::inUse('Instrument je v pozicích — místo smazání ho deaktivuj.');
            }
            $this->instruments->delete($id);
            $this->icons->release($row['icon']);
            return $response->withStatus(204);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function input(array $body): array
    {
        $errors = [];
        $assetClass = Input::enum($body, 'asset_class', InstrumentRepository::ASSET_CLASSES, $errors);
        $symbol = Input::string($body, 'symbol', 40, true, $errors);
        $symbol = $symbol !== null ? strtoupper($symbol) : null;
        $name = Input::string($body, 'name', 190, true, $errors);
        $isin = Input::string($body, 'isin', 12, false, $errors);
        if ($isin !== null && preg_match('/^[A-Z]{2}[A-Z0-9]{9}\d$/', strtoupper($isin)) !== 1) {
            $errors['isin'][] = 'Neplatný ISIN.';
        }
        $quoteCurrency = Input::currency($body, 'quote_currency', true, $errors);
        $decimals = $body['decimals'] ?? 8;
        if (!is_numeric($decimals) || (int) $decimals < 0 || (int) $decimals > 18) {
            $errors['decimals'][] = 'Počet desetinných míst 0–18.';
        }
        $priceSource = Input::enum($body, 'price_source', InstrumentRepository::PRICE_SOURCES, $errors, 'manual');

        $refs = [];
        $rawRefs = $body['source_refs'] ?? [];
        if (!is_array($rawRefs)) {
            $errors['source_refs'][] = 'Očekáván objekt {zdroj: identifikátor}.';
        } else {
            foreach ($rawRefs as $source => $ref) {
                if (!in_array($source, InstrumentRepository::PRICE_SOURCES, true) || !is_string($ref) || mb_strlen($ref) > 64) {
                    $errors['source_refs'][] = 'Neplatný identifikátor zdroje ' . $source . '.';
                    continue;
                }
                if (trim($ref) !== '') {
                    $refs[$source] = trim($ref);
                }
            }
        }
        Input::assertValid($errors);

        return [
            'asset_class'    => $assetClass,
            'symbol'         => $symbol,
            'name'           => $name,
            'isin'           => $isin !== null ? strtoupper($isin) : null,
            'quote_currency' => $quoteCurrency,
            'decimals'       => (int) $decimals,
            'price_source'   => $priceSource,
            'source_refs'    => $refs,
        ];
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
