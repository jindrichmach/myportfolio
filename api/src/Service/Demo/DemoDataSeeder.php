<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Demo;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\AccountRepository;
use MyPortfolio\Repository\AlertRepository;
use MyPortfolio\Repository\AllocationTargetRepository;
use MyPortfolio\Repository\InstitutionRepository;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\ManualValuationRepository;
use MyPortfolio\Repository\NetWorthRepository;
use MyPortfolio\Repository\PortfolioRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\ShareRepository;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Auth\PasswordHasher;
use MyPortfolio\Service\NetWorth\LoanSchedule;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Portfolio\CashBalanceService;
use MyPortfolio\Service\Portfolio\TransactionService;

/**
 * Ukázková data pro proklikání všech stránek: ~5 let historie relativně k dnešku —
 * krypto (nákupy, prodeje před i po časovém testu, staking, převod na hardware peněženku), ETF (měsíční nákupy,
 * prodej), akcie s dividendami a srážkovou daní (USA i ČR), Portu s ručním oceněním, spořicí účet s úroky,
 * hotovost na účtu brokera, penzijní spoření a DIP, cenové hranice, cílová alokace, benchmark a čisté jmění (byt s hypotékou,
 * auto s úvěrem). Volitelně ({@see self::seedPartner}) partner s vlastními daty a vzájemné sdílení.
 * Instrumenty jsou skutečné (ceny a kurzy stáhne backfill), částky obchodů jsou ilustrativní.
 *
 * Zakládá přes repository + TransactionService — data projdou stejnou validací jako z API.
 */
final class DemoDataSeeder
{
    private int $userId = 0;
    private \DateTimeImmutable $today;
    private int $transactions = 0;

    public function __construct(
        private readonly Connection $db,
        private readonly InstitutionRepository $institutions,
        private readonly InstrumentRepository $instruments,
        private readonly AccountRepository $accounts,
        private readonly PortfolioRepository $portfolios,
        private readonly PositionRepository $positions,
        private readonly ManualValuationRepository $valuations,
        private readonly AlertRepository $alerts,
        private readonly TransactionService $tx,
        private readonly CashBalanceService $cash,
        private readonly AllocationTargetRepository $targets,
        private readonly UserPreferenceRepository $preferences,
        private readonly NetWorthRepository $netWorth,
        private readonly ShareRepository $shares,
        private readonly PasswordHasher $hasher,
    ) {}

    /** Datum prvního záznamu — od něj je potřeba stáhnout kurzy a ceny. */
    public static function startDate(\DateTimeImmutable $today): string
    {
        return $today->modify('first day of -64 months')->format('Y-m-d');
    }

