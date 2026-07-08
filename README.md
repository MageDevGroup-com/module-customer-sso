# MageDevGroup_CustomerSso

> Provider-agnostic single sign-on for the Magento 2 storefront (OIDC).

![License](https://img.shields.io/badge/license-OSL--3.0-green) ![Magento](https://img.shields.io/badge/Magento-2.4-orange) ![PHP](https://img.shields.io/badge/PHP-8.3--8.5-blue) ![Version](https://img.shields.io/badge/version-0.0.1-lightgrey)

This is the storefront-login capability **core**: it logs shoppers in over OIDC but never references a concrete IdP. Install a provider plugin (e.g. `customer-sso-okta`) to bind it to an identity provider.

## Features

- **JIT customer creation** — an unknown identity provisions a new `customer_entity` on first sign-in.
- **Account linking** by IdP `sub` first, then by email under policy.
- **Group mapping** — an IdP claim maps to a Magento customer group.
- **Coexists with password login** — the native email/password form stays available (configurable).

## Installation

Usually installed via a provider plugin, which pulls this core (which pulls `sso-core`):

```bash
composer require magedevgroup/module-customer-sso-okta
bin/magento module:enable MageDevGroup_CustomerSsoOkta MageDevGroup_CustomerSso MageDevGroup_SsoCore
bin/magento setup:upgrade
```

Direct install of the core alone:

```bash
composer require magedevgroup/module-customer-sso
bin/magento module:enable MageDevGroup_SsoCore MageDevGroup_CustomerSso
bin/magento setup:upgrade
```

Register the module callback URL in your IdP: `https://<store-host>/customersso/sso/callback`. It must exactly match the storefront URL used at runtime.

## Configuration

Admin → Stores → Configuration → **MageDevGroup → Customer SSO → General** (config path `magedevgroup_customer_sso/general/*`). Settings are store-scoped, so SSO can be enabled and tuned per store view.

| Field | Path | Notes |
|---|---|---|
| Enable Customer SSO | `enabled` | Master switch; off by default. |
| Identity Provider | `active_provider` | Dropdown populated by installed provider plugins. |
| Client ID | `client_id` | OIDC client id from the IdP. |
| Client Secret | `client_secret` | Stored encrypted. |
| Keep Password Login | `allow_password_login` | On (default): native email/password form stays visible alongside SSO. Off: form is hidden once SSO is live, making login SSO-only. |
| Account Linking by Email | `auto_link_policy` | `auto` vs `require_verification`. See below. |
| Group to Customer-Group Map | `group_customer_group_map` | IdP group → customer-group rules. See below. |

The "Sign in with SSO" button appears on the customer login page when the module is enabled and a provider is selected; it uses the active preset's branding. Setting *Keep Password Login* to *No* hides the native form once SSO is live. As a break-glass, the form stays visible on an unconfigured or broken install (no active provider), so a mis-set toggle can never lock shoppers out.

## Account linking

An SSO identity is matched to a customer in this order:

1. **By IdP `sub`** — the stable subject stored in `magedevgroup_customer_sso_subject` on first link. A customer is EAV/API-backed, so the link lives in its own table rather than a column on the entity.
2. **By email**, governed by `auto_link_policy`:
   - `auto` — a matching email signs straight into the existing customer.
   - `require_verification` (default) — email linking is refused until the email is proven, preventing account takeover via an unverified IdP email.
3. **JIT create** — no match → a new `customer_entity` is provisioned from the identity, and the `sub` is stored for stable re-login.

`require_verification` is the safe default; switch to `auto` only when the IdP guarantees verified emails.

## Group → customer-group mapping

`group_customer_group_map` takes one rule per line as `idp_group=customer_group_id`, where `customer_group_id` is a Magento `customer_group` id. Blank lines and `#` comments are ignored; on duplicate groups the later line wins.

```
# IdP group          Customer group id
wholesale=2
vip=3
```

With no rules configured this is inert: customer groups are never touched, so a store using SSO only for login keeps its manually assigned groups. Once a map exists, groups are resolved on JIT create and refreshed on every login, so IdP group changes take effect at next sign-in. An identity whose groups match no rule reverts to the store's default customer group (`GroupManagementInterface::getDefaultGroup`) — customers always hold a valid group. A rule pointing at a non-existent group id is skipped, leaving the current group unchanged.

## How it works

1. Shopper clicks "Sign in with SSO" → `customersso/sso/start` builds the OIDC auth URL (state + nonce + PKCE) via sso-core and the active preset, persists that state in the customer session, then redirects to the IdP.
2. IdP redirects back to `customersso/sso/callback`, which validates `state`, exchanges the code, and normalizes claims into an `Identity` via sso-core. IdP error responses return to the login page with a message.
3. The customer is matched (`sub` → email-under-policy → JIT create) and the `sub` link is stored.
4. The customer group is resolved from IdP groups and the storefront customer session is established.

## Requirements

- Magento **2.4.x**
- PHP **8.3 – 8.5**

## Part of the MageDevGroup identity suite

| Repo | Role |
|------|------|
| `sso-core` | Shared OIDC engine (installed automatically) |
| `admin-sso` · `admin-sso-<idp>` | Admin-panel SSO login |
| `customer-sso` · `customer-sso-<idp>` | Storefront SSO login |
| `admin-scim` · `admin-scim-<idp>` | Admin-user provisioning (SCIM 2.0) |

## License

[OSL-3.0](LICENSE) © MageDevGroup. Commercial licensing and support: <https://magedevgroup.com>.
