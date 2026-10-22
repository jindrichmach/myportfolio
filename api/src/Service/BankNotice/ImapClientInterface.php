<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice;

interface ImapClientInterface
{
    /**
     * Posledních `$limit` zpráv složky (nejnovější první), schránka se nemění.
     *
     * @param array<string,mixed> $settings řádek imap_accounts + dešifrované `password`
     * @return list<NoticeMessage>
     */
    public function latest(array $settings, int $limit): array;

    /**
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,folders?:list<string>}
     */
    public function test(array $settings): array;
}