    /**
     * @return array{institutions:int,instruments:int,accounts:int,portfolios:int,positions:int,transactions:int,from:string}
     * @throws DomainError když uživatel už nějaké účty má
     */
    public function seed(int $userId, ?\DateTimeImmutable $today = null): array
    {
        $this->userId = $userId;
        $this->today = $today ?? new \DateTimeImmutable('today');
        $this->transactions = 0;
        if ($this->accounts->list($userId, false) !== []) {
            throw new DomainError('demo_not_empty', 'Uživatel už má účty — ukázková data jde založit jen do prázdného účtu.', 409);
        }

        // ── Katalog ────────────────────────────────────────────────────
        $inst = [
            'coinbase' => $this->institution('Coinbase', 'exchange', 'https://www.coinbase.com'),
            'anycoin'  => $this->institution('Anycoin', 'exchange', 'https://www.anycoin.cz'),
            'xtb'      => $this->institution('XTB', 'broker', 'https://www.xtb.com'),
            'fio'      => $this->institution('Fio banka', 'broker', 'https://www.fio.cz'),
            'portu'    => $this->institution('Portu', 'robo', 'https://www.portu.cz'),
            'trezor'   => $this->institution('Trezor', 'wallet', 'https://trezor.io'),
            'airbank'  => $this->institution('Air Bank', 'bank', 'https://www.airbank.cz'),
            'pension'  => $this->institution('NN Penzijní společnost', 'pension', 'https://www.nn.cz'),
        ];
        $ins = [
            'btc'  => $this->instrument(['asset_class' => 'crypto', 'symbol' => 'BTC', 'name' => 'Bitcoin', 'quote_currency' => 'EUR', 'price_source' => 'coingecko', 'source_refs' => ['coingecko' => 'bitcoin']]),
            'eth'  => $this->instrument(['asset_class' => 'crypto', 'symbol' => 'ETH', 'name' => 'Ethereum', 'quote_currency' => 'EUR', 'price_source' => 'coingecko', 'source_refs' => ['coingecko' => 'ethereum']]),
            'ada'  => $this->instrument(['asset_class' => 'crypto', 'symbol' => 'ADA', 'name' => 'Cardano', 'quote_currency' => 'EUR', 'price_source' => 'coingecko', 'source_refs' => ['coingecko' => 'cardano']]),
            'sol'  => $this->instrument(['asset_class' => 'crypto', 'symbol' => 'SOL', 'name' => 'Solana', 'quote_currency' => 'EUR', 'price_source' => 'coingecko', 'source_refs' => ['coingecko' => 'solana']]),
            'cspx' => $this->instrument(['asset_class' => 'etf', 'symbol' => 'CSPX', 'name' => 'iShares Core S&P 500 UCITS ETF (Acc)', 'isin' => 'IE00B5BMR087', 'quote_currency' => 'USD', 'decimals' => 4, 'price_source' => 'xtb', 'source_refs' => ['xtb' => 'CSPX.UK', 'yahoo' => 'CSPX.L']]),
            'vwce' => $this->instrument(['asset_class' => 'etf', 'symbol' => 'VWCE', 'name' => 'Vanguard FTSE All-World UCITS ETF (Acc)', 'isin' => 'IE00BK5BQT80', 'quote_currency' => 'EUR', 'decimals' => 4, 'price_source' => 'xtb', 'source_refs' => ['xtb' => 'VWCE.DE', 'yahoo' => 'VWCE.DE']]),
            'ko'   => $this->instrument(['asset_class' => 'stock', 'symbol' => 'KO', 'name' => 'The Coca-Cola Company', 'isin' => 'US1912161007', 'quote_currency' => 'USD', 'decimals' => 4, 'price_source' => 'yahoo', 'source_refs' => ['yahoo' => 'KO']]),
            'cez'  => $this->instrument(['asset_class' => 'stock', 'symbol' => 'CEZ', 'name' => 'ČEZ, a. s.', 'isin' => 'CZ0005112300', 'quote_currency' => 'CZK', 'decimals' => 4, 'price_source' => 'yahoo', 'source_refs' => ['yahoo' => 'CEZ.PR']]),
        ];

        // ── Účty, portfolia, pozice ────────────────────────────────────
        $acc = [];
        foreach ([
            'coinbase' => ['coinbase', 'Hlavní', 'EUR', null], 'anycoin' => ['anycoin', 'Hlavní', 'CZK', null],
            'xtb' => ['xtb', 'Investiční účet', 'CZK', null], 'fio' => ['fio', 'e-Broker', 'CZK', null],
            'portu' => ['portu', 'Portfolio', 'CZK', null], 'trezor' => ['trezor', 'Model T', 'EUR', 'Cold storage'],
            'airbank' => ['airbank', 'Spořicí účet', 'CZK', null],
            'dps' => ['pension', 'Doplňkové penzijní spoření', 'CZK', null], 'dip' => ['xtb', 'DIP', 'CZK', 'Dlouhodobý investiční produkt'],
        ] as $key => [$institution, $name, $currency, $note]) {
            $acc[$key] = $this->accounts->create($this->userId, ['institution_id' => $inst[$institution], 'name' => $name, 'currency' => $currency, 'note' => $note]);
        }
        $pf = [];
        foreach (['long' => 'Dlouhodobé', 'div' => 'Dividendové', 'spec' => 'Spekulace', 'cash' => 'Hotovost', 'pension' => 'Penze'] as $key => $name) {
            $pf[$key] = $this->portfolios->create($this->userId, ['name' => $name, 'sort' => count($pf) * 10]);
        }
        $market = fn (string $portfolio, string $account, string $instrument, string $currency) => $this->positions->create($this->userId, [
            'portfolio_id' => $pf[$portfolio], 'account_id' => $acc[$account], 'instrument_id' => $ins[$instrument],
            'valuation_mode' => 'market', 'name' => null, 'currency' => $currency, 'interest_rate_pa' => null,
        ]);
        $manual = fn (string $portfolio, string $account, string $name, ?string $rate) => $this->positions->create($this->userId, [
            'portfolio_id' => $pf[$portfolio], 'account_id' => $acc[$account], 'instrument_id' => null,
            'valuation_mode' => 'manual', 'name' => $name, 'currency' => 'CZK', 'interest_rate_pa' => $rate,
        ]);
        $pos = [
            'btc_cb' => $market('long', 'coinbase', 'btc', 'EUR'), 'btc_tr' => $market('long', 'trezor', 'btc', 'EUR'),
            'eth_cb' => $market('long', 'coinbase', 'eth', 'EUR'), 'ada_cb' => $market('spec', 'coinbase', 'ada', 'EUR'),
            'sol_any' => $market('spec', 'anycoin', 'sol', 'EUR'),
            'cspx' => $market('long', 'xtb', 'cspx', 'USD'), 'vwce' => $market('long', 'xtb', 'vwce', 'EUR'),
            'ko' => $market('div', 'xtb', 'ko', 'USD'), 'cez' => $market('div', 'fio', 'cez', 'CZK'),
            'portu' => $manual('long', 'portu', 'Portu — Vyvážená strategie', null),
            'savings' => $manual('cash', 'airbank', 'Air Bank spořicí účet', '3.5000'),
            'xtb_cash' => $this->positions->create($this->userId, ['portfolio_id' => $pf['cash'], 'account_id' => $acc['xtb'], 'instrument_id' => null,
                'valuation_mode' => 'manual', 'is_cash' => true, 'name' => 'Hotovost CZK', 'currency' => 'CZK', 'interest_rate_pa' => null]),
            'dps' => $manual('pension', 'dps', 'DPS — Vyvážený účastnický fond', null),
            'dip' => $market('pension', 'dip', 'vwce', 'EUR'),
        ];

        $this->crypto($pos);
        $this->etf($pos);
        $this->dividends($pos);
        $this->cash($pos);
        $this->pension($pos);

        // Cílová alokace celku (ETF rozepsané po instrumentech) a benchmark v grafu vývoje
        $this->targets->replace($this->userId, null, [
            ['category' => 'crypto', 'instrument_id' => null, 'target_pct' => '25', 'band_pct' => '5'],
            ['category' => 'etf', 'instrument_id' => null, 'target_pct' => '45', 'band_pct' => '5'],
            ['category' => 'etf', 'instrument_id' => $ins['vwce'], 'target_pct' => '60', 'band_pct' => null],
            ['category' => 'etf', 'instrument_id' => $ins['cspx'], 'target_pct' => '40', 'band_pct' => null],
            ['category' => 'stock', 'instrument_id' => null, 'target_pct' => '10', 'band_pct' => '3'],
            ['category' => 'fund', 'instrument_id' => null, 'target_pct' => '10', 'band_pct' => '5'],
            ['category' => 'cash', 'instrument_id' => null, 'target_pct' => '10', 'band_pct' => '5'],
        ]);
        $this->preferences->setBenchmark($this->userId, $ins['vwce']); // světový index — srovnání celku s trhem
        $this->netWorth();

        $this->alerts->createPriceAlert($this->userId, ['instrument_id' => $ins['btc'], 'direction' => 'above', 'price' => '150000', 'currency' => 'EUR', 'note' => 'demo']);
        $this->alerts->createPriceAlert($this->userId, ['instrument_id' => $ins['vwce'], 'direction' => 'below', 'price' => '90', 'currency' => 'EUR', 'note' => 'demo']);

        return ['institutions' => count($inst), 'instruments' => count($ins), 'accounts' => count($acc), 'portfolios' => count($pf),
            'positions' => count($pos), 'transactions' => $this->transactions, 'from' => self::startDate($this->today)];
    }

