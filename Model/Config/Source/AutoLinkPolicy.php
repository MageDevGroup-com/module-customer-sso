<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Model\Config\Source;

use MageDevGroup\CustomerSso\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Dropdown source for the email account-linking policy.
 *
 * Governs what happens when an SSO identity's email matches an existing
 * customer that isn't yet linked by `sub`: silently take it over (auto-link)
 * or refuse until the email is proven (require verification). The safe default
 * is verification — see {@see Config::AUTO_LINK_REQUIRE_VERIFICATION}.
 */
class AutoLinkPolicy implements OptionSourceInterface
{
    /**
     * @inheritDoc
     *
     * @return array<int,array{value:string,label:\Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::AUTO_LINK_REQUIRE_VERIFICATION,
                'label' => __('Require verification'),
            ],
            [
                'value' => Config::AUTO_LINK_AUTO,
                'label' => __('Auto-link by email'),
            ],
        ];
    }
}
