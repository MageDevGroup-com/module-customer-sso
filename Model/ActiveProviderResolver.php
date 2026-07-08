<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Model;

use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves the admin-selected active provider into its registered preset.
 *
 * The active provider code is stored in storefront-scoped config (set in the
 * admin UI); this maps it to the concrete {@see ProviderPresetInterface} a plugin
 * registered into the {@see PresetRegistry}. Returns null when nothing is selected
 * or the selected code has no registered preset (e.g. its plugin was removed).
 */
class ActiveProviderResolver
{
    /** Config path holding the admin-selected active provider code. */
    public const XML_PATH_ACTIVE_PROVIDER = 'magedevgroup_customer_sso/general/active_provider';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param PresetRegistry $presetRegistry
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PresetRegistry $presetRegistry
    ) {
    }

    /**
     * Configured active provider code for the given store, or null when unset.
     *
     * @param int|string|null $storeId
     */
    public function getActiveCode($storeId = null): ?string
    {
        $code = $this->scopeConfig->getValue(
            self::XML_PATH_ACTIVE_PROVIDER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $code = is_string($code) ? trim($code) : '';

        return $code === '' ? null : $code;
    }

    /**
     * Active provider preset for the given store, or null when none applies.
     *
     * Null when no provider is selected or the selected one is not registered.
     *
     * @param int|string|null $storeId
     */
    public function getActive($storeId = null): ?ProviderPresetInterface
    {
        $code = $this->getActiveCode($storeId);
        if ($code === null || !$this->presetRegistry->has($code)) {
            return null;
        }

        return $this->presetRegistry->get($code);
    }
}