    /** @param array<string,int> $pos */
    private function crypto(array $pos): void
    {
        // BTC — nákupy, převod na Trezor (datum nabytí zůstává), prodej zdanitelný i po časovém testu
        $this->add($pos['btc_cb'], ['type' => 'buy', 'executed_at' => $this->at(64, 15), 'quantity' => '0.05', 'amount' => '2450', 'fee' => '14.70']);
        $this->add($pos['btc_cb'], ['type' => 'buy', 'executed_at' => $this->at(50, 20), 'quantity' => '0.08', 'amount' => '1568', 'fee' => '9.41']);
        $this->add($pos['btc_cb'], ['type' => 'buy', 'executed_at' => $this->at(44, 10), 'quantity' => '0.06', 'amount' => '1020', 'fee' => '6.12']);
        $this->tx->transfer($this->userId, ['from_position_id' => $pos['btc_cb'], 'to_position_id' => $pos['btc_tr'], 'quantity' => '0.1',
            'executed_at' => $this->at(42, 1), 'fee' => '0.00005', 'fee_currency' => 'BTC', 'note' => 'demo']);
        $this->transactions += 2;
        $this->add($pos['btc_cb'], ['type' => 'sell', 'executed_at' => $this->at(20, 12), 'quantity' => '0.02', 'amount' => '1300', 'fee' => '7.80']);
        $this->add($pos['btc_cb'], ['type' => 'sell', 'executed_at' => $this->at(3, 8), 'quantity' => '0.03', 'amount' => '2700', 'fee' => '16.20']);

        // ETH — nákupy, čtvrtletní staking, částečný prodej
        $this->add($pos['eth_cb'], ['type' => 'buy', 'executed_at' => $this->at(62, 2), 'quantity' => '1.2', 'amount' => '3360', 'fee' => '20.16']);
        $this->add($pos['eth_cb'], ['type' => 'buy', 'executed_at' => $this->at(46, 28), 'quantity' => '0.8', 'amount' => '1032', 'fee' => '6.19']);
        for ($m = 36, $i = 0; $m >= 1; $m -= 3, $i++) {
            $this->add($pos['eth_cb'], ['type' => 'reward', 'executed_at' => $this->at($m, 28), 'quantity' => (string) BigDecimal::of('0.004')->plus(BigDecimal::of('0.0003')->multipliedBy($i)), 'amount' => (string) (8 + $i)]);
        }
        $this->add($pos['eth_cb'], ['type' => 'sell', 'executed_at' => $this->at(8, 17), 'quantity' => '0.5', 'amount' => '1500', 'fee' => '9']);

        // ADA — nákup a měsíční staking (drobné loty)
        $this->add($pos['ada_cb'], ['type' => 'buy', 'executed_at' => $this->at(34, 5), 'quantity' => '1000', 'amount' => '350', 'fee' => '2.10']);
        for ($m = 30; $m >= 1; $m--) {
            $this->add($pos['ada_cb'], ['type' => 'reward', 'executed_at' => $this->at($m, 25), 'quantity' => '2.4', 'amount' => (string) BigDecimal::of('0.6')->plus(BigDecimal::of('0.05')->multipliedBy($m % 7))]);
        }

        // SOL — Anycoin, placeno v CZK
        $this->add($pos['sol_any'], ['type' => 'buy', 'executed_at' => $this->at(30, 5), 'quantity' => '25', 'amount' => '12500', 'price_currency' => 'CZK', 'fee' => '125']);
        $this->add($pos['sol_any'], ['type' => 'buy', 'executed_at' => $this->at(26, 18), 'quantity' => '10', 'amount' => '22800', 'price_currency' => 'CZK', 'fee' => '228']);
        $this->add($pos['sol_any'], ['type' => 'sell', 'executed_at' => $this->at(18, 22), 'quantity' => '15', 'amount' => '84000', 'price_currency' => 'CZK', 'fee' => '840']);
        $this->add($pos['sol_any'], ['type' => 'sell', 'executed_at' => $this->at(2, 11), 'quantity' => '10', 'amount' => '52000', 'price_currency' => 'CZK', 'fee' => '520']);
    }

