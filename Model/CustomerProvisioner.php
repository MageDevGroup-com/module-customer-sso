<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Model;

use MageDevGroup\SsoCore\Api\Data\IdentityInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolve the storefront customer for a verified OIDC identity, creating one when
 * needed and keeping the IdP-subject link that makes re-login stable.
 *
 * Resolution order mirrors the suite contract:
 *  1. match by the stored IdP subject (`sub`) — the stable re-login key;
 *  2. else match by email and adopt the existing customer, but only under the
 *     configured auto-link policy and never over an account already bound to a
 *     different subject (email is not a stable identifier — takeover guard);
 *  3. else create a fresh `customer_entity` (JIT) and link it to the subject.
 *
 * Provider-neutral — the identity is already normalized by sso-core, so no
 * IdP-specific claim handling lives here. Claim → customer-group mapping is applied
 * separately by {@see GroupAssigner}; this establishes the account and its subject
 * link only.
 *
 * The new customer carries no password: SSO is the entry point. Native password
 * login remains available only via the store's normal reset flow when the admin
 * keeps it enabled.
 *
 * Mirrors {@see \MageDevGroup\AdminSso\Model\UserProvisioner} for the customer
 * domain; the subject link lives in {@see SubjectLink} rather than a table column
 * because customers are EAV/API entities.
 */
class CustomerProvisioner
{
    /** Lastname used when the IdP releases no name / only a single-word name. */
    private const DEFAULT_LAST_NAME = 'SSO';

    /**
     * @param CustomerInterfaceFactory $customerFactory
     * @param CustomerRepositoryInterface $customerRepository
     * @param StoreManagerInterface $storeManager
     * @param SubjectLink $subjectLink
     * @param Config $config
     * @param ActiveProviderResolver $activeProviderResolver
     */
    public function __construct(
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly SubjectLink $subjectLink,
        private readonly Config $config,
        private readonly ActiveProviderResolver $activeProviderResolver
    ) {
    }

    /**
     * Return the storefront customer for the given identity, creating one if needed.
     *
     * @param IdentityInterface $identity verified identity from sso-core
     * @return CustomerInterface the linked or newly created customer (persisted)
     * @throws LocalizedException when an existing email match may not be linked
     *         (policy requires verification, or it is bound to another subject), or
     *         when a new customer must be created but the IdP released no email
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\State\InputMismatchException
     */
    public function provision(IdentityInterface $identity): CustomerInterface
    {
        $subjectId = $identity->getSubjectId();
        $providerCode = $this->currentProviderCode();

        $linked = $this->findLinkedCustomer($providerCode, $subjectId);
        if ($linked !== null) {
            return $linked;
        }

        $email = $this->normalizeEmail($identity->getEmail());
        if ($email !== null) {
            $existing = $this->findByEmail($email);
            if ($existing !== null) {
                return $this->adoptByEmail($existing, $providerCode, $subjectId);
            }
        }

        return $this->createNew($identity, $email, $providerCode, $subjectId);
    }

