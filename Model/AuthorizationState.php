<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Model;

use MageDevGroup\SsoCore\Api\Data\AuthorizationStateInterface;

/**
 * Plain value object carrying the one-time authorization state reloaded from the
 * session on the callback. Unlike sso-core's {@see AuthorizationRequest} it holds
 * no redirect URL — only the `state`/`nonce`/PKCE verifier the callback needs.
 */
class AuthorizationState implements AuthorizationStateInterface
{
    /**
     * @param string $state
     * @param string $nonce
     * @param string $codeVerifier
     */
    public function __construct(
        private readonly string $state,
        private readonly string $nonce,
        private readonly string $codeVerifier
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @inheritDoc
     */
    public function getNonce(): string
    {
        return $this->nonce;
    }

    /**
     * @inheritDoc
     */
    public function getCodeVerifier(): string
    {
        return $this->codeVerifier;
    }
}
