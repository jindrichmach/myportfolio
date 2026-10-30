<?php

declare(strict_types=1);

namespace MyPortfolio\Action\NetWorth;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\NetWorthRepository;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\History\HistoryService;
use MyPortfolio\Service\NetWorth\LoanSchedule;
use MyPortfolio\Service\NetWorth\NetWorthService;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Support\Decimal;
use MyPortfolio\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Čisté jmění, v měně zobrazení:
 *   GET    /api/net-worth                       — investice + majetek − závazky, položky
 *   GET    /api/net-worth/history?range=        — denní historie
 *   POST   /api/net-worth/items                 — nová položka (závazek: počáteční zůstatek, úrok, splátka)
 *   PUT    /api/net-worth/items/{id}            — název, měna, poznámka, archivace
 *   DELETE /api/net-worth/items/{id}
 *   GET    /api/net-worth/items/{id}/entries    — ocenění / zůstatky (u závazku i splátkový výhled)
 *   POST   /api/net-worth/items/{id}/entries    — ocenění nebo korekce zůstatku k datu (stejný den se přepíše)
 *   DELETE /api/net-worth/entries/{id}
 */
final class NetWorthAction
{
    private const SCHEDULE_MONTHS = 12;

    public function __construct(
        private readonly NetWorthService $service,
        private readonly NetWorthRepository $repository,
        private readonly UserPreferenceRepository $preferences,
    ) {}

    public function overview(Request $request, Response $response): Response
    {
        $userId = CurrentUser::owner($request);
        $archived = ($request->getQueryParams()['archived'] ?? '') === '1';
        return Json::ok($response, $this->service->overview($userId, $this->preferences->displayCurrency(CurrentUser::id($request)), $archived));
    }

    public function history(Request $request, Response $response): Response
    {
        $userId = CurrentUser::owner($request);
        $range = (string) ($request->getQueryParams()['range'] ?? '1Y');
        if (!in_array($range, HistoryService::RANGES, true)) {
            return Json::domainError($response, DomainError::validation(['range' => ['Neplatné období.']]));
        }
        return Json::ok($response, $this->service->history($userId, $this->preferences->displayCurrency(CurrentUser::id($request)), $range));
    }