    /**
     * Load the customer already linked to this subject, or null when none.
     *
     * Also returns null when the link is stale (the customer was deleted).
     *
     * @param string $providerCode active SSO provider code
     * @param string $subjectId
     */
    private function findLinkedCustomer(string $providerCode, string $subjectId): ?CustomerInterface
    {
        $customerId = $this->subjectLink->findCustomerIdBySubject($providerCode, $subjectId);
        if ($customerId === null) {
            return null;
        }

        try {
            return $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    /**
     * Load an existing customer by email in the current website, or null when none.
     *
     * @param string $email already-normalized email
     */
    private function findByEmail(string $email): ?CustomerInterface
    {
        try {
            return $this->customerRepository->get($email, $this->currentWebsiteId());
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    /**
     * Adopt an existing customer matched by email, storing the subject link so future
     * logins resolve by subject. Refuses to link when the account already belongs to
     * a different subject (takeover guard), or when the policy requires verification.
     *
     * @param CustomerInterface $customer customer matched by email
     * @param string $providerCode active SSO provider code
     * @param string $subjectId
     * @throws LocalizedException when linking is not permitted
     */
    private function adoptByEmail(
        CustomerInterface $customer,
        string $providerCode,
        string $subjectId
    ): CustomerInterface {
        $customerId = (int)$customer->getId();

        $existingIdentity = $this->subjectLink->getIdentityByCustomerId($customerId);
        if ($existingIdentity !== null
            && ($existingIdentity['provider_code'] !== $providerCode
                || $existingIdentity['subject_id'] !== $subjectId)
        ) {
            throw new LocalizedException(
                __('A customer account with this email is already linked to a different single sign-on identity.')
            );
        }

        if (!$this->config->isAutoLinkByEmailAllowed($this->currentStoreId())) {
            throw new LocalizedException(
                __(
                    'An account with this email address already exists. '
                    . 'Please sign in with your password to enable single sign-on for it.'
                )
            );
        }

        $this->subjectLink->link($customerId, $providerCode, $subjectId);

        return $customer;
    }

    /**
     * Create, persist, and subject-link a fresh storefront customer.
     *
     * @param IdentityInterface $identity
     * @param string|null $email already-normalized email, or null
     * @param string $providerCode active SSO provider code
     * @param string $subjectId
     * @throws LocalizedException when the IdP released no email to key the account on
     */
    private function createNew(
        IdentityInterface $identity,
        ?string $email,
        string $providerCode,
        string $subjectId
    ): CustomerInterface {
        if ($email === null) {
            throw new LocalizedException(
                __('The identity provider did not release an email address required to create a customer account.')
            );
        }

        $store = $this->storeManager->getStore();
        [$firstName, $lastName] = $this->splitName($identity->getName(), $email);

        $customer = $this->customerFactory->create();
        $customer->setWebsiteId((int)$store->getWebsiteId());
        $customer->setStoreId((int)$store->getId());
        $customer->setEmail($email);
        $customer->setFirstname($firstName);
        $customer->setLastname($lastName);

        $saved = $this->customerRepository->save($customer);
        $this->subjectLink->link((int)$saved->getId(), $providerCode, $subjectId);

        return $saved;
    }

    /**
     * Website id of the current store (customers are website-scoped).
     */
    private function currentWebsiteId(): int
    {
        return (int)$this->storeManager->getStore()->getWebsiteId();
    }

    /**
     * Id of the current store (config policy is store-scoped).
     */
    private function currentStoreId(): int
    {
        return (int)$this->storeManager->getStore()->getId();
    }

    /**
     * Active SSO provider code for the current store, or '' when none is selected.
     *
     * Scopes the subject link so a `sub` issued by one provider can never resolve a
     * customer linked under another (`sub` is only unique per issuer).
     */
    private function currentProviderCode(): string
    {
        return (string)$this->activeProviderResolver->getActiveCode($this->currentStoreId());
    }

    /**
     * Trimmed, lower-cased email, or null when the IdP released none.
     *
     * @param string|null $email
     */
    private function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string)$email));

        return $email === '' ? null : $email;
    }

    /**
     * Split a display name into first/last, guaranteeing both are non-empty
     * (Magento requires both). With no name the email local part seeds the first
     * name; a single-word name gets the {@see self::DEFAULT_LAST_NAME} placeholder.
     *
     * @param string|null $name
     * @param string $email used to derive a first name when the IdP released none
     * @return array{0:string,1:string}
     */
    private function splitName(?string $name, string $email): array
    {
        $name = trim((string)$name);
        if ($name === '') {
            $local = strstr($email, '@', true);
            $first = trim(is_string($local) ? $local : $email);

            return [$first !== '' ? $first : $email, self::DEFAULT_LAST_NAME];
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = (string)array_shift($parts);
        $last = $parts === [] ? self::DEFAULT_LAST_NAME : implode(' ', $parts);

        return [$first, $last];
    }
}
