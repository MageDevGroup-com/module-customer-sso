<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader over the module's storefront configuration.
 *
 * Wraps {@see ScopeConfigInterface} so the rest of the module never touches raw
 * config paths, and decrypts the OIDC client secret (stored encrypted via the
 * Magento `Encrypted` backend model). Reads are store-scoped: storefront SSO can
 * be enabled/tuned per store view. Provider-neutral — the concrete IdP comes from
 * the active preset, resolved by {@see ActiveProviderResolver}.
 */
class Config
{
    /** Whether customer SSO is enabled. */
    public const XML_PATH_ENABLED = 'magedevgroup_customer_sso/general/enabled';

    /** OIDC client (application) id issued by the IdP. */
    public const XML_PATH_CLIENT_ID = 'magedevgroup_customer_sso/general/client_id';

    /** OIDC client secret, stored encrypted. */
    public const XML_PATH_CLIENT_SECRET = 'magedevgroup_customer_sso/general/client_secret';

    /** Whether the native email/password login form stays visible alongside SSO. */
    public const XML_PATH_ALLOW_PASSWORD_LOGIN = 'magedevgroup_customer_sso/general/allow_password_login';

    /** Email account-linking policy (auto-link vs require verification). */
    public const XML_PATH_AUTO_LINK_POLICY = 'magedevgroup_customer_sso/general/auto_link_policy';

    /** IdP-group → customer-group rules, one `group=customer_group_id` per line. */
    public const XML_PATH_GROUP_MAP = 'magedevgroup_customer_sso/general/group_customer_group_map';

    /** Take over a matching customer by email without further proof. */
    public const AUTO_LINK_AUTO = 'auto';

    /** Refuse to link by email until the email is proven (safe default). */
    public const AUTO_LINK_REQUIRE_VERIFICATION = 'require_verification';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Whether customer SSO is enabled for the given store.
     *
     * @param int|string|null $storeId
     */
    public function isEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Configured OIDC client id, or null when unset.
     *
     * @param int|string|null $storeId
     */
    public function getClientId($storeId = null): ?string
    {
        return $this->readNonEmptyString(self::XML_PATH_CLIENT_ID, $storeId);
    }

    /**
     * Decrypted OIDC client secret, or null when unset.
     *
     * @param int|string|null $storeId
     */
    public function getClientSecret($storeId = null): ?string
    {
        $encrypted = $this->scopeConfig->getValue(
            self::XML_PATH_CLIENT_SECRET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if (!is_string($encrypted) || $encrypted === '') {
            return null;
        }

        $decrypted = $this->encryptor->decrypt($encrypted);

        return $decrypted === '' ? null : $decrypted;
    }

    /**
     * Whether the native email/password login form stays visible alongside SSO.
     *
     * @param int|string|null $storeId
     */
    public function isPasswordLoginAllowed($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ALLOW_PASSWORD_LOGIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Configured email-linking policy; falls back to the safe
     * {@see self::AUTO_LINK_REQUIRE_VERIFICATION} when unset or unrecognized.
     *
     * @param int|string|null $storeId
     */
    public function getAutoLinkPolicy($storeId = null): string
    {
        $value = $this->readNonEmptyString(self::XML_PATH_AUTO_LINK_POLICY, $storeId);

        return $value === self::AUTO_LINK_AUTO ? self::AUTO_LINK_AUTO : self::AUTO_LINK_REQUIRE_VERIFICATION;
    }

    /**
     * Whether a matching customer may be linked by email without verification.
     *
     * @param int|string|null $storeId
     */
    public function isAutoLinkByEmailAllowed($storeId = null): bool
    {
        return $this->getAutoLinkPolicy($storeId) === self::AUTO_LINK_AUTO;
    }

    /**
     * Parsed IdP-group → customer-group-id map.
     *
     * Reads the `group=customer_group_id` lines; blank lines and `#` comments are
     * ignored, later entries win on duplicate groups. The actual assignment is
     * applied by {@see GroupAssigner}; this only exposes the rules.
     *
     * @param int|string|null $storeId
     * @return array<string,string> group name → customer group id
     */
    public function getGroupCustomerGroupMap($storeId = null): array
    {
        $raw = $this->scopeConfig->getValue(
            self::XML_PATH_GROUP_MAP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $map = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$group, $groupId] = explode('=', $line, 2);
            $group = trim($group);
            $groupId = trim($groupId);
            if ($group !== '' && $groupId !== '') {
                $map[$group] = $groupId;
            }
        }

        return $map;
    }

    /**
     * Read a store-scoped config value as a trimmed non-empty string, or null.
     *
     * @param string $path
     * @param int|string|null $storeId
     */
    private function readNonEmptyString(string $path, $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
