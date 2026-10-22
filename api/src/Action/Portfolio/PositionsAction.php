<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Portfolio;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\AccountRepository;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\ManualValuationRepository;
use MyPortfolio\Repository\PortfolioRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Service\BankNotice\AccountNumber;
use MyPortfolio\Service\Portfolio\CashBalanceService;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Portfolio\TransactionService;
use MyPortfolio\Service\Portfolio\ValuationService;
use MyPortfolio\Service\Price\PriceService;
use MyPortfolio\Support\Decimal;
use MyPortfolio\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pozice uživatele (instrument × účet × portfolio, nebo ručně oceňovaná hodnota):
 *   GET /api/positions (?portfolio_id=&account_id=&archived=1), POST /api/positions
 *   GET|PUT|DELETE /api/positions/{id}
 *
 * Instrument, režim ocenění a měna se po založení nemění (transakce na ně navazují).
 */
final class PositionsAction
{
    public function __construct(
        private readonly PositionRepository $positions,
        private readonly PortfolioRepository $portfolios,
        private readonly AccountRepository $accounts,
        private readonly InstrumentRepository $instruments,
        private readonly TransactionService $transactions,
        private readonly PriceService $prices,
        private readonly ValuationService $valuation,
        private readonly ManualValuationRepository $valuations,
        private readonly CashBalanceService $cash,
    ) {}