    /** @param array<string,int> $pos */
    private function etf(array $pos): void
    {
        // Měsíční nákupy (DCA) posledních 40 měsíců, prodej části nejstarších kusů po 3 letech
        for ($m = 40, $i = 0; $m >= 1; $m--, $i++) {
            $this->add($pos['cspx'], ['type' => 'buy', 'executed_at' => $this->at($m, 5, '15:35'), 'quantity' => '1', 'unit_price' => (string) (420 + $i * 5)]);
            $this->add($pos['vwce'], ['type' => 'buy', 'executed_at' => $this->at($m, 5, '09:05'), 'quantity' => '3', 'unit_price' => (string) BigDecimal::of('95')->plus(BigDecimal::of('1.1')->multipliedBy($i))]);
        }
        $this->add($pos['cspx'], ['type' => 'sell', 'executed_at' => $this->at(1, 20, '16:10'), 'quantity' => '3', 'unit_price' => '640']);
        foreach ([36, 24, 12] as $m) {
            $this->add($pos['vwce'], ['type' => 'fee', 'executed_at' => $this->at($m, 28), 'amount' => '2.50', 'note' => 'demo — poplatek za vedení']);
        }
    }

    /** @param array<string,int> $pos */
    private function dividends(array $pos): void
    {
        // Coca-Cola (USA) — čtvrtletní dividenda, srážková daň 15 %
        $this->add($pos['ko'], ['type' => 'buy', 'executed_at' => $this->at(48, 12, '15:40'), 'quantity' => '20', 'unit_price' => '58']);
        $this->add($pos['ko'], ['type' => 'buy', 'executed_at' => $this->at(24, 12, '15:40'), 'quantity' => '10', 'unit_price' => '60']);
        for ($m = 46; $m >= 1; $m -= 3) {
            $gross = BigDecimal::of('0.46')->multipliedBy($m > 24 ? 20 : 30);
            $this->add($pos['ko'], ['type' => 'dividend', 'executed_at' => $this->at($m, 15), 'amount' => (string) $gross,
                'fee' => (string) self::pct15($gross), 'fee_currency' => 'USD']);
        }
        // ČEZ — roční dividenda v CZK, daň sražená u zdroje
        $this->add($pos['cez'], ['type' => 'buy', 'executed_at' => $this->at(40, 3, '10:00'), 'quantity' => '100', 'unit_price' => '900']);
        foreach ([33, 21, 9] as $m) {
            $this->add($pos['cez'], ['type' => 'dividend', 'executed_at' => $this->at($m, 20), 'amount' => '5200', 'fee' => '780', 'fee_currency' => 'CZK']);
        }
    }

