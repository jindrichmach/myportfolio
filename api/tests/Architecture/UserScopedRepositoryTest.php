<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Architecture;

use MyPortfolio\Repository\AccountRepository;
use MyPortfolio\Repository\AlertRepository;
use MyPortfolio\Repository\AllocationTargetRepository;
use MyPortfolio\Repository\BankNoticeRepository;
use MyPortfolio\Repository\ExchangeConnectionRepository;
use MyPortfolio\Repository\ImapAccountRepository;
use MyPortfolio\Repository\ImportBatchRepository;
use MyPortfolio\Repository\ImportPreviewRepository;
use MyPortfolio\Repository\LotRepository;
use MyPortfolio\Repository\ManualValuationRepository;
use MyPortfolio\Repository\NetWorthRepository;
use MyPortfolio\Repository\PortfolioRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\SnapshotRepository;
use MyPortfolio\Repository\TaxRepository;
use MyPortfolio\Repository\TransactionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Izolace uživatelů: každá veřejná metoda repository nad uživatelskými daty bere
 * `int $userId` jako první parametr a každý SQL dotaz v souboru filtruje `user_id`.
 */
final class UserScopedRepositoryTest extends TestCase
{
    /** @return array<string,array{class-string}> */
    public static function repositories(): array
    {
        return [
            'accounts'     => [AccountRepository::class],
            'portfolios'   => [PortfolioRepository::class],
            'allocation'   => [AllocationTargetRepository::class],
            'net_worth'    => [NetWorthRepository::class],
            'positions'    => [PositionRepository::class],
            'transactions' => [TransactionRepository::class],
            'lots'         => [LotRepository::class],
            'valuations'   => [ManualValuationRepository::class],
            'snapshots'    => [SnapshotRepository::class],
            'imports'      => [ImportBatchRepository::class],
            'previews'     => [ImportPreviewRepository::class],
            'exchanges'    => [ExchangeConnectionRepository::class],
            'tax'          => [TaxRepository::class],
            'alerts'       => [AlertRepository::class],
            'imap'         => [ImapAccountRepository::class],
            'notices'      => [BankNoticeRepository::class],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('repositories')]
    public function testEveryPublicMethodTakesUserIdFirst(string $class): void
    {
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isStatic()) {
                continue;
            }
            $first = $method->getParameters()[0] ?? null;
            self::assertNotNull($first, "$class::{$method->getName()} nemá parametry.");
            self::assertSame('userId', $first->getName(), "$class::{$method->getName()} musí brát \$userId jako první parametr.");
            self::assertSame('int', (string) $first->getType());
        }
    }

    /** @param class-string $class */
    #[DataProvider('repositories')]
    public function testEveryStatementFiltersByUserId(string $class): void
    {
        $source = (string) file_get_contents((string) (new \ReflectionClass($class))->getFileName());
        preg_match_all('/->prepare\((.*?)\)\s*(?:->|;)/s', $source, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $expression) {
            if (preg_match('/^\s*[\'"(]?\s*(?:self::SELECT|\'\s*(?:SELECT|UPDATE|DELETE))/i', $expression) !== 1) {
                continue; // INSERT — user_id je ve VALUES
            }
            // Dynamicky skládané WHERE ($whereSql / implode($where)) začíná vždy `user_id = ?` — viz list().
            self::assertMatchesRegularExpression(
                '/user_id\s*=\s*\?|\$whereSql|implode\(\' AND \', \$where\)/',
                $expression,
                "$class: dotaz bez user_id — " . trim($expression),
            );
        }
    }
}
