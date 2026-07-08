<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Model;

use MageDevGroup\CustomerSso\Model\Config;
use MageDevGroup\CustomerSso\Model\GroupAssigner;
use MageDevGroup\SsoCore\Model\Data\Identity;
use MageDevGroup\SsoCore\Model\Mapping\MappingEngine;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class GroupAssignerTest extends TestCase
{
    /** The store's default customer group id used across the suite. */
    private const DEFAULT_GROUP_ID = '1';

    /** @var Config&Stub */
    private $config;

    /** @var CustomerRepositoryInterface&MockObject */
    private $customerRepository;

    /** @var GroupRepositoryInterface&Stub */
    private $groupRepository;

    /** @var GroupAssigner */
    private GroupAssigner $assigner;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->groupRepository = $this->createStub(GroupRepositoryInterface::class);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $defaultGroup = $this->createStub(GroupInterface::class);
        $defaultGroup->method('getId')->willReturn(self::DEFAULT_GROUP_ID);
        $groupManagement = $this->createStub(GroupManagementInterface::class);
        $groupManagement->method('getDefaultGroup')->willReturn($defaultGroup);

        // Real mapping engine — the resolution logic is what we're exercising.
        $this->assigner = new GroupAssigner(
            $this->config,
            new MappingEngine(),
            $this->customerRepository,
            $groupManagement,
            $this->groupRepository,
            $storeManager
        );
    }

    /**
     * @param array<string,string> $map
     */
    private function withRules(array $map): void
    {
        $this->config->method('getGroupCustomerGroupMap')->willReturn($map);
    }

    /**
     * Stub group existence: only the listed ids resolve, any other throws (as a
     * missing/typo'd map entry would).
     *
     * @param string[] $existingIds
     */
    private function existingGroups(array $existingIds): void
    {
        $this->groupRepository->method('getById')->willReturnCallback(
            function ($id) use ($existingIds): GroupInterface {
                if (!in_array((string)$id, $existingIds, true)) {
                    throw new NoSuchEntityException(__('No such group.'));
                }

                return $this->createStub(GroupInterface::class);
            }
        );
    }

    /**
     * Customer currently on the given group id.
     *
     * @param string $currentGroupId
     * @return CustomerInterface&MockObject
     */
    private function customer(string $currentGroupId): CustomerInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn((int)$currentGroupId);

        return $customer;
    }

    public function testAssignsMappedGroupWhenGroupMatches(): void
    {
        $this->withRules(['vip' => '5']);
        $this->existingGroups(['5']);
        $customer = $this->customer(self::DEFAULT_GROUP_ID);

        $customer->expects(self::once())->method('setGroupId')->with(5);
        $this->customerRepository->expects(self::once())->method('save')->with($customer)->willReturn($customer);

        self::assertSame(
            $customer,
            $this->assigner->assign($customer, new Identity('sub', 'u@example.com', 'U', ['vip']))
        );
    }

    public function testFirstResolvedGroupWinsWithMultipleGroups(): void
    {
        // A customer holds a single group; the first resolved rule wins.
        $this->withRules(['a' => '10', 'b' => '20']);
        $this->existingGroups(['10', '20']);
        $customer = $this->customer(self::DEFAULT_GROUP_ID);

        $customer->expects(self::once())->method('setGroupId')->with(10);
        $this->customerRepository->expects(self::once())->method('save')->with($customer)->willReturn($customer);

        $this->assigner->assign($customer, new Identity('sub', 'u@example.com', 'U', ['a', 'b']));
    }

    public function testFallsBackToDefaultGroupWhenNoGroupMatches(): void
    {
        // Refresh on re-login: a customer previously on a mapped group (5) whose IdP
        // groups no longer match any rule is reverted to the store's default group.
        $this->withRules(['vip' => '5']);
        $customer = $this->customer('5');

        $customer->expects(self::once())->method('setGroupId')->with(1);
        $this->customerRepository->expects(self::once())->method('save')->with($customer)->willReturn($customer);

        $this->assigner->assign($customer, new Identity('sub', 'u@example.com', 'U', ['engineering']));
    }

    public function testNoOpWhenNoGroupMapConfigured(): void
    {
        // No group governance configured: a customer an admin manually placed on a
        // non-default group must not be reset to the store default on SSO login.
        $this->withRules([]);
        $customer = $this->customer('5');

        $customer->expects(self::never())->method('setGroupId');
        $this->customerRepository->expects(self::never())->method('save');

        self::assertSame(
            $customer,
            $this->assigner->assign($customer, new Identity('sub', 'u@example.com', 'U', ['vip']))
        );
    }

    public function testNoOpWhenUnmappedAndAlreadyOnDefaultGroup(): void
    {
        // A fresh JIT customer sits on the default group; an unmapped identity must
        // not issue a needless re-save.
        $this->withRules(['vip' => '5']);
        $customer = $this->customer(self::DEFAULT_GROUP_ID);

        $customer->expects(self::never())->method('setGroupId');
        $this->customerRepository->expects(self::never())->method('save');

        self::assertSame(
            $customer,
            $this->assigner->assign($customer, new Identity('sub', 'u@example.com', 'U', []))
        );
    }

    public function testNoOpWhenAlreadyOnMappedGroup(): void
    {
        // Refresh on re-login is idempotent: same group → no needless write.
        $this->withRules(['vip' => '5']);
        $customer = $this->customer('5');

        $customer->expects(self::never())->method('setGroupId');
        $this->customerRepository->expects(self::never())->method('save');

        $this->assigner->assign($customer, new Identity('sub', 'u@example.com', 'U', ['vip']));
    }

    public function testKeepsCurrentGroupWhenMappedValueIsNotNumeric(): void
    {
        // A non-numeric map value (e.g. a group name mistakenly on the right-hand
        // side) coerces to 0 — the "NOT LOGGED IN" group, which getById() returns —
        // and must not silently downgrade the customer. Stub group 0 as existing to
        // mirror the real registry.
        $this->withRules(['staff' => 'wholesale']);
        $this->existingGroups(['0']);
        $customer = $this->customer(self::DEFAULT_GROUP_ID);

        $customer->expects(self::never())->method('setGroupId');
        $this->customerRepository->expects(self::never())->method('save');

        self::assertSame(
            $customer,
            $this->assigner->assign($customer, new Identity('sub', 'u@example.com', 'U', ['staff']))
        );
    }

    public function testKeepsCurrentGroupWhenMappedGroupDoesNotExist(): void
    {
        // A typo'd / deleted group id in the config must not be assigned: moving the
        // customer onto a non-existent group would break their session/pricing.
        $this->withRules(['vip' => '999']);
        $this->existingGroups([]);
        $customer = $this->customer(self::DEFAULT_GROUP_ID);

        $customer->expects(self::never())->method('setGroupId');
        $this->customerRepository->expects(self::never())->method('save');

        self::assertSame(
            $customer,
            $this->assigner->assign($customer, new Identity('sub', 'u@example.com', 'U', ['vip']))
        );
    }
}
