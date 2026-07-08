<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Model\Config\Source;

use MageDevGroup\CustomerSso\Model\Config\Source\ActiveProvider;
use MageDevGroup\CustomerSso\Model\PresetRegistry;
use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use PHPUnit\Framework\TestCase;

class ActiveProviderTest extends TestCase
{
    private function preset(string $code, string $label): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('getCode')->willReturn($code);
        $preset->method('getLabel')->willReturn($label);

        return $preset;
    }

    public function testFirstOptionIsAlwaysAnEmptyPlaceholder(): void
    {
        $source = new ActiveProvider(new PresetRegistry([]));

        $options = $source->toOptionArray();
        self::assertCount(1, $options);
        self::assertSame('', $options[0]['value']);
    }

    public function testBuildsOneOptionPerRegisteredPreset(): void
    {
        $source = new ActiveProvider(new PresetRegistry([
            $this->preset('okta', 'Okta'),
            $this->preset('google', 'Google'),
        ]));

        $options = $source->toOptionArray();

        self::assertSame('', $options[0]['value']);
        self::assertSame(['value' => 'okta', 'label' => 'Okta'], self::normalize($options[1]));
        self::assertSame(['value' => 'google', 'label' => 'Google'], self::normalize($options[2]));
    }

    private static function normalize(array $option): array
    {
        return ['value' => $option['value'], 'label' => (string)$option['label']];
    }
}