    /** @param array<string,int> $pos */
    private function cash(array $pos): void
    {
        // Hotovost na XTB — občas zadaný zůstatek (rozdíl = vklad / výběr)
        foreach ([36 => '20000', 24 => '12000', 12 => '15500', 1 => '8300'] as $m => $balance) {
            $r = $this->cash->set($this->userId, $pos['xtb_cash'], $this->at($m, 0, null), $balance, 'demo');
            $this->transactions += $r['transaction_id'] !== null ? 1 : 0;
        }

        // Portu — měsíční vklady, jeden výběr, pololetní ruční ocenění
        $value = BigDecimal::zero();
        for ($m = 36; $m >= 1; $m--) {
            $this->add($pos['portu'], ['type' => 'deposit', 'executed_at' => $this->at($m, 10), 'amount' => '3000']);
            $value = $value->plus(3000)->multipliedBy('1.006');
            if ($m === 14) {
                $this->add($pos['portu'], ['type' => 'withdrawal', 'executed_at' => $this->at($m, 15), 'amount' => '10000']);
                $value = $value->minus(10000);
            }
            if ($m % 6 === 0) {
                $this->valuations->upsert($this->userId, $pos['portu'], $this->at($m, 28, null),
                    (string) $value->toScale(0, RoundingMode::HalfUp), 'CZK', 'demo');
            }
        }
        // Spořicí účet — vklady, výběr, měsíční úroky se srážkovou daní 15 %
        $this->add($pos['savings'], ['type' => 'deposit', 'executed_at' => $this->at(36, 1), 'amount' => '150000']);
        $this->add($pos['savings'], ['type' => 'deposit', 'executed_at' => $this->at(24, 15), 'amount' => '50000']);
        $this->add($pos['savings'], ['type' => 'withdrawal', 'executed_at' => $this->at(12, 20), 'amount' => '40000']);
        for ($m = 35; $m >= 1; $m--) {
            $balance = $m > 24 ? 150000 : ($m > 12 ? 200000 : 160000);
            $interest = BigDecimal::of($balance)->multipliedBy('0.035')->dividedBy(12, 2, RoundingMode::HalfUp);
            $this->add($pos['savings'], ['type' => 'interest', 'executed_at' => $this->at($m, 0), 'amount' => (string) $interest,
                'fee' => (string) self::pct15($interest), 'fee_currency' => 'CZK']);
        }
    }