    /**
     * POST /api/positions/{id}/cash-balance — nastaví zůstatek hotovosti k datu (rozdíl = vklad / výběr).
     *
     * @param array{id:string} $args
     */
    public function cashBalance(Request $request, Response $response, array $args): Response
    {
        try {
            $body = (array) ($request->getParsedBody() ?? []);
            $result = $this->cash->set(CurrentUser::owner($request), (int) $args['id'], trim((string) ($body['date'] ?? '')),
                $body['balance'] ?? null, isset($body['note']) ? trim((string) $body['note']) : null);
            $position = $this->detail(CurrentUser::owner($request), (int) $args['id']);
            return Json::ok($response, $result + ['position' => $position !== null ? self::ownerOnly($request, $position) : null]);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function list(Request $request, Response $response): Response
    {
        $userId = CurrentUser::owner($request);
        $q = $request->getQueryParams();
        $rows = $this->positions->list($userId, [
            'portfolio_id' => (int) ($q['portfolio_id'] ?? 0),
            'account_id'   => (int) ($q['account_id'] ?? 0),
            'archived'     => ($q['archived'] ?? '') === '1',
        ]);
        $last = $this->prices->lastPrices(array_values(array_map(
            static fn (array $r) => ['id' => $r['instrument_id'], 'asset_class' => $r['asset_class']],
            array_filter($rows, static fn (array $r) => $r['instrument_id'] !== null),
        )));
        $manual = $this->valuation->manualValues($userId, $rows);
        foreach ($rows as &$row) {
            $row['holding'] = $this->transactions->holding($userId, $row);
            $row['last_price'] = $row['instrument_id'] !== null ? ($last[$row['instrument_id']] ?? null) : null;
            // Ruční pozice: dnešní hodnota v měně pozice (poslední ocenění + pozdější toky).
            $row['current_value'] = $manual[$row['id']] ?? null;
            $row = self::ownerOnly($request, $row);
        }
        return Json::ok($response, $rows);
    }

    /** @param array{id:string} $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        $position = $this->detail(CurrentUser::owner($request), (int) $args['id']);
        if ($position === null) {
            return Json::domainError($response, DomainError::notFound('Pozice nenalezena.'));
        }
        return Json::ok($response, self::ownerOnly($request, $position));
    }

    /**
     * Napojení na bankovní avíza (číslo účtu) patří jen vlastníkovi — partner ho ve sdílení nevidí ani nemění,
     * jinak by přesměroval avíza vlastníka.
     *
     * @param array<string,mixed> $position
     * @return array<string,mixed>
     */
    private static function ownerOnly(Request $request, array $position): array
    {
        return CurrentUser::owner($request) === CurrentUser::id($request)
            ? $position : array_diff_key($position, ['bank_account' => true, 'bank_notices_from' => true]);
    }

    /** Pozice s drženým množstvím a poslední cenou. @return array<string,mixed>|null */
    private function detail(int $userId, int $id): ?array
    {
        $position = $this->positions->find($userId, $id);
        if ($position === null) {
            return null;
        }
        $position['holding'] = $this->transactions->holding($userId, $position);
        $position['last_price'] = $position['instrument_id'] !== null
            ? ($this->prices->lastPrices([['id' => $position['instrument_id'], 'asset_class' => $position['asset_class']]])[$position['instrument_id']] ?? null)
            : null;
        return $position;
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $body = (array) ($request->getParsedBody() ?? []);
            $errors = [];
            $mode = Input::enum($body, 'valuation_mode', ['market', 'manual'], $errors);
            $links = $this->links($userId, $body, $errors);

            $instrumentId = null;
            $currency = null;
            // Hotovost na účtu = ruční pozice se zadávaným zůstatkem; název se doplní podle měny
            $isCash = $mode === 'manual' && Input::bool($body, 'is_cash');
            $name = Input::string($body, 'name', 120, $mode === 'manual' && !$isCash, $errors);
            if ($mode === 'market') {
                $instrumentId = Input::id($body, 'instrument_id', true, $errors);
                $instrument = $instrumentId !== null ? $this->instruments->find($instrumentId) : null;
                if ($instrumentId !== null && ($instrument === null || !$instrument['is_active'])) {
                    $errors['instrument_id'][] = 'Instrument nenalezen.';
                }
                $currency = $instrument['quote_currency'] ?? null;
            } elseif ($mode === 'manual') {
                $currency = Input::currency($body, 'currency', true, $errors, $links['account']['currency'] ?? null);
            }
            $rate = $this->interestRate($body, $errors);
            Input::assertValid($errors);
            if ($isCash) {
                $name ??= 'Hotovost ' . $currency;
            }

            if ($this->positions->duplicateExists($userId, $links['portfolio_id'], $links['account_id'], $instrumentId)) {
                throw DomainError::duplicate('Tento instrument už v portfoliu na tomto účtu máš.');
            }
            $id = $this->positions->create($userId, [
                'portfolio_id'     => $links['portfolio_id'],
                'account_id'       => $links['account_id'],
                'instrument_id'    => $instrumentId,
                'valuation_mode'   => $mode,
                'is_cash'          => $isCash,
                'name'             => $name,
                'currency'         => $currency,
                'interest_rate_pa' => $rate,
            ]);
            return $this->get($request, $response->withStatus(201), ['id' => (string) $id])->withStatus(201);
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
            $existing = $this->positions->find($userId, $id) ?? throw DomainError::notFound('Pozice nenalezena.');
            $body = (array) ($request->getParsedBody() ?? []);
            $errors = [];
            $links = $this->links($userId, $body, $errors);
            $name = Input::string($body, 'name', 120, $existing['valuation_mode'] === 'manual', $errors);
            $rate = $this->interestRate($body, $errors);
            $bank = CurrentUser::owner($request) === CurrentUser::id($request) ? $this->bankLink($userId, $existing, $body, $errors) : null;
            Input::assertValid($errors);

            if ($this->positions->duplicateExists($userId, $links['portfolio_id'], $links['account_id'], $existing['instrument_id'], $id)) {
                throw DomainError::duplicate('Tento instrument už v portfoliu na tomto účtu máš.');
            }
            $this->positions->update($userId, $id, [
                'portfolio_id'     => $links['portfolio_id'],
                'account_id'       => $links['account_id'],
                'name'             => $name,
                'interest_rate_pa' => $rate,
                'archived'         => Input::bool($body, 'archived'),
            ]);
            if ($bank !== null) {
                $this->positions->setBankLink($userId, $id, $bank['account'], $bank['from']);
            }
            return $this->get($request, $response, $args);
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
            $this->positions->find($userId, $id) ?? throw DomainError::notFound('Pozice nenalezena.');
            if ($this->positions->hasTransactions($userId, $id)) {
                throw DomainError::inUse('Pozice má transakce — místo smazání ji archivuj.');
            }
            $this->positions->delete($userId, $id);
            return $response->withStatus(204);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /**
     * Napojení ruční pozice na bankovní avíza — jen když tělo pole `bank_account` obsahuje.
     * Bez data se avíza berou ode dne po posledním ručním ocenění (navazuje na dosavadní
     * data pozice), bez ocenění ode dneška.
     *
     * @param array<string,mixed> $position
     * @param array<string,mixed> $body
     * @param array<string,list<string>> $errors
     * @return array{account:?string,from:?string}|null
     */
    private function bankLink(int $userId, array $position, array $body, array &$errors): ?array
    {
        if (!array_key_exists('bank_account', $body)) {
            return null;
        }
        if ($position['valuation_mode'] !== 'manual') {
            $errors['bank_account'][] = 'Bankovní avíza jde napojit jen na ruční pozici.';
            return null;
        }
        try {
            $account = AccountNumber::normalize(is_string($body['bank_account']) ? $body['bank_account'] : null);
        } catch (\InvalidArgumentException $e) {
            $errors['bank_account'][] = $e->getMessage();
            return null;
        }
        $from = trim((string) ($body['bank_notices_from'] ?? ''));
        if ($from === '') {
            $last = $this->valuations->list($userId, (int) $position['id'])[0]['date'] ?? null;
            $from = $last !== null ? (new \DateTimeImmutable($last))->modify('+1 day')->format('Y-m-d') : date('Y-m-d');
        } elseif (\DateTimeImmutable::createFromFormat('!Y-m-d', $from)?->format('Y-m-d') !== $from) {
            $errors['bank_notices_from'][] = 'Neplatné datum.';
            return null;
        }
        return ['account' => $account, 'from' => $from];
    }

    /**
     * Portfolio a účet musí patřit přihlášenému uživateli.
     *
     * @param array<string,mixed> $body
     * @param array<string,list<string>> $errors
     * @return array{portfolio_id:int,account_id:int,account:?array<string,mixed>}
     */
    private function links(int $userId, array $body, array &$errors): array
    {
        $portfolioId = Input::id($body, 'portfolio_id', true, $errors);
        $accountId = Input::id($body, 'account_id', true, $errors);
        if ($portfolioId !== null && $this->portfolios->find($userId, $portfolioId) === null) {
            $errors['portfolio_id'][] = 'Portfolio nenalezeno.';
        }
        $account = $accountId !== null ? $this->accounts->find($userId, $accountId) : null;
        if ($accountId !== null && $account === null) {
            $errors['account_id'][] = 'Účet nenalezen.';
        }
        return ['portfolio_id' => (int) $portfolioId, 'account_id' => (int) $accountId, 'account' => $account];
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,list<string>> $errors
     */
    private function interestRate(array $body, array &$errors): ?string
    {
        try {
            $rate = Decimal::parse($body['interest_rate_pa'] ?? null, Decimal::SCALE_RATE);
        } catch (\InvalidArgumentException $e) {
            $errors['interest_rate_pa'][] = $e->getMessage();
            return null;
        }
        if ($rate !== null && ($rate->isNegative() || $rate->isGreaterThan(100))) {
            $errors['interest_rate_pa'][] = 'Úrok musí být 0–100 % p.a.';
        }
        return Decimal::toDb($rate, Decimal::SCALE_RATE);
    }
}
