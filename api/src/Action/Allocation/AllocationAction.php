<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Allocation;

use Brick\Math\BigDecimal;
use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\AllocationTargetRepository;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\PortfolioRepository;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Allocation\RebalanceService;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Support\Decimal;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Cílová alokace, v měně zobrazení:
 *   GET /api/allocation?portfolio_id=&deposit=  — skutečnost vs. cíl, návrh vkladu a plný rebalancing
 *   PUT /api/allocation/targets                 — uložit celou sadu cílů (prázdné classes = smazat)
 */
final class AllocationAction
{
    public function __construct(
        private readonly RebalanceService $service,
        private readonly AllocationTargetRepository $targets,
        private readonly PortfolioRepository $portfolios,
        private readonly InstrumentRepository $instruments,
        private readonly UserPreferenceRepository $preferences,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $q = $request->getQueryParams();
            $portfolioId = $this->portfolio($userId, $q['portfolio_id'] ?? null);
            $deposit = null;
            if (($q['deposit'] ?? '') !== '') {
                $deposit = self::decimal($q['deposit']);
                if ($deposit === null || $deposit->isNegative()) {
                    throw DomainError::validation(['deposit' => ['Zadej kladnou částku.']]);
                }
            }
            return Json::ok($response, $this->service->compute($userId, $this->preferences->displayCurrency(CurrentUser::id($request)), $portfolioId, $deposit));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function save(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $body = (array) ($request->getParsedBody() ?? []);
            $portfolioId = $this->portfolio($userId, $body['portfolio_id'] ?? null);
            $this->targets->replace($userId, $portfolioId, $this->rows(is_array($body['classes'] ?? null) ? $body['classes'] : []));
            return Json::ok($response, $this->service->compute($userId, $this->preferences->displayCurrency(CurrentUser::id($request)), $portfolioId));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    private function portfolio(int $userId, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $this->portfolios->find($userId, $id) === null) {
            throw DomainError::notFound('Portfolio nenalezeno.');
        }
        return $id;
    }

    /**
     * Validace sady: třídy v součtu 100 %, instrumenty dané třídy v součtu 100 %, tolerance 0–50 p. b.
     *
     * @param array<mixed> $classes
     * @return list<array{category:string,instrument_id:?int,target_pct:string,band_pct:?string}>
     */
    private function rows(array $classes): array
    {
        $errors = [];
        $rows = [];
        $sum = BigDecimal::zero();
        $seen = [];
        foreach (array_values($classes) as $i => $c) {
            $c = is_array($c) ? $c : [];
            $category = (string) ($c['category'] ?? '');
            if (!in_array($category, RebalanceService::CATEGORIES, true) || isset($seen[$category])) {
                $errors["classes.$i.category"][] = 'Neznámá nebo opakovaná třída.';
                continue;
            }
            $seen[$category] = true;
            $target = self::pct($c['target_pct'] ?? null, '100');
            $hasBand = ($c['band_pct'] ?? null) !== null && $c['band_pct'] !== '';
            $band = $hasBand ? self::pct($c['band_pct'], '50') : null;
            if ($target === null) {
                $errors["classes.$i.target_pct"][] = 'Zadej 0–100 %.';
                continue;
            }
            if ($hasBand && $band === null) {
                $errors["classes.$i.band_pct"][] = 'Zadej 0–50 procentních bodů.';
            }
            $sum = $sum->plus($target);
            $rows[] = ['category' => $category, 'instrument_id' => null, 'target_pct' => (string) $target, 'band_pct' => $band !== null ? (string) $band : null];

            $instruments = is_array($c['instruments'] ?? null) ? array_values($c['instruments']) : [];
            if ($instruments === []) {
                continue;
            }
            $inner = BigDecimal::zero();
            $ids = [];
            foreach ($instruments as $j => $it) {
                $it = is_array($it) ? $it : [];
                $id = filter_var($it['instrument_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $instrument = $id !== false ? $this->instruments->find($id) : null;
                $pct = self::pct($it['target_pct'] ?? null, '100');
                if ($instrument === null || $instrument['asset_class'] !== $category || isset($ids[$id])) {
                    $errors["classes.$i.instruments.$j.instrument_id"][] = 'Instrument nepatří do třídy nebo se opakuje.';
                    continue;
                }
                if ($pct === null) {
                    $errors["classes.$i.instruments.$j.target_pct"][] = 'Zadej 0–100 %.';
                    continue;
                }
                $ids[$id] = true;
                $inner = $inner->plus($pct);
                $rows[] = ['category' => $category, 'instrument_id' => $id, 'target_pct' => (string) $pct, 'band_pct' => null];
            }
            if (!$inner->isEqualTo(100)) {
                $errors["classes.$i.instruments"][] = 'Podíly instrumentů ve třídě musí dát dohromady 100 %.';
            }
        }
        if ($rows !== [] && !$sum->isEqualTo(100)) {
            $errors['classes'][] = 'Cíle tříd musí dát dohromady 100 % (teď ' . $sum->strippedOfTrailingZeros() . ' %).';
        }
        if ($errors !== []) {
            throw DomainError::validation($errors);
        }
        return $rows;
    }

    private static function decimal(mixed $value): ?BigDecimal
    {
        try {
            return Decimal::parse($value, 2);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function pct(mixed $value, string $max): ?BigDecimal
    {
        $v = self::decimal($value);
        return $v !== null && !$v->isNegative() && $v->isLessThanOrEqualTo($max) ? $v->toScale(2) : null;
    }
}