    /** Srážková daň 15 % na haléře. */
    /**
     * Penzijko: vlastní vklady 1 700 Kč měsíčně, čtvrtletní ocenění podle výpisu (vklady + státní příspěvek 340 Kč
     * + zaměstnavatel 1 000 Kč + zhodnocení) — příspěvky se tak projeví jako výnos. DIP u XTB: měsíční nákupy VWCE.
     *
     * @param array<string,int> $pos
     */
    private function pension(array $pos): void
    {
        $balance = BigDecimal::zero();
        for ($m = 60; $m >= 0; $m--) {
            $this->add($pos['dps'], ['type' => 'deposit', 'executed_at' => $this->at($m, 10), 'amount' => '1700']);
            $balance = $balance->multipliedBy('1.0035')->plus(1700 + 340 + 1000);
            if ($m % 3 === 0) {
                $this->valuations->upsert($this->userId, $pos['dps'], $this->at($m, 0, null), (string) $balance->toScale(2, RoundingMode::HalfUp), 'CZK', 'demo — výpis');
            }
        }
        for ($m = 36, $i = 0; $m >= 0; $m--, $i++) {
            $this->add($pos['dip'], ['type' => 'buy', 'executed_at' => $this->at($m, 12, '09:10'), 'quantity' => '2',
                'unit_price' => (string) BigDecimal::of('104')->plus(BigDecimal::of('0.9')->multipliedBy($i))]);
        }
    }

