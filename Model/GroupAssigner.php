<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Model;

use MageDevGroup\SsoCore\Api\Data\IdentityInterface;
use MageDevGroup\SsoCore\Model\Mapping\MappingEngine;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Maps an identity's IdP groups onto a Magento customer group and keeps the
 * customer on it.
 *
 * The rules come from admin config ({@see Config::getGroupCustomerGroupMap()});
 * sso-core's {@see MappingEngine} resolves the groups against them, falling back
 * to the store's default customer group when nothing matches. A customer holds a
 * single group, so the first resolved group wins.
 *
 * Only active when the admin has configured a group map: with no rules it is a
 * no-op, so a store using SSO purely for login never has its manually curated
 * customer groups touched. Once a map exists it runs on every SSO login — on JIT
 * create it grants the initial group, on subsequent logins it refreshes it so IdP
 * group changes take effect: removing a customer from their mapped IdP group reverts
 * them to the default group at the next login. Unlike the admin role (which is
 * stripped to deny), a customer always has a valid group, so denial means the
 * store's default, never none.
 *
 * A resolved group id that is not a real, positive customer-group id (a typo'd or
 * non-numeric map entry) is not assigned — the customer keeps their current group
 * rather than being moved onto a broken one or downgraded to "NOT LOGGED IN".
 *
 * Provider-neutral: groups arrive already normalized on the identity by sso-core.
 * Mirrors {@see \MageDevGroup\AdminSso\Model\RoleAssigner} for the customer domain.
 */
class GroupAssigner
{
    /**
     * @param Config $config
     * @param MappingEngine $mappingEngine
     * @param CustomerRepositoryInterface $customerRepository
     * @param GroupManagementInterface $groupManagement
     * @param GroupRepositoryInterface $groupRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Config $config,
        private readonly MappingEngine $mappingEngine,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly GroupManagementInterface $groupManagement,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Resolve and apply the customer group for the identity onto the customer.
     *
     * A no-op (returns the given customer unchanged) when the resolved group already
     * matches the customer's current one, or when a mapped group id does not exist.
     *
     * @param CustomerInterface $customer persisted customer to assign the group to
     * @param IdentityInterface $identity verified identity from sso-core
     * @return CustomerInterface the customer, re-saved when the group changed
     * @throws \Magento\Framework\Exception\LocalizedException on save failure
     */
    public function assign(CustomerInterface $customer, IdentityInterface $identity): CustomerInterface
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        $rules = $this->config->getGroupCustomerGroupMap($storeId);
        // No group governance configured: leave the customer's group untouched rather
        // than resetting an admin-assigned group to the store default on every login.
        if ($rules === []) {
            return $customer;
        }

        $defaultGroupId = (string)$this->groupManagement->getDefaultGroup($storeId)->getId();

        $resolved = $this->mappingEngine->resolve(
            $identity->getGroups(),
            $rules,
            $defaultGroupId
        );
        // resolve() always returns at least the default group (a non-null default).
        $targetGroupId = (string)($resolved[0] ?? $defaultGroupId);

        if ($targetGroupId === (string)(int)$customer->getGroupId()) {
            return $customer;
        }

        // The default group is guaranteed to exist; only a mapped id can be a typo.
        if ($targetGroupId !== $defaultGroupId && !$this->isAssignableGroup($targetGroupId)) {
            return $customer;
        }

        $customer->setGroupId((int)$targetGroupId);

        return $this->customerRepository->save($customer);
    }

    /**
     * Whether a resolved target is a real, assignable customer group.
     *
     * A logged-in customer's group id is always a positive integer. A non-numeric
     * map value (e.g. a group name mistakenly put on the right-hand side, since the
     * left side is a name) or a non-canonical/non-positive one would coerce to 0 —
     * the reserved "NOT LOGGED IN" group, which {@see GroupRepositoryInterface::getById}
     * happily returns — and silently downgrade the customer. Reject those before the
     * existence check.
     *
     * @param string $groupId
     */
    private function isAssignableGroup(string $groupId): bool
    {
        if ((string)(int)$groupId !== $groupId || (int)$groupId < 1) {
            return false;
        }

        return $this->groupExists($groupId);
    }

    /**
     * Whether a customer group with the given id exists.
     *
     * @param string $groupId
     */
    private function groupExists(string $groupId): bool
    {
        try {
            $this->groupRepository->getById((int)$groupId);

            return true;
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }
}
