<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Model\Config\Source;

use MageDevGroup\CustomerSso\Model\PresetRegistry;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Active-provider dropdown source for the storefront SSO config field.
 *
 * Options are built from the {@see PresetRegistry} — i.e. whatever provider
 * plugins registered their preset — so the list grows as plugins are installed
 * without this core knowing any IdP.
 */
class ActiveProvider implements OptionSourceInterface
{
    /**
     * @param PresetRegistry $presetRegistry
     */
    public function __construct(
        private readonly PresetRegistry $presetRegistry
    ) {
    }

    /**
     * @inheritDoc
     *
     * @return array<int,array{value:string,label:\Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('-- Please Select --')]];
        foreach ($this->presetRegistry->getAll() as $preset) {
            $options[] = ['value' => $preset->getCode(), 'label' => $preset->getLabel()];
        }

        return $options;
    }
}