    /** Čisté jmění: byt s hypotékou (refixace, mimořádná splátka), auto s úvěrem (doplacený). */
    private function netWorth(): void
    {
        $flat = $this->netWorth->createItem($this->userId, ['kind' => 'real_estate', 'name' => 'Byt 2+kk Brno', 'currency' => 'CZK', 'note' => 'demo']);
        foreach ([52 => '4200000', 40 => '4350000', 28 => '4600000', 16 => '4950000', 4 => '5250000'] as $m => $value) {
            $this->netWorth->saveEntry($this->userId, $flat, $this->at($m, 15, null), $value, null, null, 'demo — odhad');
        }
        $mortgage = $this->netWorth->createItem($this->userId, ['kind' => 'liability', 'name' => 'Hypotéka', 'currency' => 'CZK', 'note' => 'demo']);
        $entries = [['date' => $this->at(52, 15, null), 'value' => '3360000', 'rate_pct' => '2.49', 'payment' => '13270']];
        $refix = $this->at(16, 15, null);
        $entries[] = ['date' => $refix, 'value' => (string) (new LoanSchedule($entries))->balanceOn($refix), 'rate_pct' => '4.89', 'payment' => '16900'];
        $extra = $this->at(6, 20, null);
        $entries[] = ['date' => $extra, 'value' => (string) (new LoanSchedule($entries))->balanceOn($extra)->minus(200000), 'rate_pct' => null, 'payment' => null];
        foreach ($entries as $i => $e) {
            $this->netWorth->saveEntry($this->userId, $mortgage, $e['date'], $e['value'], $e['rate_pct'], $e['payment'],
                ['demo — čerpání', 'demo — refixace', 'demo — mimořádná splátka 200 000 Kč'][$i]);
        }
        $car = $this->netWorth->createItem($this->userId, ['kind' => 'other', 'name' => 'Škoda Octavia Combi', 'currency' => 'CZK', 'note' => 'demo']);
        foreach ([40 => '690000', 28 => '600000', 16 => '530000', 4 => '470000'] as $m => $value) {
            $this->netWorth->saveEntry($this->userId, $car, $this->at($m, 10, null), $value, null, null, 'demo — odhad');
        }
        $carLoan = $this->netWorth->createItem($this->userId, ['kind' => 'liability', 'name' => 'Úvěr na auto', 'currency' => 'CZK', 'note' => 'demo']);
        $this->netWorth->saveEntry($this->userId, $carLoan, $this->at(40, 10, null), '350000', '6.9', '10790', 'demo — 36 splátek');
    }

