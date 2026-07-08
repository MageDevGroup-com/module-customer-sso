<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Model;

use MageDevGroup\CustomerSso\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values, array $decrypt = []): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(static fn (string $path) => $values[$path] ?? null);
        $scopeConfig->method('isSetFlag')
            ->willReturnCallback(static fn (string $path) => (bool)($values[$path] ?? false));

        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')
            ->willReturnCallback(static fn (string $value) => $decrypt[$value] ?? '');

        return new Config($scopeConfig, $encryptor);
    }

    public function testFlagsReadThroughIsSetFlag(): void
    {
        $config = $this->config([
            Config::XML_PATH_ENABLED => '1',
            Config::XML_PATH_ALLOW_PASSWORD_LOGIN => '1',
        ]);

        self::assertTrue($config->isEnabled());
        self::assertTrue($config->isPasswordLoginAllowed());
    }

    public function testFlagsDefaultToFalseWhenUnset(): void
    {
        $config = $this->config([]);

        self::assertFalse($config->isEnabled());
        self::assertFalse($config->isPasswordLoginAllowed());
    }

    public function testReadsAreStoreScoped(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('getValue')
            ->with(Config::XML_PATH_CLIENT_ID, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn('cid');
        $scopeConfig->expects(self::once())
            ->method('isSetFlag')
            ->with(Config::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(true);

        $config = new Config($scopeConfig, $this->createStub(EncryptorInterface::class));

        self::assertTrue($config->isEnabled(7));
        self::assertSame('cid', $config->getClientId(7));
    }

    public function testGetClientIdTrimsAndTreatsBlankAsNull(): void
    {
        self::assertSame('cid-123', $this->config([Config::XML_PATH_CLIENT_ID => '  cid-123 '])->getClientId());
        self::assertNull($this->config([Config::XML_PATH_CLIENT_ID => '   '])->getClientId());
        self::assertNull($this->config([])->getClientId());
    }

    public function testGetClientSecretDecryptsStoredValue(): void
    {
        $config = $this->config(
            [Config::XML_PATH_CLIENT_SECRET => 'enc:blob'],
            ['enc:blob' => 'super-secret']
        );

        self::assertSame('super-secret', $config->getClientSecret());
    }

    public function testGetClientSecretReturnsNullWhenUnsetOrEmptyAfterDecrypt(): void
    {
        self::assertNull($this->config([])->getClientSecret());
        self::assertNull($this->config([Config::XML_PATH_CLIENT_SECRET => ''])->getClientSecret());
        // Stored but decrypts to empty (e.g. key rotation) → null, not a stray value.
        self::assertNull($this->config([Config::XML_PATH_CLIENT_SECRET => 'stale'])->getClientSecret());
    }

    public function testGetAutoLinkPolicyReturnsAutoOnlyWhenExplicitlyAuto(): void
    {
        self::assertSame(
            Config::AUTO_LINK_AUTO,
            $this->config([Config::XML_PATH_AUTO_LINK_POLICY => 'auto'])->getAutoLinkPolicy()
        );
        self::assertTrue($this->config([Config::XML_PATH_AUTO_LINK_POLICY => 'auto'])->isAutoLinkByEmailAllowed());
    }

    public function testGetAutoLinkPolicyFallsBackToRequireVerification(): void
    {
        self::assertSame(
            Config::AUTO_LINK_REQUIRE_VERIFICATION,
            $this->config([])->getAutoLinkPolicy()
        );
        self::assertSame(
            Config::AUTO_LINK_REQUIRE_VERIFICATION,
            $this->config([Config::XML_PATH_AUTO_LINK_POLICY => 'nonsense'])->getAutoLinkPolicy()
        );
        self::assertFalse($this->config([])->isAutoLinkByEmailAllowed());
    }

    public function testGetGroupCustomerGroupMapParsesLines(): void
    {
        $raw = "# wholesale customers\nStaff=3\r\n vip = 2 \n\nbad-line-no-equals\n=4\nfoo=\n";
        $config = $this->config([Config::XML_PATH_GROUP_MAP => $raw]);

        self::assertSame(['Staff' => '3', 'vip' => '2'], $config->getGroupCustomerGroupMap());
    }

    public function testGetGroupCustomerGroupMapLastDuplicateWins(): void
    {
        $config = $this->config([Config::XML_PATH_GROUP_MAP => "team=1\nteam=5"]);

        self::assertSame(['team' => '5'], $config->getGroupCustomerGroupMap());
    }

    public function testGetGroupCustomerGroupMapEmptyWhenUnsetOrBlank(): void
    {
        self::assertSame([], $this->config([])->getGroupCustomerGroupMap());
        self::assertSame([], $this->config([Config::XML_PATH_GROUP_MAP => "  \n \n"])->getGroupCustomerGroupMap());
    }
}
