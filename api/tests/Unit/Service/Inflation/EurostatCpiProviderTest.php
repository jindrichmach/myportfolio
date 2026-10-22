<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Inflation;

use MyPortfolio\Service\Inflation\EurostatCpiProvider;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Tests\Support\FakeHttpFetcher;
use PHPUnit\Framework\TestCase;

/** Odpověď Eurostat JSON-stat (zkrácená syntetická fixture ve tvaru prc_hicp_minr). */
final class EurostatCpiProviderTest extends TestCase
{
    private const BODY = '{"version":"2.0","class":"dataset","value":{"0":100.5,"1":101,"3":102.25},"id":["freq","unit","coicop18","geo","time"],'
        . '"size":[1,1,1,1,4],"dimension":{"time":{"category":{"index":{"2001-01":0,"2001-02":1,"2001-03":2,"2001-04":3}}}}}';

    public function testParsesMonthsAndSkipsMissingValues(): void
    {
        $http = new FakeHttpFetcher(['prc_hicp_minr' => self::BODY]);
        $rows = (new EurostatCpiProvider($http))->fetch('2001-01');
        self::assertSame(['2001-01' => '100.5', '2001-02' => '101', '2001-04' => '102.25'], $rows);
        self::assertStringContainsString('geo=CZ&coicop18=TOTAL&unit=I15&sinceTimePeriod=2001-01', $http->requested[0]);
    }

    public function testRejectsMultiSeriesResponse(): void
    {
        $this->expectException(MarketDataException::class);
        EurostatCpiProvider::parse(str_replace('"size":[1,1,1,1,4]', '"size":[1,2,1,1,4]', self::BODY));
    }
}
