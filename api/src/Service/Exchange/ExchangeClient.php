<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Exchange;

use MyPortfolio\Service\Import\Draft;

/**
 * Klient API jedné burzy: ověření klíče a stažení pohybů za období jako řádky importu (Draft) —
 * stejný model jako parsery CSV, takže párování instrumentů, převody i idempotence jdou přes import.
 */
interface ExchangeClient
{
    /** Klíč poskytovatele (coinbase, binance, kraken, coinmate). */
    public function provider(): string;

    public function label(): string;

    /**
     * Pole přihlašovacích údajů pro formulář.
     *
     * @return list<array{key:string,label:array{0:string,1:string},secret:bool,multiline?:bool}>
     */
    public function credentialFields(): array;

    /**
     * Ověří klíč (jen čtecí volání). Varuje, když má klíč víc oprávnění než čtení (obchod, výběr).
     *
     * @param array<string,string> $credentials
     * @return array{ok:bool,message:string,warnings:list<string>}
     */
    public function test(array $credentials): array;

    /**
     * Pohyby v intervalu [$from, $to) — `$from` null = celá historie.
     *
     * @param array<string,string> $credentials
     * @param list<string> $knownAssets symboly aktiv, která už na napojeném účtu jsou (i s nulovým držením) — burzy,
     *                                  které obchody hledají po párech (Binance), z nich poznají i uzavřené pozice
     * @return list<Draft>
     * @throws ExchangeException
     */
    public function fetch(array $credentials, ?\DateTimeImmutable $from, \DateTimeImmutable $to, array $knownAssets = []): array;
}
