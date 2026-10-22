<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use PDO;

/**
 * Zachytí UTC text i epoch v jediném SQL statementu. Epoch se používá pro
 * stávající TIMESTAMP sloupce, UTC text pro bezpečnostní DATETIME(6).
 */
final class DatabaseSecurityClock implements SecurityClock
{
    public function capture(PDO $pdo): SecurityTime
    {
        $row = $pdo->query(
            'SELECT UTC_TIMESTAMP(6) AS utc_sql,
                    UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) AS epoch'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Databázový bezpečnostní čas není dostupný.');
        }

        return SecurityTime::fromDatabase(
            (string) ($row['utc_sql'] ?? ''),
            $row['epoch'] ?? '',
        );
    }
}
