<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Demo;

use MyPortfolio\Service\Demo\DemoDataSeeder;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Ukázková data projdou validací a naplní všechny stránky (bez stažených cen — jen struktura). */
#[Group('integration')]
final class DemoDataTest extends ApiTestCase
{
    private const PARTNER = 'partner.demo-test@example.invalid';

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            $id = (int) $pdo->query("SELECT id FROM users WHERE email = '" . self::PARTNER . "'")->fetchColumn();
            if ($id !== 0) {
                $pdo->exec("DELETE FROM shares WHERE owner_user_id = $id OR grantee_user_id = $id");
                foreach (['daily_snapshots', 'snapshot_dirty', 'tax_lots', 'transactions', 'positions', 'portfolios', 'accounts', 'sessions'] as $table) {
                    $pdo->exec("DELETE FROM $table WHERE user_id = $id");
                }
                $pdo->exec("DELETE FROM users WHERE id = $id");
            }
        }
        parent::tearDown();
    }

    public function testSeedsRichDataOnlyIntoEmptyAccount(): void
    {
        $today = new \DateTimeImmutable('2026-10-09');
        $r = $this->container->get(DemoDataSeeder::class)->seed($this->userId, $today);
        self::assertSame([8, 8, 9, 5, 14], [$r['institutions'], $r['instruments'], $r['accounts'], $r['portfolios'], $r['positions']]);
        self::assertGreaterThan(200, $r['transactions']);
        self::assertSame('2021-06-01', $r['from']);

        $count = fn (string $sql) => (int) $this->db->pdo()->query($sql . " AND user_id = {$this->userId}")->fetchColumn();
        self::assertGreaterThan(0, $count("SELECT COUNT(*) FROM transactions WHERE type = 'dividend' AND fee IS NOT NULL"));
        self::assertGreaterThan(0, $count("SELECT COUNT(*) FROM transactions WHERE type = 'transfer_in'"));
        self::assertGreaterThan(0, $count("SELECT COUNT(*) FROM lot_disposals WHERE kind = 'sell'"));
        self::assertSame(2, $count('SELECT COUNT(*) FROM price_alerts WHERE 1 = 1'));
        // žádný prodej nespotřeboval víc, než pozice držela
        self::assertSame(0, $count("SELECT COUNT(*) FROM tax_lots WHERE remaining_quantity < 0"));

        $income = $this->call($this->userId, 'GET', '/api/income?year=2026')['body'];
        self::assertGreaterThan(0, count($income['payments']));
        $tax = $this->call($this->userId, 'GET', '/api/tax-report/years')['body'];
        self::assertContains(2025, $tax);

        // Penzijko (vklady + čtvrtletní ocenění), DIP, alokace, benchmark, čisté jmění
        self::assertSame(21, $count('SELECT COUNT(*) FROM manual_valuations WHERE note LIKE \'demo — výpis\''));
        self::assertTrue($this->call($this->userId, 'GET', '/api/allocation')['body']['has_targets']);
        self::assertNotNull($this->container->get(\MyPortfolio\Repository\UserPreferenceRepository::class)->benchmark($this->userId));
        $nw = $this->call($this->userId, 'GET', '/api/net-worth')['body'];
        self::assertSame(['real_estate', 'other', 'liability', 'liability'], array_column($nw['items'], 'kind'));
        $loans = array_column(array_filter($nw['items'], static fn (array $i) => $i['kind'] === 'liability'), null, 'name');
        self::assertSame('0.00', $loans['Úvěr na auto']['value']);           // doplacený
        self::assertGreaterThan(2500000, (float) $loans['Hypotéka']['value']);
        self::assertLessThan(3360000, (float) $loans['Hypotéka']['value']);

        // Demo partner: vlastní data a vzájemné sdílení
        $p = $this->container->get(DemoDataSeeder::class)->seedPartner($this->userId, self::PARTNER, $today);
        self::assertGreaterThanOrEqual(12, strlen($p['password']));
        $received = $this->call($this->userId, 'GET', '/api/shares')['body']['received'];
        self::assertSame(['write', 'portfolios'], [$received[0]['permission'], $received[0]['scope']]);
        $house = $this->call($this->userId, 'GET', '/api/household')['body'];
        self::assertCount(2, $house['members']);

        $this->expectException(DomainError::class);
        $this->container->get(DemoDataSeeder::class)->seed($this->userId, $today);
    }
}
