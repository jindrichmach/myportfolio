<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use MyPortfolio\Repository\PasskeyCredentialRepository;
use PDO;

final class MfaStepUpService
{
    public const OPERATION_API_TOKEN_CREATE = 'api_token.create';
    public const OPERATION_PASSKEY_REGISTER = 'passkey.register';

    /** Vygenerování nové sady záložních kódů — stará se tím nevratně zahodí. */
    public const OPERATION_RECOVERY_CODES = 'mfa.recovery_codes';

    private const METHODS = ['passkey', 'totp', 'recovery'];

    /**
     * Co smí autorizovat ZÁLOŽNÍ KÓD. Úmyslně užší množina než u skutečných faktorů:
     * kód neprokazuje držení zařízení, jen znalost tajemství z papíru, takže smí
     * posloužit výhradně k obnově přístupu — odebrat ztracený klíč a zaregistrovat
     * nový. Vydání API tokenu ani práci s podpisovým certifikátem pro daňová podání
     * s ním potvrdit nelze; na to musí uživatel nejdřív obnovit reálný faktor.
     *
     * Regenerace sady tu schválně NENÍ: kdo se dostal dovnitř papírovým kódem, si
     * jím nesmí vyrobit novou sadu donekonečna. Nejdřív reálný faktor, pak nové kódy.
     */
    private const RECOVERY_OPERATIONS = [self::OPERATION_PASSKEY_REGISTER];

    public function __construct(
        private readonly MfaStepUpProofStore $proofs,
        private readonly MfaPolicyService $policy,
        private readonly PasskeyCredentialRepository $credentials,
    ) {}

    public function assertAllowed(int $userId, string $operation, string $authMethod): string
    {
        $operation = trim($operation);
        if (!in_array($authMethod, self::METHODS, true)) {
            throw new StepUpOperationException('Nepodporovaná metoda step-up ověření.');
        }
        if ($operation !== self::OPERATION_API_TOKEN_CREATE
            && $operation !== self::OPERATION_PASSKEY_REGISTER
            && $operation !== self::OPERATION_RECOVERY_CODES
            && preg_match('/^passkey\.revoke:([1-9][0-9]*)$/D', $operation, $matches) !== 1
        ) {
            throw new StepUpOperationException('Nepovolený účel step-up ověření.');
        }

        if ($authMethod === 'recovery') {
            // Odebrání klíče je hlavní důvod, proč kód existuje — bez něj by uživatel
            // sice byl přihlášený, ale ztracený passkey by ze seznamu neodstranil.
            $isRevoke = isset($matches[1]);
            if (!$isRevoke && !in_array($operation, self::RECOVERY_OPERATIONS, true)) {
                throw new StepUpOperationException('Záložní kód tuhle operaci potvrdit nemůže.');
            }
        }

        if (isset($matches[1])) {
            $credentialId = filter_var(
                $matches[1],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]],
            );
            if (!is_int($credentialId)
                || $this->credentials->findActiveForUserById($userId, $credentialId) === null
            ) {
                throw new StepUpOperationException('Nepovolený účel step-up ověření.');
            }
        }

        // Záložní kód schválně NEprochází `allowed_mfa_methods`. Ten seznam říká,
        // čím lze splnit POVINNÉ MFA, ne čím se lze zachránit — a kdyby ho kód musel
        // splnit, byl by k nepotřebě přesně v konfiguraci, která uživatele zamkla ven
        // (`allowed_mfa_methods = ['passkey']` + ztracený klíč). Rozsah operací mu
        // omezuje RECOVERY_OPERATIONS výš.
        if ($authMethod === 'recovery') {
            return $operation;
        }

        $methodAllowed = $this->policy->isMethodAllowed($authMethod);
        if ($operation === self::OPERATION_PASSKEY_REGISTER) {
            $passkeyAllowed = $authMethod === 'passkey'
                ? $methodAllowed
                : $this->policy->isMethodAllowed('passkey');
            $firstPasskeyTransition = !$methodAllowed
                && $authMethod === 'totp'
                && $passkeyAllowed
                && $this->credentials->countActiveForUser($userId) === 0;
            if (!$passkeyAllowed || (!$methodAllowed && !$firstPasskeyTransition)) {
                throw new StepUpOperationException('Tato metoda není pro operaci povolená.');
            }
        } elseif (!$methodAllowed) {
            throw new StepUpOperationException('Tato metoda není pro operaci povolená.');
        }

        return $operation;
    }

    public function issue(
        int $userId,
        string $sessionToken,
        string $operation,
        string $authMethod,
        ?int $authCredentialId = null,
    ): string {
        $operation = $this->assertAllowed($userId, $operation, $authMethod);
        if ($authMethod === 'passkey'
            && ($authCredentialId === null
                || $this->credentials->findActiveForUserById($userId, $authCredentialId) === null)
        ) {
            throw new StepUpOperationException('Passkey pro step-up není aktivní.');
        }
        return $this->proofs->issue(
            $userId,
            $sessionToken,
            $operation,
            $authMethod,
            $authCredentialId,
        );
    }

    public function consume(
        string $proofToken,
        int $userId,
        string $sessionToken,
        string $operation,
    ): MfaStepUpProof {
        $operation = trim($operation);
        $proof = $this->proofs->consume($proofToken, $userId, $sessionToken, $operation);
        $this->assertAllowed($userId, $operation, $proof->authMethod);
        if ($proof->authMethod === 'passkey'
            && ($proof->authCredentialId === null
                || $this->credentials->findActiveForUserById($userId, $proof->authCredentialId) === null)
        ) {
            throw new StepUpOperationException('Passkey pro step-up už není aktivní.');
        }
        return $proof;
    }

    public function consumeInTransaction(
        PDO $pdo,
        SecurityTime $cutoff,
        string $proofToken,
        int $userId,
        string $sessionToken,
        string $operation,
    ): MfaStepUpProof {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Spotřeba step-up proofu vyžaduje aktivní transakci.');
        }

        $operation = trim($operation);
        $proof = $this->proofs->consumeInTransaction(
            $pdo,
            $cutoff,
            $proofToken,
            $userId,
            $sessionToken,
            $operation,
        );
        $this->assertAllowed($userId, $operation, $proof->authMethod);
        if ($proof->authMethod === 'passkey'
            && ($proof->authCredentialId === null
                || $this->credentials->findActiveForUserByIdForUpdate(
                    $pdo,
                    $userId,
                    $proof->authCredentialId,
                ) === null)
        ) {
            throw new StepUpOperationException('Passkey pro step-up už není aktivní.');
        }
        return $proof;
    }
}
