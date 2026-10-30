<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Catalog;

use MyPortfolio\Bootstrap;
use MyPortfolio\Service\Portfolio\DomainError;

/**
 * Přibalená knihovna ikon (`api/resources/icon-library`, statický asset): ikony kryptoměn (CC0), loga správců ETF
 * a seznam českých institucí s webem (logo se stahuje přes {@see WebsiteIconFetcher}, u části je přibalené).
 */
final class IconLibrary
{
    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? Bootstrap::rootDir() . '/api/resources/icon-library';
    }

    /** @return list<array{symbol:string,name:string,file:string}> */
    public function crypto(): array
    {
        return $this->json('crypto.json');
    }

    /** @return list<array{slug:string,name:string}> */
    public function etfIssuers(): array
    {
        return $this->json('etf.json');
    }

    /** Logo správce ETF podle slugu (`ishares`). */
    public function etfIcon(string $slug): string
    {
        foreach ($this->etfIssuers() as $e) {
            if ($e['slug'] === $slug && ($bytes = @file_get_contents($this->dir . "/etf/$slug.png")) !== false) {
                return $bytes;
            }
        }
        throw DomainError::notFound('Ikona v knihovně neexistuje.');
    }

    /** @return list<array{name:string,type:string,website:string,icon?:string}> */
    public function institutions(): array
    {
        return $this->json('institutions-cz.json');
    }

    /** Obsah PNG ikony kryptoměny podle symbolu (`btc`). */
    public function cryptoIcon(string $symbol): string
    {
        $symbol = strtolower($symbol);
        foreach ($this->crypto() as $c) {
            if (strtolower($c['symbol']) === $symbol) {
                $bytes = @file_get_contents($this->dir . '/' . $c['file']);
                if ($bytes !== false) {
                    return $bytes;
                }
            }
        }
        throw DomainError::notFound('Ikona v knihovně neexistuje.');
    }

    /** Obsah přibaleného loga instituce podle souboru (`raiffeisenbank`); jen z `institutions-cz.json`. */
    public function institutionIcon(string $slug): string
    {
        foreach ($this->institutions() as $i) {
            if (isset($i['icon']) && $i['icon'] === "institutions/$slug.png") {
                $bytes = @file_get_contents($this->dir . '/' . $i['icon']);
                if ($bytes !== false) {
                    return $bytes;
                }
            }
        }
        throw DomainError::notFound('Ikona v knihovně neexistuje.');
    }

    /** @return list<array<string,string>> */
    private function json(string $file): array
    {
        $data = json_decode((string) @file_get_contents($this->dir . '/' . $file), true);
        return is_array($data) ? array_values($data) : [];
    }
}
