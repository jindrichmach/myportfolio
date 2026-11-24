<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Repository\PasskeyCredentialRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * MFA step-up pro admin operace (TOTP kód nebo jednorázový proof z passkey/TOTP) jako u vytvoření API tokenu;
 * admin bez zaregistrovaného faktoru ho nepotřebuje. Vrací chybovou odpověď, nebo null při úspěchu.
 */
final class StepUpGate
{
    public function __construct(
        private readonly Connection $db,
        private readonly TotpVerifier $totp,
        private readonly SecretEncryption $crypto,
        private readonly BruteForceGuard $bruteForce,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly MfaProtectedOperationService $protectedOperations,
    ) {}

    /** @param array<string,mixed> $body */
    public function check(Request $request, Response $response, int $userId, array $body, string $operation, string $requiredMessage = 'Pro tuto operaci je vyžadováno nové ověření.'): ?Response
    {
        $totpCode = trim((string) ($body['totp_code'] ?? ''));
        $proof = trim((string) ($body['step_up_token'] ?? ''));

        $stmt = $this->db->pdo()->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $u = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $totpAvailable = (int) ($u['totp_enabled'] ?? 0) === 1 && !empty($u['totp_secret']);
        $passkeyAvailable = $this->mfaPolicy->isMethodAllowed('passkey')
            && $this->credentials->countActiveForUser($userId) > 0;

        if ($proof !== '') {
            try {
                $this->protectedOperations->consumeProof(
                    $userId,
                    (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                    $proof,
                    $operation,
                );
            } catch (OneTimeTokenException|StepUpOperationException) {
                return Json::error($response, 'step_up_proof_invalid', 'Step-up ověření je neplatné nebo již bylo použito.', 403);
            } catch (\DomainException) {
                return Json::error($response, 'session_expired', 'Session vypršela.', 401);
            }
            return null;
        }
        if (!$totpAvailable && !$passkeyAvailable) {
            return null;
        }
        if ($totpAvailable && $totpCode !== '') {
            if ($this->bruteForce->isTotpLocked($userId)) {
                return Json::error($response, 'too_many_attempts', 'Příliš mnoho TOTP pokusů. Zkus to později.', 429);
            }
            try {
                $secret = $this->crypto->decrypt((string) $u['totp_secret'], SecretEncryption::context('users.totp_secret', $userId));
            } catch (\RuntimeException) {
                return Json::error($response, 'server_error', 'Chyba konfigurace serveru.', 500);
            }
            if (!$this->totp->verify($userId, $secret, $totpCode)) {
                $this->bruteForce->recordTotpFailure($userId);
                return Json::error($response, 'step_up_proof_invalid', 'Neplatný TOTP kód.', 403);
            }
            $this->bruteForce->recordTotpSuccess($userId);
            return null;
        }

        return Json::error(
            $response,
            'step_up_required',
            $requiredMessage,
            403,
            ['methods' => array_values(array_filter([$passkeyAvailable ? 'passkey' : null, $totpAvailable ? 'totp' : null]))],
        );
    }
}
