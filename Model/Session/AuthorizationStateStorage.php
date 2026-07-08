<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Model\Session;

use MageDevGroup\CustomerSso\Model\AuthorizationState;
use MageDevGroup\SsoCore\Api\AuthorizationStateStorageInterface;
use MageDevGroup\SsoCore\Api\Data\AuthorizationStateInterface;
use Magento\Customer\Model\Session;

/**
 * Customer-session-backed store for the per-request OIDC authorization state.
 *
 * The start controller {@see save}s the state (keyed by its `state` token) before
 * redirecting to the IdP; the callback controller {@see consume}s it once. Entries
 * are held in the storefront customer session under a single session key so several
 * in-flight logins from one browser don't clobber each other. Consuming an entry
 * removes it, giving replay protection.
 */
class AuthorizationStateStorage implements AuthorizationStateStorageInterface
{
    /** Session key holding the map of pending states, keyed by `state` token. */
    private const SESSION_KEY = 'magedevgroup_customer_sso_auth_states';

    /**
     * @param Session $session
     */
    public function __construct(
        private readonly Session $session
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(AuthorizationStateInterface $state): void
    {
        $states = $this->load();
        $states[$state->getState()] = [
            'nonce' => $state->getNonce(),
            'code_verifier' => $state->getCodeVerifier(),
        ];
        $this->session->setData(self::SESSION_KEY, $states);
    }

    /**
     * @inheritDoc
     */
    public function consume(string $state): ?AuthorizationStateInterface
    {
        $states = $this->load();
        if (!isset($states[$state])) {
            return null;
        }

        $entry = $states[$state];
        unset($states[$state]);
        $this->session->setData(self::SESSION_KEY, $states);

        return new AuthorizationState(
            $state,
            (string)($entry['nonce'] ?? ''),
            (string)($entry['code_verifier'] ?? '')
        );
    }

    /**
     * Load the pending-state map from the session.
     *
     * @return array<string,array{nonce:string,code_verifier:string}>
     */
    private function load(): array
    {
        $states = $this->session->getData(self::SESSION_KEY);

        return is_array($states) ? $states : [];
    }
}
