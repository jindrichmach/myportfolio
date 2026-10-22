<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Support;

use MyPortfolio\Support\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    /** @return array<string,array{mixed,?string}> */
    public static function validInputs(): array
    {
        return [
            'tečka'              => ['0.015', '0.015'],
            'čárka'              => ['0,5', '0.5'],
            'mezery tisíců'      => ['1 234 567,89', '1234567.89'],
            'nezlomitelná mezera' => ["12\u{00A0}000", '12000'],
            'int'                => [42, '42'],
            'záporné'            => ['-2.50', '-2.5'],
            'prázdné'            => ['', null],
            'null'               => [null, null],
        ];
    }

    #[DataProvider('validInputs')]
    public function testParsesValidInput(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, Decimal::parse($input, 18)?->toString());
    }

    /** @return array<string,array{mixed}> */
    public static function invalidInputs(): array
    {
        return [
            'float'     => [0.1],
            'text'      => ['abc'],
            'exponent'  => ['1e5'],
            'dvě tečky' => ['1.2.3'],
            'pole'      => [['1']],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInput(mixed $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::parse($input, 18);
    }

    public function testRejectsMoreDecimalsThanScaleButIgnoresTrailingZeros(): void
    {
        self::assertSame('1.5', Decimal::parse('1.5000000', 4)?->toString());
        $this->expectException(\InvalidArgumentException::class);
        Decimal::parse('1.23456', 4);
    }

    public function testDbRoundTrip(): void
    {
        self::assertSame('0.0150', Decimal::toDb(Decimal::parse('0.015', 4), 4));
        self::assertSame('0.015', Decimal::out('0.015000000000000000'));
        self::assertSame('1000', Decimal::out('1000.0000'));
        self::assertNull(Decimal::out(null));
    }
}
