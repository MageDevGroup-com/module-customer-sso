<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Model\Oidc;

use MageDevGroup\CustomerSso\Model\ActiveProviderResolver;
use MageDevGroup\CustomerSso\Model\AuthorizationState;
use MageDevGroup\CustomerSso\Model\Config;
use MageDevGroup\CustomerSso\Model\Oidc\CallbackHandler;
use MageDevGroup\SsoCore\Api\AuthorizationStateStorageInterface;
use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use MageDevGroup\SsoCore\Model\Data\Identity;
use MageDevGroup\SsoCore\Model\Oidc\DiscoveryClient;
use MageDevGroup\SsoCore\Model\Oidc\IdentityFactory;
use MageDevGroup\SsoCore\Model\Oidc\IdTokenValidator;
use MageDevGroup\SsoCore\Model\Oidc\JwksClient;
use MageDevGroup\SsoCore\Model\Oidc\ProviderMetadata;
use MageDevGroup\SsoCore\Model\Oidc\TokenClient;
use MageDevGroup\SsoCore\Model\Oidc\TokenResponse;
use Jose\Component\Core\JWKSet;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CallbackHandlerTest extends TestCase
{
    private const CALLBACK_URL = 'https://magento.loc/customersso/sso/callback';
    private const CODE = 'auth-code-abc';
    private const STATE = 'state-token-xyz';
    private const NONCE = 'nonce-123';
    private const VERIFIER = 'pkce-verifier-456';

    /** @var Config&Stub */
    private $config;

    /** @var ActiveProviderResolver&Stub */
    private $resolver;

    /** @var DiscoveryClient&MockObject */
    private $discoveryClient;

    /** @var TokenClient&MockObject */
    private $tokenClient;

    /** @var JwksClient&MockObject */
    private $jwksClient;

    /** @var IdTokenValidator&MockObject */
    private $idTokenValidator;

    /** @var IdentityFactory&MockObject */
    private $identityFactory;

    /** @var AuthorizationStateStorageInterface&MockObject */
    private $stateStorage;

    /** @var UrlInterface&Stub */
    private $url;

    /** @var CallbackHandler */
    private CallbackHandler $handler;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->resolver = $this->createStub(ActiveProviderResolver::class);
        $this->discoveryClient = $this->createMock(DiscoveryClient::class);
        $this->tokenClient = $this->createMock(TokenClient::class);
        $this->jwksClient = $this->createMock(JwksClient::class);
        $this->idTokenValidator = $this->createMock(IdTokenValidator::class);
        $this->identityFactory = $this->createMock(IdentityFactory::class);
        $this->stateStorage = $this->createMock(AuthorizationStateStorageInterface::class);
        $this->url = $this->createStub(UrlInterface::class);

        $this->handler = new CallbackHandler(
            $this->config,
            $this->resolver,
            $this->discoveryClient,
            $this->tokenClient,
            $this->jwksClient,
            $this->idTokenValidator,
            $this->identityFactory,
            $this->stateStorage,
            $this->url
        );
    }

    private function preset(): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('buildDiscoveryUrl')->willReturn('https://idp.example/.well-known/openid-configuration');

        return $preset;
    }

    private function metadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            'https://idp.example',
            'https://idp.example/authorize',
            'https://idp.example/token',
            'https://idp.example/jwks'
        );
    }

    /**
     * Assert the OIDC exchange never begins — used by the guard-clause tests where
     * the handler must bail out before touching the IdP.
     */
    private function assertExchangeNotStarted(): void
    {
        $this->discoveryClient->expects(self::never())->method('discover');
        $this->tokenClient->expects(self::never())->method('exchangeCode');
        $this->jwksClient->expects(self::never())->method('getKeySet');
        $this->idTokenValidator->expects(self::never())->method('validate');
        $this->identityFactory->expects(self::never())->method('create');
    }

    public function testHandleReturnsNormalizedIdentity(): void
    {
        $preset = $this->preset();
        $keySet = new JWKSet([]);
        $claims = ['sub' => 'user-1', 'email' => 'user@example.com', 'nonce' => self::NONCE];
        $identity = new Identity('user-1', 'user@example.com', 'User One', ['shoppers']);

        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getClientId')->willReturn('cid');
        $this->config->method('getClientSecret')->willReturn('secret');
        $this->resolver->method('getActive')->willReturn($preset);
        $this->url->method('getUrl')->willReturn(self::CALLBACK_URL);

        $this->stateStorage->expects(self::once())
            ->method('consume')
            ->with(self::STATE)
            ->willReturn(new AuthorizationState(self::STATE, self::NONCE, self::VERIFIER));

        $this->discoveryClient->expects(self::once())
            ->method('discover')
            ->with('https://idp.example/.well-known/openid-configuration')
            ->willReturn($this->metadata());

        $this->tokenClient->expects(self::once())
            ->method('exchangeCode')
            ->with(
                'https://idp.example/token',
                self::CODE,
                self::CALLBACK_URL,
                'cid',
                self::VERIFIER,
                'secret'
            )
            ->willReturn(new TokenResponse('the-id-token'));

        $this->jwksClient->expects(self::once())
            ->method('getKeySet')
            ->with('https://idp.example/jwks')
            ->willReturn($keySet);

        $this->idTokenValidator->expects(self::once())
            ->method('validate')
            ->with('the-id-token', $keySet, 'https://idp.example', 'cid', self::NONCE)
            ->willReturn($claims);

        $this->identityFactory->expects(self::once())
            ->method('create')
            ->with($claims, $preset)
            ->willReturn($identity);

        self::assertSame($identity, $this->handler->handle(self::CODE, self::STATE));
    }

    public function testThrowsWhenDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->stateStorage->expects(self::never())->method('consume');
        $this->assertExchangeNotStarted();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Customer SSO is disabled.');

        $this->handler->handle(self::CODE, self::STATE);
    }

    public function testThrowsWhenNoProviderActive(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->resolver->method('getActive')->willReturn(null);
        $this->stateStorage->expects(self::never())->method('consume');
        $this->assertExchangeNotStarted();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No customer SSO provider is configured.');

        $this->handler->handle(self::CODE, self::STATE);
    }

    public function testThrowsWhenClientIdMissing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getClientId')->willReturn(null);
        $this->resolver->method('getActive')->willReturn($this->preset());
        $this->stateStorage->expects(self::never())->method('consume');
        $this->assertExchangeNotStarted();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The customer SSO client ID is not configured.');

        $this->handler->handle(self::CODE, self::STATE);
    }

    public function testThrowsWhenStateUnknownOrReplayed(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getClientId')->willReturn('cid');
        $this->resolver->method('getActive')->willReturn($this->preset());

        $this->stateStorage->expects(self::once())
            ->method('consume')
            ->with(self::STATE)
            ->willReturn(null);

        // No exchange once the state is rejected (expired/forged/replayed).
        $this->assertExchangeNotStarted();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The SSO sign-in session is invalid or has expired. Please try again.');

        $this->handler->handle(self::CODE, self::STATE);
    }
}
