<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Model\Config\Source;

use MageDevGroup\CustomerSso\Model\Config;
use MageDevGroup\CustomerSso\Model\Config\Source\AutoLinkPolicy;
use PHPUnit\Framework\TestCase;

class AutoLinkPolicyTest extends TestCase
{
    public function testExposesBothPoliciesWithVerificationFirst(): void
    {
        $options = (new AutoLinkPolicy())->toOptionArray();

        self::assertSame(
            [Config::AUTO_LINK_REQUIRE_VERIFICATION, Config::AUTO_LINK_AUTO],
            array_column($options, 'value')
        );
        foreach ($options as $option) {
            self::assertNotSame('', (string)$option['label']);
        }
    }
}
