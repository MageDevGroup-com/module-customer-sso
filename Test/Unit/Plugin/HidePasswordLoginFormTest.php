<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Plugin;

use MageDevGroup\CustomerSso\Model\ActiveProviderResolver;
use MageDevGroup\CustomerSso\Model\Config;
use MageDevGroup\CustomerSso\Plugin\HidePasswordLoginForm;
use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use Magento\Customer\Block\Form\Login;
use PHPUnit\Framework\TestCase;

class HidePasswordLoginFormTest extends TestCase
{
    /** Native login-form markup the plugin either passes through or suppresses. */
    private const FORM = '<form>login</form>';

    private function render(HidePasswordLoginForm $plugin): string
    {
        return $plugin->afterToHtml($this->createStub(Login::class), self::FORM);
    }

    private function plugin(bool $enabled, bool $passwordAllowed, bool $providerActive): HidePasswordLoginForm
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('isPasswordLoginAllowed')->willReturn($passwordAllowed);

        $resolver = $this->createStub(ActiveProviderResolver::class);
        $resolver->method('getActive')->willReturn(
            $providerActive ? $this->createStub(ProviderPresetInterface::class) : null
        );

        return new HidePasswordLoginForm($config, $resolver);
    }

    public function testHidesFormWhenPasswordLoginDisabledUnderLiveSso(): void
    {
        $plugin = $this->plugin(enabled: true, passwordAllowed: false, providerActive: true);

        self::assertSame('', $this->render($plugin));
    }

    public function testKeepsFormWhenPasswordLoginAllowed(): void
    {
        $plugin = $this->plugin(enabled: true, passwordAllowed: true, providerActive: true);

        self::assertSame(self::FORM, $this->render($plugin));
    }

    public function testKeepsFormWhenModuleDisabled(): void
    {
        $plugin = $this->plugin(enabled: false, passwordAllowed: false, providerActive: true);

        self::assertSame(self::FORM, $this->render($plugin));
    }

    public function testKeepsFormWhenNoProviderActive(): void
    {
        $plugin = $this->plugin(enabled: true, passwordAllowed: false, providerActive: false);

        self::assertSame(self::FORM, $this->render($plugin));
    }
}
