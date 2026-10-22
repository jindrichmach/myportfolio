<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Settings;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\AlertRepository;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Alert\AlertService;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Support\Decimal;
use MyPortfolio\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * E-mailová upozornění:
 *   GET|PUT /api/settings/alerts        — zapnutí, příjemci, prahy
 *   POST    /api/settings/alerts/send   — vyhodnotit a poslat hned (jen když je co hlásit)
 *   GET|POST /api/price-alerts, PUT|DELETE /api/price-alerts/{id} — cenové hranice (úprava hranici znovu aktivuje)
 */
final class AlertsAction
{
    private const MAX_RECIPIENTS = 5;
    private const MAX_PRICE_ALERTS = 100;

    public function __construct(
        private readonly UserPreferenceRepository $preferences,
        private readonly AlertRepository $alerts,
        private readonly InstrumentRepository $instruments,
        private readonly AlertService $service,
        private readonly LoggerInterface $logger,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->settings(CurrentUser::id($request)));
    }

    public function update(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::id($request);
            $body = (array) ($request->getParsedBody() ?? []);
            $errors = [];
            $settings = [
                'enabled'            => Input::bool($body, 'enabled'),
                'recipients'         => $this->recipients($body['recipients'] ?? [], $errors),
                'move_portfolio_pct' => self::pct($body, 'move_portfolio_pct', $errors),
                'move_position_pct'  => self::pct($body, 'move_position_pct', $errors),
                'holding_days'       => self::days($body, $errors),
                'data_issues'        => Input::bool($body, 'data_issues', true),
                'allocation'         => Input::bool($body, 'allocation', true),
            ];
            Input::assertValid($errors);
            $this->preferences->setAlerts($userId, $settings);
            return Json::ok($response, $this->settings($userId));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function send(Request $request, Response $response): Response
    {
        $userId = CurrentUser::id($request);
        try {
            return Json::ok($response, $this->service->run($userId));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        } catch (\Throwable $e) {
            $this->logger->error('alerts.send_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return Json::error($response, 'mail_failed', 'E-mail se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }
    }

    public function priceAlerts(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->alerts->priceAlerts(CurrentUser::id($request)));
    }

    public function createPriceAlert(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::id($request);
            if (count($this->alerts->priceAlerts($userId)) >= self::MAX_PRICE_ALERTS) {
                throw DomainError::validation(['instrument_id' => ['Nejvýše ' . self::MAX_PRICE_ALERTS . ' cenových hranic.']]);
            }
            $id = $this->alerts->createPriceAlert($userId, $this->priceInput((array) ($request->getParsedBody() ?? [])));
            return Json::ok($response, $this->alerts->findPriceAlert($userId, $id), 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function updatePriceAlert(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::id($request);
            $id = (int) $args['id'];
            $this->alerts->findPriceAlert($userId, $id) ?? throw DomainError::notFound();
            $this->alerts->updatePriceAlert($userId, $id, $this->priceInput((array) ($request->getParsedBody() ?? [])));
            return Json::ok($response, $this->alerts->findPriceAlert($userId, $id));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function deletePriceAlert(Request $request, Response $response, array $args): Response
    {
        $userId = CurrentUser::id($request);
        if ($this->alerts->findPriceAlert($userId, (int) $args['id']) === null) {
            return Json::domainError($response, DomainError::notFound());
        }
        $this->alerts->deletePriceAlert($userId, (int) $args['id']);
        return $response->withStatus(204);
    }

    /** @return array<string,mixed> */
    private function settings(int $userId): array
    {
        $s = $this->preferences->alerts($userId) ?? [];
        return [
            'enabled'            => $s['enabled'] ?? false,
            'recipients'         => $s['recipients'] ?? [],
            'move_portfolio_pct' => $s['move_portfolio_pct'] ?? null,
            'move_position_pct'  => $s['move_position_pct'] ?? null,
            'holding_days'       => $s['holding_days'] ?? null,
            'data_issues'        => $s['data_issues'] ?? true,
            'allocation'         => $s['allocation'] ?? true,
            'sent_at'            => $s['sent_at'] ?? null,
            'email'              => $s['email'] ?? '',
            'price_alerts'       => $this->alerts->priceAlerts($userId),
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{instrument_id:int,direction:string,price:string,currency:string,note:?string}
     */
    private function priceInput(array $body): array
    {
        $errors = [];
        $instrumentId = Input::id($body, 'instrument_id', true, $errors);
        $instrument = $instrumentId !== null ? $this->instruments->find($instrumentId) : null;
        if ($instrumentId !== null && $instrument === null) {
            $errors['instrument_id'][] = 'Instrument nenalezen.';
        }
        $direction = Input::enum($body, 'direction', ['above', 'below'], $errors);
        try {
            $price = Decimal::parse($body['price'] ?? null, Decimal::SCALE_PRICE);
            if ($price === null || !$price->isPositive()) {
                $errors['price'][] = 'Zadej kladnou cenu.';
            }
        } catch (\InvalidArgumentException $e) {
            $price = null;
            $errors['price'][] = $e->getMessage();
        }
        $currency = Input::currency($body, 'currency', false, $errors, $instrument['quote_currency'] ?? null);
        $note = Input::string($body, 'note', 255, false, $errors);
        Input::assertValid($errors);
        return ['instrument_id' => (int) $instrumentId, 'direction' => (string) $direction, 'price' => (string) $price,
            'currency' => (string) $currency, 'note' => $note];
    }

    /**
     * @param array<string,list<string>> $errors
     * @return list<string>
     */
    private function recipients(mixed $raw, array &$errors): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : (preg_split('/[\s,;]+/', (string) $raw) ?: []) as $email) {
            $email = strtolower(trim((string) $email));
            if ($email === '') {
                continue;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
                $errors['recipients'][] = "Neplatný e-mail: $email";
            }
            $out[$email] = $email;
        }
        if (count($out) > self::MAX_RECIPIENTS) {
            $errors['recipients'][] = 'Nejvýše ' . self::MAX_RECIPIENTS . ' příjemců.';
        }
        return array_values($out);
    }

    /**
     * Práh v procentech (0,1–100), prázdné = vypnuto.
     *
     * @param array<string,mixed> $body
     * @param array<string,list<string>> $errors
     */
    private static function pct(array $body, string $key, array &$errors): ?string
    {
        try {
            $v = Decimal::parse($body[$key] ?? null, 2);
        } catch (\InvalidArgumentException $e) {
            $errors[$key][] = $e->getMessage();
            return null;
        }
        if ($v !== null && ($v->isLessThan('0.1') || $v->isGreaterThan(100))) {
            $errors[$key][] = 'Práh musí být 0,1–100 %.';
        }
        return $v !== null ? (string) $v : null;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,list<string>> $errors
     */
    private static function days(array $body, array &$errors): ?int
    {
        $v = $body['holding_days'] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v) || (int) $v < 1 || (int) $v > 365) {
            $errors['holding_days'][] = 'Počet dní musí být 1–365.';
            return null;
        }
        return (int) $v;
    }
}
