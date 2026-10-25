<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Admin;

use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/admin/activity-log
 * Query: ?action=&user_id=&entity_type=&entity_id=&q=&from=YYYY-MM-DD&to=YYYY-MM-DD&limit=100&offset=0
 * `q` hledá v názvu akce, e-mailu a jménu uživatele; `from`/`to` jsou včetně celého dne.
 *
 * Vrací poslední záznamy z activity_log + JOIN users.
 * Admin only.
 */
final class ListActivityLogAction
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $q = $request->getQueryParams();
        $where = [];
        $params = [];
        if (!empty($q['action'])) {
            $where[] = 'al.action = ?';
            $params[] = (string) $q['action'];
        }
        if (!empty($q['user_id'])) {
            $where[] = 'al.user_id = ?';
            $params[] = (int) $q['user_id'];
        }
        if (!empty($q['entity_type'])) {
            $where[] = 'al.entity_type = ?';
            $params[] = (string) $q['entity_type'];
        }
        if (!empty($q['entity_id'])) {
            $where[] = 'al.entity_id = ?';
            $params[] = (int) $q['entity_id'];
        }
        if (trim((string) ($q['q'] ?? '')) !== '') {
            $like = '%' . addcslashes(trim((string) $q['q']), '%_\\') . '%';
            $where[] = '(al.action LIKE ? OR u.email LIKE ? OR u.name LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($q['from'] ?? '')) === 1) {
            $where[] = 'al.created_at >= ?';
            $params[] = $q['from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($q['to'] ?? '')) === 1) {
            $where[] = 'al.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = (string) $q['to'];
        }
        $limit = max(1, min(500, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT al.id, al.user_id, u.email AS user_email, u.name AS user_name,
                       al.action, al.entity_type, al.entity_id, al.payload, al.ip, al.created_at
                  FROM activity_log al
             LEFT JOIN users u ON u.id = al.user_id
                  $whereSql
              ORDER BY al.id DESC
                 LIMIT $limit OFFSET $offset";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Decode JSON payload + převed IP z packed binary na čitelnou string
        foreach ($rows as &$r) {
            if ($r['payload'] !== null) {
                $r['payload'] = json_decode((string) $r['payload'], true);
            }
            if ($r['ip'] !== null && $r['ip'] !== '') {
                $r['ip'] = @inet_ntop($r['ip']) ?: null;
            } else {
                $r['ip'] = null;
            }
            $r['id'] = (int) $r['id'];
            $r['user_id'] = $r['user_id'] !== null ? (int) $r['user_id'] : null;
            $r['entity_id'] = $r['entity_id'] !== null ? (int) $r['entity_id'] : null;
        }

        // Total count for pagination
        $countSql = "SELECT COUNT(*) FROM activity_log al LEFT JOIN users u ON u.id = al.user_id $whereSql";
        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Distinct actions for filter dropdown
        $actions = $this->db->pdo()->query(
            "SELECT action, COUNT(*) AS cnt FROM activity_log GROUP BY action ORDER BY action"
        )->fetchAll(\PDO::FETCH_ASSOC);

        return Json::ok($response, [
            'data'    => $rows,
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'actions' => $actions,
        ]);
    }
}
