<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Alert;

use DI\Container;
use MyPortfolio\Service\Mail\Mailer;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Denní souhrn upozornění na syntetických datech: cenová hranice, včerejší pohyb +20 %, lot, který za 10 dní
 * splní časový test, držený instrument bez ceny. Druhé spuštění nepošle nic (vše už ohlášeno).
 */
#[Group('integration')]
final class AlertsTest extends ApiTestCase
{
    /** @var list<array{0:string,1:list<string>,2:array<string,mixed>}> */
    private array $sent = [];

    private function mockMailer(): void
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendTemplate')->willReturnCallback(function (string $code, string $locale, array $to, array $vars): string {
            $this->sent[] = [$code, $to, $vars];
            return '250 OK';
        });
        $container = $this->container;
        self::assertInstanceOf(Container::class, $container);
        $container->set(Mailer::class, $mailer);
    }

    private function day(int $offset): string
    {
        return (new \DateTimeImmutable('today'))->modify("$offset days")->format('Y-m-d');
    }

    public function testDigestIsSentOnceAndPriceAlertTriggers(): void
    {
        $this->mockMailer();  // před prvním requestem — kontejner pak AlertService drží
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Alert Exchange', 'type' => 'exchange'])['body']['id'];
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Spot'])['body']['id'];
        $pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Alerty'])['body']['id'];
        $position = function (string $symbol) use ($acc, $pf): array {
            $ins = $this->call($this->userId, 'POST', '/api/instruments', ['asset_class' => 'crypto', 'symbol' => $symbol, 'name' => "Synthetic $symbol",
                'quote_currency' => 'CZK', 'price_source' => 'manual'])['body']['id'];
            $pos = $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'market', 'portfolio_id' => $pf, 'account_id' => $acc,
                'instrument_id' => $ins])['body']['id'];
            return [$ins, $pos];
        };
        [$x, $posX] = $position('SYNX');
        [, $posY] = $position('SYNY');
        $acquired = (new \DateTimeImmutable('today'))->modify('-3 years +9 days')->format('Y-m-d');
        foreach ([[$posX, '1'], [$posY, '2']] as [$pos, $qty]) {
            self::assertSame(201, $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $pos, 'type' => 'buy',
                'executed_at' => "$acquired 10:00", 'quantity' => $qty, 'amount' => '50'])['status']);
        }
        $price = $this->db->pdo()->prepare("INSERT INTO prices (instrument_id, date, close, currency, source) VALUES (?, ?, ?, 'CZK', 'manual')");
        $price->execute([$x, $acquired, '50']);
        $price->execute([$x, $this->day(-2), '100']);
        $price->execute([$x, $this->day(-1), '120']);
        $price->execute([$x, $this->day(0), '120']);
        // SYNY nemá cenu kromě dne nákupu → „chybí / zastaralá cena"
        $this->db->pdo()->prepare("DELETE FROM prices WHERE instrument_id = (SELECT instrument_id FROM positions WHERE id = ?)")->execute([$posY]);

        $settings = $this->call($this->userId, 'PUT', '/api/settings/alerts', ['enabled' => true, 'recipients' => 'alerts@example.invalid',
            'move_portfolio_pct' => '5', 'move_position_pct' => '', 'holding_days' => 30, 'data_issues' => true]);
        self::assertSame(200, $settings['status'], $settings['raw']);
        self::assertSame(['5', null, 30], [$settings['body']['move_portfolio_pct'], $settings['body']['move_position_pct'], $settings['body']['holding_days']]);
        $above = $this->call($this->userId, 'POST', '/api/price-alerts', ['instrument_id' => $x, 'direction' => 'above', 'price' => '110']);
        self::assertSame(201, $above['status'], $above['raw']);
        self::assertSame('CZK', $above['body']['currency']);
        $this->call($this->userId, 'POST', '/api/price-alerts', ['instrument_id' => $x, 'direction' => 'below', 'price' => '50']);

        // Cizí uživatel cenové hranice nevidí ani neupraví
        self::assertSame([], $this->call($this->adminId, 'GET', '/api/price-alerts')['body']);
        self::assertSame(404, $this->call($this->adminId, 'PUT', "/api/price-alerts/{$above['body']['id']}",
            ['instrument_id' => $x, 'direction' => 'above', 'price' => '1'])['status']);

        // Cílová alokace krypto 50 / hotovost 50 při 100 % v kryptu → obě třídy mimo toleranci
        self::assertSame(200, $this->call($this->userId, 'PUT', '/api/allocation/targets', ['classes' => [
            ['category' => 'crypto', 'target_pct' => '50'], ['category' => 'cash', 'target_pct' => '50'],
        ]])['status']);

        $first = $this->call($this->userId, 'POST', '/api/settings/alerts/send');
        self::assertSame(200, $first['status'], $first['raw']);
        self::assertTrue($first['body']['sent']);
        self::assertSame(['price' => 1, 'moves' => 1, 'holding' => 2, 'allocation' => 2, 'data' => 1], $first['body']['counts']);
        [$code, $to, $vars] = $this->sent[0];
        self::assertSame(['alerts_digest', ['alerts@example.invalid']], [$code, $to]);
        self::assertSame(['SYNX', 'above', '120'], [$vars['price'][0]['symbol'], $vars['price'][0]['direction'], $vars['price'][0]['current']]);
        self::assertSame(['portfolio', 'Alerty', '20.00'], [$vars['moves'][0]['scope'], $vars['moves'][0]['name'], $vars['moves'][0]['gain_pct']]);
        self::assertSame(10, $vars['holding'][0]['days']);
        self::assertSame('SYNY', $vars['data'][0]['symbol']);
        self::assertSame([['crypto', '100.00', '50.00'], ['cash', '0.00', '50.00']],
            array_map(static fn (array $a) => [$a['category'], $a['pct'], $a['target_pct']], $vars['allocation']));
        self::assertStringStartsWith('Upozornění MyPortfolio: 7 položek', $vars['subject']);

        $second = $this->call($this->userId, 'POST', '/api/settings/alerts/send')['body'];
        self::assertFalse($second['sent']);
        self::assertCount(1, $this->sent);
        $alerts = array_column($this->call($this->userId, 'GET', '/api/price-alerts')['body'], null, 'direction');
        self::assertSame('120', $alerts['above']['triggered_price']);
        self::assertNull($alerts['below']['triggered_at']);

        // Úprava hranice ji znovu aktivuje
        $this->call($this->userId, 'PUT', "/api/price-alerts/{$above['body']['id']}", ['instrument_id' => $x, 'direction' => 'above', 'price' => '115']);
        self::assertSame(['price' => 1, 'moves' => 0, 'holding' => 0, 'allocation' => 0, 'data' => 0], $this->call($this->userId, 'POST', '/api/settings/alerts/send')['body']['counts']);
    }

    public function testValidation(): void
    {
        $r = $this->call($this->userId, 'PUT', '/api/settings/alerts', ['enabled' => true, 'recipients' => 'nope', 'move_portfolio_pct' => '0',
            'holding_days' => 999]);
        self::assertSame(400, $r['status']);
        self::assertSame(['recipients', 'move_portfolio_pct', 'holding_days'], array_keys($r['body']['error']['fields']));
        $p = $this->call($this->userId, 'POST', '/api/price-alerts', ['instrument_id' => 999999999, 'direction' => 'sideways', 'price' => '-1']);
        self::assertSame(400, $p['status']);
    }
}
