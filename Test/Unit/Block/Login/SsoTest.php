<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Block\Login;

use MageDevGroup\CustomerSso\Block\Login\Sso;
use MageDevGroup\CustomerSso\Model\ActiveProviderResolver;
use MageDevGroup\CustomerSso\Model\Config;
use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\TestCase;

class SsoTest extends TestCase
{
    private function preset(string $buttonLabel, ?string $iconUrl): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('getButtonLabel')->willReturn($buttonLabel);
        $preset->method('getButtonIconUrl')->willReturn($iconUrl);

        return $preset;
    }

    private function block(
        bool $enabled,
        ?ProviderPresetInterface $active,
        ?UrlInterface $url = null
    ): Sso {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        $resolver = $this->createStub(ActiveProviderResolver::class);
        $resolver->method('getActive')->willReturn($active);

        return (new ObjectManager($this))->getObject(Sso::class, [
            'context' => $this->createStub(Context::class),
            'config' => $config,
            'activeProviderResolver' => $resolver,
            'url' => $url ?? $this->createStub(UrlInterface::class),
        ]);
    }

    public function testAvailableWhenEnabledAndProviderActive(): void
    {
        $block = $this->block(true, $this->preset('Sign in with Okta', 'https://cdn/okta.svg'));

        self::assertTrue($block->isAvailable());
        self::assertSame('Sign in with Okta', $block->getButtonLabel());
        self::assertSame('https://cdn/okta.svg', $block->getButtonIconUrl());
    }

    public function testHiddenWhenModuleDisabled(): void
    {
        $block = $this->block(false, $this->preset('Sign in with Okta', null));

        self::assertFalse($block->isAvailable());
    }

    public function testHiddenWhenNoProviderActive(): void
    {
        $block = $this->block(true, null);

        self::assertFalse($block->isAvailable());
        self::assertSame('', $block->getButtonLabel());
        self::assertNull($block->getButtonIconUrl());
    }

    public function testStartUrlBuiltFromRoute(): void
    {
        $url = $this->createMock(UrlInterface::class);
        $url->expects(self::once())
            ->method('getUrl')
            ->with('customersso/sso/start')
            ->willReturn('https://magento.loc/customersso/sso/start');

        $block = $this->block(true, $this->preset('Sign in with Okta', null), $url);

        self::assertSame('https://magento.loc/customersso/sso/start', $block->getStartUrl());
    }

    public function testRendersNothingWhenNotAvailable(): void
    {
        $block = $this->block(false, null);

        $toHtml = new \ReflectionMethod($block, '_toHtml');

        self::assertSame('', $toHtml->invoke($block));
    }
}
