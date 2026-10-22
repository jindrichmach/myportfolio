<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice\Parser;

use MyPortfolio\Service\BankNotice\NoticeMessage;
use MyPortfolio\Service\BankNotice\ParsedNotice;

interface NoticeParserInterface
{
    /** Stabilní klíč parseru (protokol, payload). */
    public function key(): string;

    /** Doména odesílatele — proti ní se ověřuje DKIM zarovnání. */
    public function senderDomain(): string;

    public function supports(NoticeMessage $message): bool;

    /** @throws \RuntimeException když avízo nejde vytěžit */
    public function parse(NoticeMessage $message): ParsedNotice;
}