    /**
     * Partner pro ukázku sdílení a domácnosti: nový uživatel s náhodným heslem, vlastní spořák a ETF, vzájemné sdílení
     * (vlastník → partner jen čtení s čistým jměním; partner → vlastník čtení i zápis do svého portfolia).
     *
     * @return array{email:string,password:string,user_id:int}
     */
    public function seedPartner(int $ownerId, string $email, ?\DateTimeImmutable $today = null): array
    {
        $this->today = $today ?? new \DateTimeImmutable('today');
        $password = rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'Xy'), '=') . '-7a';
        $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role, locale, display_currency, is_active)
             SELECT ?, ?, 'Partner (demo)', 'user', locale, display_currency, 1 FROM users WHERE id = ?"
        )->execute([$email, $this->hasher->hash($password), $ownerId]);
        $partnerId = (int) $this->db->pdo()->lastInsertId();

        $this->userId = $partnerId;
        $airbank = $this->institution('Air Bank', 'bank', 'https://www.airbank.cz');
        $xtb = $this->institution('XTB', 'broker', 'https://www.xtb.com');
        $cspx = $this->instrument(['asset_class' => 'etf', 'symbol' => 'CSPX', 'name' => 'iShares Core S&P 500 UCITS ETF (Acc)', 'isin' => 'IE00B5BMR087', 'quote_currency' => 'USD', 'decimals' => 4, 'price_source' => 'xtb', 'source_refs' => ['xtb' => 'CSPX.UK', 'yahoo' => 'CSPX.L']]);
        $pf = $this->portfolios->create($partnerId, ['name' => 'Moje investice', 'sort' => 0]);
        $savingsAcc = $this->accounts->create($partnerId, ['institution_id' => $airbank, 'name' => 'Spořicí účet', 'currency' => 'CZK', 'note' => null]);
        $xtbAcc = $this->accounts->create($partnerId, ['institution_id' => $xtb, 'name' => 'Investiční účet', 'currency' => 'CZK', 'note' => null]);
        $savings = $this->positions->create($partnerId, ['portfolio_id' => $pf, 'account_id' => $savingsAcc, 'instrument_id' => null,
            'valuation_mode' => 'manual', 'name' => 'Air Bank spořicí účet', 'currency' => 'CZK', 'interest_rate_pa' => '3.2000']);
        $etf = $this->positions->create($partnerId, ['portfolio_id' => $pf, 'account_id' => $xtbAcc, 'instrument_id' => $cspx,
            'valuation_mode' => 'market', 'name' => null, 'currency' => 'USD', 'interest_rate_pa' => null]);
        $this->add($savings, ['type' => 'deposit', 'executed_at' => $this->at(24, 2), 'amount' => '120000']);
        for ($m = 24, $i = 0; $m >= 0; $m--, $i++) {
            $this->add($etf, ['type' => 'buy', 'executed_at' => $this->at($m, 8, '15:35'), 'quantity' => '1', 'unit_price' => (string) (480 + $i * 6)]);
        }

        $this->shares->create(['owner' => $ownerId, 'grantee' => $partnerId, 'email' => $email, 'token_hash' => null, 'expires_at' => null,
            'permission' => 'read', 'scope' => 'all', 'sections' => ['net_worth', 'income'], 'portfolios' => []]);
        $ownerEmail = (string) $this->db->pdo()->query('SELECT email FROM users WHERE id = ' . $ownerId)->fetchColumn();
        $this->shares->create(['owner' => $partnerId, 'grantee' => $ownerId, 'email' => mb_strtolower($ownerEmail), 'token_hash' => null, 'expires_at' => null,
            'permission' => 'write', 'scope' => 'portfolios', 'sections' => [], 'portfolios' => [$pf]]);
        return ['email' => $email, 'password' => $password, 'user_id' => $partnerId];
    }

    private static function pct15(BigDecimal $amount): BigDecimal
    {
        return $amount->multipliedBy('0.15')->toScale(2, RoundingMode::HalfUp);
    }

    /** @param array<string,mixed> $data */
    private function add(int $positionId, array $data): void
    {
        $this->tx->create($this->userId, $positionId, $data + ['note' => 'demo']);
        $this->transactions++;
    }

    /** Datum `$monthsAgo` měsíců zpět, den v měsíci (0 = poslední), nikdy v budoucnosti. */
    private function at(int $monthsAgo, int $day, ?string $time = '10:00'): string
    {
        $month = $this->today->modify("first day of -$monthsAgo months");
        $last = (int) $month->format('t');
        $date = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day === 0 ? $last : min($day, $last));
        if ($date > $this->today) {
            $date = $this->today;
        }
        return $date->format('Y-m-d') . ($time !== null ? " $time" : '');
    }

    private function institution(string $name, string $type, string $website): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM institutions WHERE name = ?');
        $stmt->execute([$name]);
        return (int) ($stmt->fetchColumn() ?: $this->institutions->create(['name' => $name, 'type' => $type, 'website' => $website], $this->userId));
    }

    /** @param array<string,mixed> $d */
    private function instrument(array $d): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM instruments WHERE asset_class = ? AND symbol = ? AND quote_currency = ?');
        $stmt->execute([$d['asset_class'], $d['symbol'], $d['quote_currency']]);
        return (int) ($stmt->fetchColumn() ?: $this->instruments->create($d + ['isin' => null, 'decimals' => 8], $this->userId));
    }
}
