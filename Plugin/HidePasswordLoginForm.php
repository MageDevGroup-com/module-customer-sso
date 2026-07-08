<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Plugin;

use MageDevGroup\CustomerSso\Model\ActiveProviderResolver;
use MageDevGroup\CustomerSso\Model\Config;
use Magento\Customer\Block\Form\Login;

/**
 * Enforce the "Keep Password Login" toggle by hiding the native storefront
 * email/password form when the admin turned it off and SSO is live (enabled with
 * an active provider).
 *
 * Guarding on an active provider keeps the form visible on an unconfigured or
 * broken install, so a mis-set toggle can never lock shoppers out with no way in.
 */
class HidePasswordLoginForm
{
    /**
     * @param Config $config
     * @param ActiveProviderResolver $activeProviderResolver
     */
    public function __construct(
        private readonly Config $config,
        private readonly ActiveProviderResolver $activeProviderResolver
    ) {
    }

    /**
     * Suppress the login form's markup when password login is disabled under SSO.
     *
     * @param Login $subject
     * @param string $result
     */
    public function afterToHtml(Login $subject, string $result): string
    {
        if ($this->isPasswordLoginSuppressed()) {
            return '';
        }

        return $result;
    }

    /**
     * Whether SSO is live and the admin turned native password login off.
     */
    private function isPasswordLoginSuppressed(): bool
    {
        return $this->config->isEnabled()
            && !$this->config->isPasswordLoginAllowed()
            && $this->activeProviderResolver->getActive() !== null;
    }
}