    public function createItem(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $body = (array) ($request->getParsedBody() ?? []);
            $errors = [];
            $kind = Input::enum($body, 'kind', NetWorthService::KINDS, $errors);
            $data = $this->itemFields($body, $errors);
            $entry = null;
            if ($kind === 'liability' || ($body['value'] ?? '') !== '') {
                $entry = $this->entryFields($body, $kind === 'liability', $errors, true);
            }
            Input::assertValid($errors);
            $id = $this->repository->createItem($userId, ['kind' => (string) $kind] + $data);
            if ($entry !== null) {
                $this->repository->saveEntry($userId, $id, ...$entry);
            }
            return Json::ok($response, $this->repository->findItem($userId, $id), 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function updateItem(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $this->item($userId, (int) $args['id']);
            $body = (array) ($request->getParsedBody() ?? []);
            $errors = [];
            $data = $this->itemFields($body, $errors);
            Input::assertValid($errors);
            $this->repository->updateItem($userId, (int) $args['id'], $data + ['archived' => Input::bool($body, 'archived')]);
            return Json::ok($response, $this->repository->findItem($userId, (int) $args['id']));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function deleteItem(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $this->item($userId, (int) $args['id']);
            $this->repository->deleteItem($userId, (int) $args['id']);
            return $response->withStatus(204);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function entries(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $item = $this->item($userId, (int) $args['id']);
            $entries = $this->repository->entries($userId, $item['id'])[$item['id']] ?? [];
            $out = ['item' => $item, 'entries' => array_reverse($entries)];
            if ($item['kind'] === 'liability') {
                $out['schedule'] = $this->schedule(new LoanSchedule($entries));
            }
            return Json::ok($response, $out);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function saveEntry(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $item = $this->item($userId, (int) $args['id']);
            $errors = [];
            $entry = $this->entryFields((array) ($request->getParsedBody() ?? []), $item['kind'] === 'liability', $errors, false);
            Input::assertValid($errors);
            $this->repository->saveEntry($userId, $item['id'], ...$entry);
            return Json::ok($response, ['entries' => array_reverse($this->repository->entries($userId, $item['id'])[$item['id']] ?? [])], 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function deleteEntry(Request $request, Response $response, array $args): Response
    {
        $userId = CurrentUser::owner($request);
        return $this->repository->deleteEntry($userId, (int) $args['id'])
            ? $response->withStatus(204)
            : Json::domainError($response, DomainError::notFound('Záznam nenalezen.'));
    }

    /** @return array<string,mixed> */
    private function item(int $userId, int $id): array
    {
        return $this->repository->findItem($userId, $id) ?? throw DomainError::notFound('Položka nenalezena.');
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,list<string>> $errors
     * @return array{name:string,currency:string,note:?string}
     */
    private function itemFields(array $body, array &$errors): array
    {
        return [
            'name'     => (string) Input::string($body, 'name', 120, true, $errors),
            'currency' => (string) Input::currency($body, 'currency', false, $errors, 'CZK'),
            'note'     => Input::string($body, 'note', 500, false, $errors),
        ];
    }

    /**
     * Ocenění (majetek) nebo zůstatek s úrokem a splátkou (závazek). Úrok a splátka jsou povinné u prvního
     * záznamu závazku; u korekce prázdné = beze změny.
     *
     * @param array<string,mixed> $body
     * @param array<string,list<string>> $errors
     * @return array{0:string,1:string,2:?string,3:?string,4:?string} datum, hodnota, úrok, splátka, poznámka
     */
    private function entryFields(array $body, bool $liability, array &$errors, bool $first): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($body['date'] ?? ''));
        if ($date === false || $date->format('Y-m-d') !== ($body['date'] ?? null)) {
            $errors['date'][] = 'Zadej datum (RRRR-MM-DD).';
        }
        $value = self::decimal($body['value'] ?? null, 4);
        if ($value === null || $value->isNegative()) {
            $errors['value'][] = $liability ? 'Zadej zůstatek (0 a víc).' : 'Zadej hodnotu (0 a víc).';
        }
        $rate = $payment = null;
        if ($liability) {
            $rate = self::decimal($body['rate_pct'] ?? null, 4);
            $payment = self::decimal($body['payment'] ?? null, 2);
            if (($rate === null && ($first || ($body['rate_pct'] ?? '') !== '')) || ($rate !== null && ($rate->isNegative() || $rate->isGreaterThan(100)))) {
                $errors['rate_pct'][] = 'Zadej úrok 0–100 % p. a.';
            }
            if (($payment === null && ($first || ($body['payment'] ?? '') !== '')) || ($payment !== null && $payment->isNegative())) {
                $errors['payment'][] = 'Zadej měsíční splátku.';
            }
        }
        return [
            $date !== false ? $date->format('Y-m-d') : '',
            (string) $value,
            $rate !== null ? (string) $rate : null,
            $payment !== null ? (string) $payment : null,
            Input::string($body, 'note', 255, false, $errors),
        ];
    }

    /** Výhled dalších splátek od dneška. @return list<array{date:string,balance:string}> */
    private function schedule(LoanSchedule $loan): array
    {
        $today = new \DateTimeImmutable('today');
        $dates = [];
        for ($m = 0; $m <= self::SCHEDULE_MONTHS; $m++) {
            $dates[] = $today->modify("first day of +$m month")->modify('last day of this month')->format('Y-m-d');
        }
        $out = [];
        foreach ($loan->balances($dates) as $date => $balance) {
            if ($balance !== null) {
                $out[] = ['date' => $date, 'balance' => (string) $balance->toScale(2, RoundingMode::HalfUp)];
            }
        }
        return $out;
    }

    private static function decimal(mixed $value, int $scale): ?BigDecimal
    {
        try {
            return Decimal::parse($value, $scale);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
