<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Sharing;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\PortfolioRepository;
use MyPortfolio\Repository\ShareRepository;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\PasswordHasher;
use MyPortfolio\Service\Mail\Mailer;
use MyPortfolio\Service\Portfolio\DomainError;
use Psr\Log\LoggerInterface;

/**
 * Správa sdílení: vlastník sdílí s e-mailem — existující uživatel má sdílení hned
 * (oznámení e-mailem), nový dostane pozvánku s odkazem na založení účtu (platí 14 dní).
 */
final class ShareService
{
    public const PERMISSIONS = ['read', 'write'];
    public const SCOPES = ['all', 'portfolios'];
    private const INVITE_DAYS = 14;

    public function __construct(
        private readonly ShareRepository $shares,
        private readonly PortfolioRepository $portfolios,
        private readonly Connection $db,
        private readonly Mailer $mailer,
        private readonly PasswordHasher $hasher,
        private readonly ActivityLogger $activity,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string,mixed> $body email, permission, scope, portfolio_ids, sections
     * @return array{share:array<string,mixed>,invited:bool,mail_sent:bool}
     */
    public function create(int $ownerId, array $body): array
    {
        $errors = [];
        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            $errors['email'][] = 'Zadej platný e-mail.';
        }
        $settings = $this->settings($ownerId, $body, $errors);
        $owner = $this->user($ownerId);
        if ($email !== '' && $email === mb_strtolower((string) $owner['email'])) {
            $errors['email'][] = 'Sám se sebou sdílet nejde.';
        }
        if ($errors !== []) {
            throw DomainError::validation($errors);
        }
        foreach ($this->shares->granted($ownerId) as $s) {
            if ($s['invite_email'] === $email) {
                throw new DomainError('already_shared', 'S tímto e-mailem už sdílíš — uprav stávající sdílení.', 409);
            }
        }

        if ($this->inactiveUserExists($email)) {
            throw DomainError::validation(['email' => ['Účet s tímto e-mailem je deaktivovaný — požádej správce o jeho obnovení.']]);
        }
        $grantee = $this->userByEmail($email);
        $token = null;
        $expires = null;
        if ($grantee === null) {
            $token = bin2hex(random_bytes(32));
            $expires = (new \DateTimeImmutable('+' . self::INVITE_DAYS . ' days'))->format('Y-m-d H:i:s');
        }
        $id = $this->shares->create(['owner' => $ownerId, 'grantee' => $grantee !== null ? (int) $grantee['id'] : null, 'email' => $email,
            'token_hash' => $token !== null ? hash('sha256', $token) : null, 'expires_at' => $expires] + $settings);
        $this->activity->log('share.created', $ownerId, 'share', $id, ['email' => $email, 'permission' => $settings['permission'], 'scope' => $settings['scope']]);

        $share = $this->shares->findForUser($ownerId, $id) ?? throw DomainError::notFound();
        $sent = $this->notify($share, $owner, $grantee, $token, $expires);
        return ['share' => $share, 'invited' => $grantee === null, 'mail_sent' => $sent];
    }

    /** @param array<string,mixed> $body */
    public function update(int $ownerId, int $id, array $body): array
    {
        $share = $this->shares->findForUser($ownerId, $id);
        if ($share === null || $share['owner_user_id'] !== $ownerId) {
            throw DomainError::notFound('Sdílení nenalezeno.');
        }
        $errors = [];
        $settings = $this->settings($ownerId, $body, $errors);
        if ($errors !== []) {
            throw DomainError::validation($errors);
        }
        $this->shares->update($ownerId, $id, $settings);
        $this->activity->log('share.updated', $ownerId, 'share', $id, ['permission' => $settings['permission'], 'scope' => $settings['scope']]);
        return $this->shares->findForUser($ownerId, $id) ?? throw DomainError::notFound();
    }

