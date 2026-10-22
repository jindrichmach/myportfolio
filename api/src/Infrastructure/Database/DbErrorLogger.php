<?php

declare(strict_types=1);

namespace MyPortfolio\Infrastructure\Database;

use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Formátuje PDOException do Monolog kontextu: sqlstate, query (single-line),
 * params (s redakcí citlivých hodnot), caller (první frame mimo Infrastructure\Database).
 *
 * Volá se z LoggingPdo / LoggingPdoStatement při zachycené chybě. Caller je
 * povinen exception rethrownout — DbErrorLogger jen loguje.
 */
final class DbErrorLogger
{
    /**
     * Heuristika: pokud SQL obsahuje sloupec, jehož název odpovídá těmto patternům,
     * VŠECHNY parametry se nahradí placeholderem. Nemůžeme spolehlivě mapovat
     * pozici `?` na konkrétní sloupec (ne u INSERTu, ne u UPDATE SET-listů s expr.),
     * tak raději redaktujeme celou množinu.
     */
    private const SENSITIVE_PATTERNS = [
        '/\bpassword\b/i',
        '/\bpassword_hash\b/i',
        '/\bsecret\b/i',
        '/\btoken\b/i',
        '/\btoken_hash\b/i',
        '/\btotp_secret\b/i',
        '/\brecovery_codes\b/i',
        '/\bapi_token\b/i',
        '/\bcredential_id(?:_hash)?\b/i',
        '/\bpublic_key\b/i',
        '/\bchallenge\b/i',
        '/\boptions_json\b/i',
        '/\bsession_id_hash\b/i',
        '/\bflow_token_hash\b/i',
    ];

    /** @param array<array-key,mixed> $params */
    public static function log(LoggerInterface $logger, PDOException $e, string $sql, array $params): void
    {
        $logger->error('DB error: ' . $e->getMessage(), [
            'sqlstate' => $e->getCode(),
            'sql'      => self::normalize($sql),
            'params'   => self::redact($sql, $params),
            'caller'   => self::caller($e),
        ]);
    }

    private static function normalize(string $sql): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($sql));
    }

    /**
     * @param array<array-key,mixed> $params
     * @return array<array-key,mixed>
     */
    private static function redact(string $sql, array $params): array
    {
        foreach (self::SENSITIVE_PATTERNS as $pat) {
            if (preg_match($pat, $sql) === 1) {
                return ['__redacted__' => '*** params hidden (sensitive column referenced) ***'];
            }
        }
        return $params;
    }

    /**
     * První frame stack-trace, který není uvnitř Infrastructure\Database namespace —
     * to je skutečný caller (Repository/Action/cron). Bez tohoto by `caller` ukazoval
     * na LoggingPdoStatement::execute, což je k ničemu.
     */
    private static function caller(PDOException $e): string
    {
        foreach ($e->getTrace() as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file === '') continue;
            if (str_contains($file, DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR)) continue;
            return $file . ':' . (int) ($frame['line'] ?? 0);
        }
        return $e->getFile() . ':' . $e->getLine();
    }
}
