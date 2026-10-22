<?php

declare(strict_types=1);

// Composer autoloader (stejně jako vendor/autoload.php — sjednocuje vstupní bod)
require __DIR__ . '/../vendor/autoload.php';

// Bypass `final class` u tříd, které potřebujeme mockovat v unit testech
// (PurchaseInvoiceRepository, Connection a další). PHPUnit 13 nepodporuje
// mockování final tříd nativně; dg/bypass-finals to runtime přepíše.
\DG\BypassFinals::enable();