    /** Vlastník sdílení odvolá, partner ho opustí. */
    public function delete(int $userId, int $id): void
    {
        $share = $this->shares->findForUser($userId, $id) ?? throw DomainError::notFound('Sdílení nenalezeno.');
        $this->shares->delete($userId, $id);
        $this->activity->log($share['owner_user_id'] === $userId ? 'share.revoked' : 'share.left', $share['owner_user_id'], 'share', $id,
            ['email' => $share['invite_email']]);
    }

    /** Nová pozvánka pro dosud nepřijaté sdílení. @return array{mail_sent:bool} */
    public function resend(int $ownerId, int $id): array
    {
        $share = $this->shares->findForUser($ownerId, $id);
        if ($share === null || $share['owner_user_id'] !== $ownerId || $share['grantee_user_id'] !== null) {
            throw DomainError::notFound('Pozvánka nenalezena.');
        }
        $token = bin2hex(random_bytes(32));
        $expires = (new \DateTimeImmutable('+' . self::INVITE_DAYS . ' days'))->format('Y-m-d H:i:s');
        $this->shares->renewInvite($ownerId, $id, hash('sha256', $token), $expires);
        return ['mail_sent' => $this->notify($share, $this->user($ownerId), null, $token, $expires)];
    }

    /** Veřejný náhled pozvánky. @return array<string,mixed> */
    public function invite(string $token): array
    {
        $share = $this->validInvite($token);
        return ['owner_name' => $share['owner_name'], 'email' => $share['invite_email'], 'permission' => $share['permission'],
            'scope' => $share['scope'], 'portfolios' => array_column($share['portfolios'], 'name'),
            'account_exists' => $this->userByEmail((string) $share['invite_email']) !== null];
    }

    /**
     * Přijetí pozvánky založením účtu (role user, jazyk a měna podle vlastníka).
     *
     * @param array<string,mixed> $body name, password, password_confirm
     */
    public function accept(string $token, array $body): int
    {
        $share = $this->validInvite($token);
        $email = (string) $share['invite_email'];
        if ($this->userByEmail($email) !== null || $this->inactiveUserExists($email)) {
            throw new DomainError('account_exists', 'Účet s tímto e-mailem už existuje — přihlas se a požádej o nové sdílení.', 409);
        }
        $name = trim((string) ($body['name'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'][] = 'Zadej jméno (max. 120 znaků).';
        }
        if ($password !== (string) ($body['password_confirm'] ?? '')) {
            $errors['password_confirm'][] = 'Hesla se neshodují.';
        }
        try {
            $hash = $this->hasher->hash($password);
        } catch (\InvalidArgumentException $e) {
            $errors['password'][] = $e->getMessage();
        }
        if ($errors !== []) {
            throw DomainError::validation($errors);
        }
        $owner = $this->user((int) $share['owner_user_id']);
        $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role, locale, display_currency, is_active) VALUES (?, ?, ?, 'user', ?, ?, 1)"
        )->execute([$email, $hash, $name, $owner['locale'], $owner['display_currency']]);
        $userId = (int) $this->db->pdo()->lastInsertId();
        $this->shares->accept((int) $share['id'], $userId);
        $this->activity->log('share.accepted', (int) $share['owner_user_id'], 'share', (int) $share['id'], ['grantee_user_id' => $userId]);
        $this->activity->log('user.created_by_invite', $userId, 'user', $userId, ['share_id' => (int) $share['id']]);
        return $userId;
    }

    /** @return array<string,mixed> */
    private function validInvite(string $token): array
    {
        $share = preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $this->shares->byToken(hash('sha256', $token)) : null;
        if ($share === null || $share['invite_expires_at'] === null || strtotime((string) $share['invite_expires_at']) < time()) {
            throw DomainError::notFound('Pozvánka neexistuje nebo vypršela.');
        }
        return $share;
    }

