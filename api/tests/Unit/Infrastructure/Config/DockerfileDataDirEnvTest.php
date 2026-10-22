<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Infrastructure\Config;

use PHPUnit\Framework\TestCase;

/**
 * Pojistka: Dockerfile NESMÍ natvrdo nastavovat `ENV MYPORTFOLIO_DATA_DIR=/data`.
 *
 * Od 3.6.0 je single-volume default — `MYPORTFOLIO_DATA_DIR=/data` se předává
 * přes `environment:` blok v `docker-compose.yml` a `docker-compose.production.yml`,
 * NIKOLI z Dockerfile ENV. Důvody:
 *   1) Uživatelé s custom compose (např. bare Docker `docker run` bez compose)
 *      musí mít možnost opt-out a používat log/storage/private v rootu image.
 *   2) Image zůstává neutrální — chování se řídí runtime configurací, ne build-time.
 */
final class DockerfileDataDirEnvTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function dockerfileProvider(): array
    {
        return [
            'Dockerfile' => ['/Dockerfile'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dockerfileProvider')]
    public function testDockerfileDoesNotHardcodeDataDirEnv(string $relativePath): void
    {
        $repoRoot = dirname(__DIR__, 5);
        $dockerfilePath = $repoRoot . $relativePath;

        self::assertFileExists($dockerfilePath, "{$relativePath} not found at repo root");

        $contents = file_get_contents($dockerfilePath);
        self::assertIsString($contents);

        // Strip comments — they may legitimately mention MYPORTFOLIO_DATA_DIR.
        $codeOnly = implode("\n", array_filter(
            preg_split('/\R/', $contents) ?: [],
            static fn (string $line) => !preg_match('/^\s*#/', $line),
        ));

        self::assertDoesNotMatchRegularExpression(
            '/^\s*ENV\s+MYPORTFOLIO_DATA_DIR\b/m',
            $codeOnly,
            "{$relativePath} must NOT set ENV MYPORTFOLIO_DATA_DIR — single-volume mode "
            . 'is enabled via docker-compose.yml environment: block (since 3.6.0). '
            . 'Hardcoding in image would prevent opt-out for custom deployments.',
        );
    }
}
