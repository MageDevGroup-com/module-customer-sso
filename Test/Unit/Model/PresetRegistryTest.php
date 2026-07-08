<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Model;

use MageDevGroup\CustomerSso\Model\PresetRegistry;
use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

class PresetRegistryTest extends TestCase
{
    private function preset(string $code): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('getCode')->willReturn($code);

        return $preset;
    }

    public function testGetAllIndexesRegisteredPresetsByCode(): void
    {
        $okta = $this->preset('okta');
        $google = $this->preset('google');

        $registry = new PresetRegistry([$okta, $google]);

        self::assertSame(
            ['okta' => $okta, 'google' => $google],
            $registry->getAll()
        );
    }

    public function testGetAllIsEmptyWhenNoPresetsRegistered(): void
    {
        self::assertSame([], (new PresetRegistry())->getAll());
    }

    public function testGetReturnsRegisteredPreset(): void
    {
        $okta = $this->preset('okta');
        $registry = new PresetRegistry([$okta]);

        self::assertTrue($registry->has('okta'));
        self::assertSame($okta, $registry->get('okta'));
    }

    public function testGetThrowsForUnregisteredCode(): void
    {
        $registry = new PresetRegistry([$this->preset('okta')]);

        self::assertFalse($registry->has('google'));
        $this->expectException(NoSuchEntityException::class);
        $registry->get('google');
    }

    public function testConstructorRejectsNonPreset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentional wrong type for the guard */
        new PresetRegistry([new \stdClass()]);
    }
}
