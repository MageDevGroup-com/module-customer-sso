<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Model\Oidc;

use MageDevGroup\CustomerSso\Model\ActiveProviderResolver;
use MageDevGroup\CustomerSso\Model\Config;
use MageDevGroup\CustomerSso\Model\Oidc\AuthorizationStarter;
use MageDevGroup\SsoCore\Api\AuthorizationStateStorageInterface;
use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use MageDevGroup\SsoCore\Model\Oidc\AuthorizationRequest;
use MageDevGroup\SsoCore\Model\Oidc\AuthorizationRequestFactory;
use MageDevGroup\SsoCore\Model\Oidc\DiscoveryClient;
use MageDevGroup\SsoCore\Model\Oidc\ProviderMetadata;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class AuthorizationStarterTest extends TestCase
{
    private const CALLBACK_URL = 'https://magento.loc/customersso/sso/callback';
    private const AUTH_URL = 'https://idp.example/authorize?client_id=cid&state=abc';

    /** @var Config&Stub */
    private $config;

    /** @var ActiveProviderResolver&Stub */
    private $resolver;

    /** @var DiscoveryClient&Stub */
    private $discoveryClient;

    /** @var UrlInterface&Stub */
    private $url;

    /** @var AuthorizationRequestFactory&MockObject */
    private $requestFactory;

    /** @var AuthorizationStateStorageInterface&MockObject */
    private $stateStorage;

    /** @var AuthorizationStarter */
    private AuthorizationStarter $starter;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->resolver = $this->createStub(ActiveProviderResolver::class);
        $this->discoveryClient = $this->createStub(DiscoveryClient::class);
        $this->url = $this->createStub(UrlInterface::class);
        $this->requestFactory = $this->createMock(AuthorizationRequestFactory::class);
        $this->stateStorage = $this->createMock(AuthorizationStateStorageInterface::class);

        $this->starter = new AuthorizationStarter(
            $this->config,
            $this->resolver,
            $this->discoveryClient,
            $this->requestFactory,
            $this->stateStorage,
            $this->url
        );
    }

    private function preset(): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('buildDiscoveryUrl')->willReturn('https://idp.example/.well-known/openid-configuration');
        $preset->method('getDefaultScopes')->willReturn(['openid', 'email', 'groups']);

        return $preset;
    }

    public function testStartBuildsAuthUrlAndPersistsState(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getClientId')->willReturn('cid');
        $this->resolver->method('getActive')->willReturn($this->preset());
        $this->url->method('getUrl')->willReturn(self::CALLBACK_URL);
        $this->discoveryClient->method('discover')
            ->willReturn(new ProviderMetadata(
                'https://idp.example',
                'https://idp.example/authorize',
                'https://idp.example/token',
                'https://idp.example/jwks'
            ));

        $request = new AuthorizationRequest(self::AUTH_URL, 'abc', 'nnn', 'vvv');
        $this->requestFactory->expects(self::once())
            ->method('create')
            ->with(
                'https://idp.example/authorize',
                'cid',
                self::CALLBACK_URL,
                ['openid', 'email', 'groups']
            )
            ->willReturn($request);

        $this->stateStorage->expects(self::once())
            ->method('save')
            ->with($request);

        self::assertSame(self::AUTH_URL, $this->starter->start());
    }

    public function testStartThrowsWhenDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->requestFactory->expects(self::never())->method('create');
        $this->stateStorage->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Customer SSO is disabled.');

        $this->starter->start();
    }

    public function testStartThrowsWhenNoProviderActive(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->resolver->method('getActive')->willReturn(null);
        $this->requestFactory->expects(self::never())->method('create');
        $this->stateStorage->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No customer SSO provider is configured.');

        $this->starter->start();
    }

    public function testStartThrowsWhenClientIdMissing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getClientId')->willReturn(null);
        $this->resolver->method('getActive')->willReturn($this->preset());
        $this->requestFactory->expects(self::never())->method('create');
        $this->stateStorage->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The customer SSO client ID is not configured.');

        $this->starter->start();
    }
}