    /**
     * Oprávnění, rozsah, portfolia a sekce. Sekce (čisté jmění, daně, příjmy) jen u sdílení celého účtu.
     *
     * @param array<string,mixed> $body
     * @param array<string,list<string>> $errors
     * @return array{permission:string,scope:string,sections:list<string>,portfolios:list<int>}
     */
    private function settings(int $ownerId, array $body, array &$errors): array
    {
        $permission = (string) ($body['permission'] ?? 'read');
        if (!in_array($permission, self::PERMISSIONS, true)) {
            $errors['permission'][] = 'Neplatné oprávnění.';
        }
        $scope = (string) ($body['scope'] ?? 'all');
        if (!in_array($scope, self::SCOPES, true)) {
            $errors['scope'][] = 'Neplatný rozsah.';
        }
        $portfolios = [];
        if ($scope === 'portfolios') {
            foreach ((array) ($body['portfolio_ids'] ?? []) as $pid) {
                $pid = filter_var($pid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($pid === false || $this->portfolios->find($ownerId, $pid) === null) {
                    $errors['portfolio_ids'][] = 'Portfolio nenalezeno.';
                    continue;
                }
                $portfolios[] = $pid;
            }
            if ($portfolios === [] && !isset($errors['portfolio_ids'])) {
                $errors['portfolio_ids'][] = 'Vyber aspoň jedno portfolio.';
            }
        }
        $sections = array_values(array_intersect(ShareAccess::SECTIONS, array_map('strval', (array) ($body['sections'] ?? []))));
        return ['permission' => $permission, 'scope' => $scope, 'sections' => $scope === 'all' ? $sections : [], 'portfolios' => $portfolios];
    }

    /**
     * @param array<string,mixed> $share
     * @param array<string,mixed> $owner
     * @param array<string,mixed>|null $grantee
     */
    private function notify(array $share, array $owner, ?array $grantee, ?string $token, ?string $expires): bool
    {
        $locale = in_array($grantee['locale'] ?? $owner['locale'], ['cs', 'en'], true) ? (string) ($grantee['locale'] ?? $owner['locale']) : 'cs';
        $cs = $locale === 'cs';
        $base = rtrim((string) $this->config->get('app.url', ''), '/');
        $scope = $share['scope'] === 'all'
            ? ($cs ? 'celý účet' : 'whole account')
            : ($cs ? 'portfolia ' : 'portfolios ') . implode(', ', array_column($share['portfolios'], 'name'));
        $vars = [
            'owner_name'      => $owner['name'],
            'scope_text'      => $scope,
            'permission_text' => $share['permission'] === 'write' ? ($cs ? 'čtení i zápis' : 'read and write') : ($cs ? 'jen čtení' : 'read only'),
            'link'            => $token !== null ? "$base/invite/$token" : "$base/",
            'expires_f'       => $expires !== null ? (new \DateTimeImmutable($expires))->format($cs ? 'j. n. Y' : 'Y-m-d') : null,
            'subject'         => $token !== null
                ? ($cs ? "Pozvánka do MyPortfolio od {$owner['name']}" : "Invitation to MyPortfolio from {$owner['name']}")
                : ($cs ? "{$owner['name']} s vámi sdílí portfolio" : "{$owner['name']} shares a portfolio with you"),
        ];
        try {
            $this->mailer->sendTemplate($token !== null ? 'share_invite' : 'share_granted', $locale, [(string) $share['invite_email']], $vars);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('share.mail_failed', ['share_id' => $share['id'], 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function user(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, email, name, locale, display_currency FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: throw DomainError::notFound('Uživatel nenalezen.');
    }

    private function inactiveUserExists(string $email): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM users WHERE LOWER(email) = ? AND is_active = 0');
        $stmt->execute([mb_strtolower($email)]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null aktivní uživatel s e-mailem */
    private function userByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, email, name, locale FROM users WHERE LOWER(email) = ? AND is_active = 1');
        $stmt->execute([mb_strtolower($email)]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
