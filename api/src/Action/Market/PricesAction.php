<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Market;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\PriceRepository;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Price\PriceService;
use MyPortfolio\Support\Decimal;
use MyPortfolio\Support\Input;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 *   GET  /api/prices?instrument_id=&from=&to=   — denní ceny (výchozí posledních 365 dní)
 *   POST /api/prices { instrument_id, date, close, currency? }
 *        — ruční cena; u instrumentu s price_source=manual smí každý, jinak jen admin
 */
final class PricesAction
{
    public function __construct(
        private readonly InstrumentRepository $instruments,
        private readonly PriceRepository $prices,
        private readonly PriceService $service,
        private readonly ClockInterface $clock,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $instrument = $this->instruments->find((int) ($q['instrument_id'] ?? 0));
        if ($instrument === null) {
            return Json::domainError($response, DomainError::notFound('Instrument nenalezen.'));
        }
        $date = static fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
        $to = $date($q['to'] ?? null) ?? $this->today()->format('Y-m-d');
        $from = $date($q['from'] ?? null) ?? (new \DateTimeImmutable($to))->modify('-365 days')->format('Y-m-d');
        return Json::ok($response, [
            'instrument_id' => $instrument['id'],
            'last_price'    => $this->service->withStaleness($instrument, $this->prices->latest($instrument['id'])),
            'data'          => $this->prices->history($instrument['id'], $from, $to),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $body = (array) ($request->getParsedBody() ?? []);
            $errors = [];
            $instrumentId = Input::id($body, 'instrument_id', true, $errors);
            $instrument = $instrumentId !== null ? $this->instruments->find($instrumentId) : null;
            if ($instrumentId !== null && $instrument === null) {
                throw DomainError::notFound('Instrument nenalezen.');
            }
            if ($instrument !== null && $instrument['price_source'] !== 'manual' && !CurrentUser::isAdmin($request)) {
                throw new DomainError('forbidden', 'Ruční cenu u automaticky oceňovaného instrumentu smí zadat jen admin.', 403);
            }
            $date = (string) ($body['date'] ?? '');
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                $errors['date'][] = 'Neplatné datum.';
            } elseif ($parsed > $this->today()) {
                $errors['date'][] = 'Datum nesmí být v budoucnosti.';
            }
            try {
                $close = Decimal::parse($body['close'] ?? null, Decimal::SCALE_PRICE);
                if ($close === null || !$close->isPositive()) {
                    $errors['close'][] = 'Cena musí být větší než 0.';
                }
            } catch (\InvalidArgumentException $e) {
                $errors['close'][] = $e->getMessage();
                $close = null;
            }
            $currency = Input::currency($body, 'currency', true, $errors, $instrument['quote_currency'] ?? null);
            Input::assertValid($errors);

            $this->service->setManual((int) $instrumentId, $date, (string) Decimal::toDb($close, Decimal::SCALE_PRICE), (string) $currency);
            return Json::ok($response, [
                'instrument_id' => $instrumentId,
                'last_price'    => $this->service->withStaleness($instrument, $this->prices->latest((int) $instrumentId)),
            ], 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
    }
}
