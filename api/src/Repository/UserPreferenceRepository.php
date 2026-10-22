<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Support\Decimal;

/**
 * Předvolby uživatele potřebné mimo profil (měna zobrazení pro ocenění a historii, týdenní report, upozornění).
 */
final class UserPreferenceRepository
{
    public function __construct(private readonly Connection $db) {}

    public function displayCurrency(int $userId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT display_currency FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (string) ($stmt->fetchColumn() ?: 'CZK');
    }

    /** Instrument, se kterým uživatel porovnává výnos (graf historie). */
    public function benchmark(int $userId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT benchmark_instrument_id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        return $id !== null && $id !== false ? (int) $id : null;
    }

    public function setBenchmark(int $userId, ?int $instrumentId): void
    {
        $this->db->pdo()->prepare('UPDATE users SET benchmark_instrument_id = ? WHERE id = ?')->execute([$instrumentId, $userId]);
    }
    /** @return array{enabled:bool,detailed:bool,monthly:bool,yearly:bool,recipients:list<string>,sent_at:?string,email:string,name:string,locale:string,display_currency:string}|null */
    public function weeklyReport(int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT email, name, locale, display_currency, weekly_report_enabled, weekly_report_detailed, weekly_report_recipients, weekly_report_sent_at,
                    monthly_report_enabled, yearly_report_enabled
               FROM users WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) $row['weekly_report_recipients']))));
        return [
            'enabled'          => (bool) $row['weekly_report_enabled'],
            'detailed'         => (bool) $row['weekly_report_detailed'],
            'monthly'          => (bool) $row['monthly_report_enabled'],
            'yearly'           => (bool) $row['yearly_report_enabled'],
            'recipients'       => $recipients,
            'sent_at'          => $row['weekly_report_sent_at'],
            'email'            => (string) $row['email'],
            'name'             => (string) $row['name'],
            'locale'           => (string) $row['locale'],
            'display_currency' => (string) $row['display_currency'],
        ];
    }

    /** @param list<string> $recipients prázdné = přihlašovací e-mail */
    public function setWeeklyReport(int $userId, bool $enabled, array $recipients, bool $detailed = true, bool $monthly = false, bool $yearly = false): void
    {
        $this->db->pdo()->prepare('UPDATE users SET weekly_report_enabled = ?, weekly_report_detailed = ?, weekly_report_recipients = ?,
                                                    monthly_report_enabled = ?, yearly_report_enabled = ? WHERE id = ?')
            ->execute([$enabled ? 1 : 0, $detailed ? 1 : 0, $recipients !== [] ? implode(',', $recipients) : null, $monthly ? 1 : 0, $yearly ? 1 : 0, $userId]);
    }

    /** @return list<int> aktivní uživatelé se zapnutým měsíčním (`monthly`) / ročním (`yearly`) souhrnem (cron) */
    public function periodReportUsers(string $kind): array
    {
        $column = $kind === 'yearly' ? 'yearly_report_enabled' : 'monthly_report_enabled';
        return array_map('intval', $this->db->pdo()
            ->query("SELECT id FROM users WHERE $column = 1 AND is_active = 1 ORDER BY id")
            ->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function markWeeklyReportSent(int $userId): void
    {
        $this->db->pdo()->prepare('UPDATE users SET weekly_report_sent_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$userId]);
    }

    /** @return list<int> aktivní uživatelé se zapnutým týdenním reportem (cron) */
    public function weeklyReportUsers(): array
    {
        return array_map('intval', $this->db->pdo()
            ->query('SELECT id FROM users WHERE weekly_report_enabled = 1 AND is_active = 1 ORDER BY id')
            ->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Nastavení e-mailových upozornění.
     *
     * @return array{enabled:bool,recipients:list<string>,move_portfolio_pct:?string,move_position_pct:?string,holding_days:?int,
     *   data_issues:bool,allocation:bool,sent_at:?string,email:string,name:string,locale:string,display_currency:string}|null
     */
    public function alerts(int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT email, name, locale, display_currency, alerts_enabled, alerts_recipients, alerts_move_portfolio_pct,
                    alerts_move_position_pct, alerts_holding_days, alerts_data_issues, alerts_allocation, alerts_sent_at
               FROM users WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'enabled'            => (bool) $row['alerts_enabled'],
            'recipients'         => array_values(array_filter(array_map('trim', explode(',', (string) $row['alerts_recipients'])))),
            'move_portfolio_pct' => Decimal::out($row['alerts_move_portfolio_pct']),
            'move_position_pct'  => Decimal::out($row['alerts_move_position_pct']),
            'holding_days'       => $row['alerts_holding_days'] !== null ? (int) $row['alerts_holding_days'] : null,
            'data_issues'        => (bool) $row['alerts_data_issues'],
            'allocation'         => (bool) $row['alerts_allocation'],
            'sent_at'            => $row['alerts_sent_at'],
            'email'              => (string) $row['email'],
            'name'               => (string) $row['name'],
            'locale'             => (string) $row['locale'],
            'display_currency'   => (string) $row['display_currency'],
        ];
    }

    /** @param array{enabled:bool,recipients:list<string>,move_portfolio_pct:?string,move_position_pct:?string,holding_days:?int,data_issues:bool,allocation:bool} $s */
    public function setAlerts(int $userId, array $s): void
    {
        $this->db->pdo()->prepare(
            'UPDATE users SET alerts_enabled = ?, alerts_recipients = ?, alerts_move_portfolio_pct = ?, alerts_move_position_pct = ?,
                              alerts_holding_days = ?, alerts_data_issues = ?, alerts_allocation = ? WHERE id = ?'
        )->execute([$s['enabled'] ? 1 : 0, $s['recipients'] !== [] ? implode(',', $s['recipients']) : null, $s['move_portfolio_pct'],
            $s['move_position_pct'], $s['holding_days'], $s['data_issues'] ? 1 : 0, $s['allocation'] ? 1 : 0, $userId]);
    }

    public function markAlertsSent(int $userId): void
    {
        $this->db->pdo()->prepare('UPDATE users SET alerts_sent_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$userId]);
    }

    /** @return list<int> aktivní uživatelé se zapnutými upozorněními (cron) */
    public function alertUsers(): array
    {
        return array_map('intval', $this->db->pdo()
            ->query('SELECT id FROM users WHERE alerts_enabled = 1 AND is_active = 1 ORDER BY id')
            ->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<int> aktivní uživatelé se zapnutým napojením na burzu (cron synchronizace) */
    public function exchangeSyncUsers(): array
    {
        return array_map('intval', $this->db->pdo()->query(
            'SELECT DISTINCT c.user_id FROM exchange_connections c JOIN users u ON u.id = c.user_id
              WHERE c.enabled = 1 AND u.is_active = 1 ORDER BY c.user_id'
        )->fetchAll(\PDO::FETCH_COLUMN));
    }
}
