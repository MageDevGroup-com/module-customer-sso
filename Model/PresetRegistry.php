<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Model;

use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Open/closed extension seam: the di-merged collection of provider presets.
 *
 * Provider plugins (`customer-sso-okta`, `customer-sso-google`, …) register their
 * {@see ProviderPresetInterface} into the `presets` array argument via di.xml.
 * This core holds no IdP-specific code — it only indexes what plugins supply,
 * keyed by each preset's stable machine code.
 */
class PresetRegistry
{
    /** @var array<string,ProviderPresetInterface> */
    private array $presets = [];

    /**
     * @param ProviderPresetInterface[] $presets di-merged provider presets
     */
    public function __construct(array $presets = [])
    {
        foreach ($presets as $preset) {
            if (!$preset instanceof ProviderPresetInterface) {
                throw new \InvalidArgumentException(
                    'Registered preset must implement ' . ProviderPresetInterface::class . '.'
                );
            }
            $this->presets[$preset->getCode()] = $preset;
        }
    }

    /**
     * Whether a preset is registered for the given code.
     *
     * @param string $code
     */
    public function has(string $code): bool
    {
        return isset($this->presets[$code]);
    }

    /**
     * Registered preset for the given code.
     *
     * @param string $code
     * @throws NoSuchEntityException when no preset is registered for the code
     */
    public function get(string $code): ProviderPresetInterface
    {
        if (!isset($this->presets[$code])) {
            throw new NoSuchEntityException(
                __('No SSO provider preset is registered for code "%1".', $code)
            );
        }

        return $this->presets[$code];
    }

    /**
     * All registered presets, keyed by their machine code.
     *
     * @return array<string,ProviderPresetInterface>
     */
    public function getAll(): array
    {
        return $this->presets;
    }
}
