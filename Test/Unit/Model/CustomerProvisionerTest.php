<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Model;

use MageDevGroup\CustomerSso\Model\ActiveProviderResolver;
use MageDevGroup\CustomerSso\Model\Config;
use MageDevGroup\CustomerSso\Model\CustomerProvisioner;
use MageDevGroup\CustomerSso\Model\SubjectLink;
use MageDevGroup\SsoCore\Model\Data\Identity;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomerProvisionerTest extends TestCase
{
    /** @var CustomerInterfaceFactory&MockObject */
    private $customerFactory;

    /** @var CustomerRepositoryInterface&MockObject */
    private $customerRepository;

    /** @var StoreManagerInterface&\PHPUnit\Framework\MockObject\Stub */
    private $storeManager;

    /** @var SubjectLink&MockObject */
    private $subjectLink;

    /** @var Config&\PHPUnit\Framework\MockObject\Stub */
    private $config;

    /** @var ActiveProviderResolver&\PHPUnit\Framework\MockObject\Stub */
    private $activeProviderResolver;

    /** @var CustomerProvisioner */
    private CustomerProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->customerFactory = $this->createMock(CustomerInterfaceFactory::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->subjectLink = $this->createMock(SubjectLink::class);
        $this->config = $this->createStub(Config::class);
        $this->activeProviderResolver = $this->createStub(ActiveProviderResolver::class);
        $this->activeProviderResolver->method('getActiveCode')->willReturn('okta');

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $store->method('getWebsiteId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->provisioner = new CustomerProvisioner(
            $this->customerFactory,
            $this->customerRepository,
            $this->storeManager,
            $this->subjectLink,
            $this->config,
            $this->activeProviderResolver
        );
    }

    /**
     * A new customer whose setters record what was set, returned by the factory.
     *
     * @param array<string,mixed> $captured filled by the customer's setters
     * @return CustomerInterface&\PHPUnit\Framework\MockObject\Stub
     */
    private function capturingCustomer(array &$captured)
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('setWebsiteId')->willReturnCallback(
            function ($v) use (&$captured, $customer) {
                $captured['website_id'] = $v;
                return $customer;
            }
        );
        $customer->method('setStoreId')->willReturnCallback(
            function ($v) use (&$captured, $customer) {
                $captured['store_id'] = $v;
                return $customer;
            }
        );
        $customer->method('setEmail')->willReturnCallback(
            function ($v) use (&$captured, $customer) {
                $captured['email'] = $v;
                return $customer;
            }
        );
        $customer->method('setFirstname')->willReturnCallback(
            function ($v) use (&$captured, $customer) {
                $captured['firstname'] = $v;
                return $customer;
            }
        );
        $customer->method('setLastname')->willReturnCallback(
            function ($v) use (&$captured, $customer) {
                $captured['lastname'] = $v;
                return $customer;
            }
        );

        return $customer;
    }

    /**
     * A persisted-customer stub carrying an id.
     *
     * @return CustomerInterface&\PHPUnit\Framework\MockObject\Stub
     */
    private function savedCustomer(int $id)
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);

        return $customer;
    }

    /**
     * Make the repository behave as if no customer matches the looked-up email.
     */
    private function noEmailMatch(): void
    {
        $this->customerRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('none')));
    }

    // --- JIT creation (no existing match) ------------------------------------

    public function testCreatesCustomerFromIdentityAndLinksSubject(): void
    {
        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(null);
        $this->noEmailMatch();

        $captured = [];
        $customer = $this->capturingCustomer($captured);

        $this->customerFactory->expects(self::once())->method('create')->willReturn($customer);
        $this->customerRepository->expects(self::once())
            ->method('save')
            ->with($customer)
            ->willReturn($this->savedCustomer(42));
        $this->subjectLink->expects(self::once())->method('link')->with(42, 'okta', 'sub-1');

        $identity = new Identity('sub-1', 'Jane.Doe@Example.com', 'Jane Doe', ['shoppers']);

        $result = $this->provisioner->provision($identity);

        self::assertSame(42, (int)$result->getId());
        self::assertSame([
            'website_id' => 1,
            'store_id' => 3,
            'email' => 'jane.doe@example.com',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
        ], $captured);
    }

    public function testThrowsWhenIdentityHasNoEmail(): void
    {
        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(null);
        $this->subjectLink->expects(self::never())->method('link');
        $this->customerFactory->expects(self::never())->method('create');
        $this->customerRepository->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'The identity provider did not release an email address required to create a customer account.'
        );

        $this->provisioner->provision(new Identity('sub-x', null, 'No Email', []));
    }

    public function testFallbackLastNameForSingleWordName(): void
    {
        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(null);
        $this->subjectLink->expects(self::once())->method('link');
        $this->noEmailMatch();

        $captured = [];
        $this->customerFactory->expects(self::once())->method('create')
            ->willReturn($this->capturingCustomer($captured));
        $this->customerRepository->expects(self::once())->method('save')
            ->willReturn($this->savedCustomer(1));

        $this->provisioner->provision(new Identity('sub-2', 'madonna@example.com', 'Madonna', []));

        self::assertSame('Madonna', $captured['firstname']);
        self::assertSame('SSO', $captured['lastname']);
    }

    public function testDerivesFirstNameFromEmailWhenNoName(): void
    {
        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(null);
        $this->subjectLink->expects(self::once())->method('link');
        $this->noEmailMatch();

        $captured = [];
        $this->customerFactory->expects(self::once())->method('create')
            ->willReturn($this->capturingCustomer($captured));
        $this->customerRepository->expects(self::once())->method('save')
            ->willReturn($this->savedCustomer(1));

        $this->provisioner->provision(new Identity('sub-3', 'shopper@example.com', null, []));

        self::assertSame('shopper', $captured['firstname']);
        self::assertSame('SSO', $captured['lastname']);
    }

    public function testKeepsMultiWordLastName(): void
    {
        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(null);
        $this->subjectLink->expects(self::once())->method('link');
        $this->noEmailMatch();

        $captured = [];
        $this->customerFactory->expects(self::once())->method('create')
            ->willReturn($this->capturingCustomer($captured));
        $this->customerRepository->expects(self::once())->method('save')
            ->willReturn($this->savedCustomer(1));

        $this->provisioner->provision(new Identity('sub-4', 'user@example.com', 'Ada Van Der Berg', []));

        self::assertSame('Ada', $captured['firstname']);
        self::assertSame('Van Der Berg', $captured['lastname']);
    }

    // --- Link by subject (stable re-login) -----------------------------------

    public function testResolvesExistingCustomerBySubject(): void
    {
        $existing = $this->savedCustomer(55);

        $this->subjectLink->method('findCustomerIdBySubject')->with('okta', 'sub-1')->willReturn(55);
        $this->subjectLink->expects(self::never())->method('link');
        $this->customerRepository->expects(self::once())->method('getById')->with(55)->willReturn($existing);
        $this->customerRepository->expects(self::never())->method('save');
        $this->customerFactory->expects(self::never())->method('create');

        self::assertSame(
            $existing,
            $this->provisioner->provision(new Identity('sub-1', 'jane@example.com', 'Jane', []))
        );
    }

    public function testFallsBackToEmailWhenSubjectLinkIsStale(): void
    {
        // Link row points at a since-deleted customer: fall through to email/JIT.
        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(99);
        $this->subjectLink->expects(self::once())->method('link');
        $this->customerRepository->method('getById')
            ->willThrowException(new NoSuchEntityException(__('gone')));
        $this->noEmailMatch();

        $captured = [];
        $this->customerFactory->expects(self::once())->method('create')
            ->willReturn($this->capturingCustomer($captured));
        $this->customerRepository->expects(self::once())->method('save')
            ->willReturn($this->savedCustomer(1));

        $this->provisioner->provision(new Identity('sub-1', 'ghost@example.com', 'Ghost', []));

        self::assertSame('ghost@example.com', $captured['email']);
    }

    // --- Link by email (under policy) ----------------------------------------

    public function testLinksByEmailWhenAutoLinkAllowed(): void
    {
        $existing = $this->savedCustomer(7);

        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(null);
        $this->subjectLink->method('getIdentityByCustomerId')->with(7)->willReturn(null);
        $this->config->method('isAutoLinkByEmailAllowed')->willReturn(true);

        $this->customerRepository->expects(self::once())
            ->method('get')->with('jane@example.com', 1)->willReturn($existing);
        $this->customerRepository->expects(self::never())->method('save');
        $this->customerFactory->expects(self::never())->method('create');
        $this->subjectLink->expects(self::once())->method('link')->with(7, 'okta', 'sub-new');

        self::assertSame(
            $existing,
            $this->provisioner->provision(new Identity('sub-new', 'jane@example.com', 'Jane', []))
        );
    }

    public function testMatchesExistingCustomerByCaseInsensitiveEmail(): void
    {
        $existing = $this->savedCustomer(7);

        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(null);
        $this->subjectLink->method('getIdentityByCustomerId')->with(7)->willReturn(null);
        $this->config->method('isAutoLinkByEmailAllowed')->willReturn(true);

        // The IdP releases a mixed-case email; the lookup must normalize to lower case
        // so the pre-existing lower-case account is matched (no duplicate JIT create).
        $this->customerRepository->expects(self::once())
            ->method('get')->with('jane@example.com', 1)->willReturn($existing);
        $this->customerRepository->expects(self::never())->method('save');
        $this->customerFactory->expects(self::never())->method('create');
        $this->subjectLink->expects(self::once())->method('link')->with(7, 'okta', 'sub-new');

        self::assertSame(
            $existing,
            $this->provisioner->provision(new Identity('sub-new', 'JANE@Example.com', 'Jane', []))
        );
    }

    public function testBlocksEmailLinkWhenPolicyRequiresVerification(): void
    {
        $existing = $this->savedCustomer(7);

        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(null);
        $this->subjectLink->method('getIdentityByCustomerId')->willReturn(null);
        $this->config->method('isAutoLinkByEmailAllowed')->willReturn(false);

        $this->customerRepository->expects(self::once())->method('get')->willReturn($existing);
        $this->customerFactory->expects(self::never())->method('create');
        $this->subjectLink->expects(self::never())->method('link');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Please sign in with your password to enable single sign-on');

        $this->provisioner->provision(new Identity('sub-new', 'jane@example.com', 'Jane', []));
    }

    public function testBlocksEmailLinkWhenAccountBoundToDifferentSubject(): void
    {
        $existing = $this->savedCustomer(7);

        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(null);
        $this->subjectLink->method('getIdentityByCustomerId')->with(7)
            ->willReturn(['provider_code' => 'okta', 'subject_id' => 'other-sub']);
        // Even with auto-link enabled, an account owned by another subject is off limits.
        $this->config->method('isAutoLinkByEmailAllowed')->willReturn(true);

        $this->customerRepository->expects(self::once())->method('get')->willReturn($existing);
        $this->customerFactory->expects(self::never())->method('create');
        $this->subjectLink->expects(self::never())->method('link');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('already linked to a different single sign-on identity');

        $this->provisioner->provision(new Identity('sub-new', 'jane@example.com', 'Jane', []));
    }

    public function testBlocksEmailLinkWhenAccountBoundToSameSubjectUnderAnotherProvider(): void
    {
        // `sub` is only unique per issuer: an identical subject string from a different
        // provider is a different identity and must not take over the account.
        $existing = $this->savedCustomer(7);

        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(null);
        $this->subjectLink->method('getIdentityByCustomerId')->with(7)
            ->willReturn(['provider_code' => 'azure', 'subject_id' => 'sub-collide']);
        $this->config->method('isAutoLinkByEmailAllowed')->willReturn(true);

        $this->customerRepository->expects(self::once())->method('get')->willReturn($existing);
        $this->customerFactory->expects(self::never())->method('create');
        $this->subjectLink->expects(self::never())->method('link');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('already linked to a different single sign-on identity');

        // Active provider is 'okta' (see setUp); the colliding subject is 'sub-collide'.
        $this->provisioner->provision(new Identity('sub-collide', 'jane@example.com', 'Jane', []));
    }
}
