<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit;

use MageDevGroup\CustomerSso\Block\Login\Sso;
use MageDevGroup\CustomerSso\Controller\Sso\Callback;
use MageDevGroup\CustomerSso\Controller\Sso\Start;
use MageDevGroup\CustomerSso\Model\ActiveProviderResolver;
use MageDevGroup\CustomerSso\Model\Config;
use MageDevGroup\CustomerSso\Model\CustomerProvisioner;
use MageDevGroup\CustomerSso\Model\CustomerSessionCreator;
use MageDevGroup\CustomerSso\Model\GroupAssigner;
use MageDevGroup\CustomerSso\Model\Oidc\AuthorizationStarter;
use MageDevGroup\CustomerSso\Model\Oidc\CallbackHandler;
use MageDevGroup\CustomerSso\Model\PresetRegistry;
use MageDevGroup\CustomerSso\Model\SubjectLink;
use MageDevGroup\SsoCore\Api\AuthorizationStateStorageInterface;
use MageDevGroup\SsoCore\Api\Data\AuthorizationStateInterface;
use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use MageDevGroup\SsoCore\Model\Mapping\MappingEngine;
use MageDevGroup\SsoCore\Model\Oidc\AuthorizationRequestFactory;
use MageDevGroup\SsoCore\Model\Oidc\DiscoveryClient;
use MageDevGroup\SsoCore\Model\Oidc\IdentityFactory;
use MageDevGroup\SsoCore\Model\Oidc\IdTokenValidator;
use MageDevGroup\SsoCore\Model\Oidc\JwksClient;
use MageDevGroup\SsoCore\Model\Oidc\ProviderMetadata;
use MageDevGroup\SsoCore\Model\Oidc\TokenClient;
use MageDevGroup\SsoCore\Model\Oidc\TokenResponse;
use Jose\Component\Core\JWKSet;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Math\Random;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integration-style verification of the whole storefront SSO capability wired with
 * a single stub provider preset: config → login button → start-auth → IdP callback
 * → JIT/linking → customer-group assignment → storefront session.
 *
 * Only the true external boundaries are doubled — the network OIDC clients
 * (discovery, token, JWKS, ID-token validation), the storefront session, the
 * customer repository/persistence, and the subject-link table. Every orchestration
 * class in between (starter, callback handler, provisioner, group assigner, the two
 * controllers, and sso-core's identity/mapping engines) is the real object, so the
 * test exercises the same collaboration the acceptance criteria describe.
 *
 * The doubled collaborators are wired once in setUp and only some are verified per
 * scenario, so the mock-without-expectations notice is opted out at the class level.
 */
#[AllowMockObjectsWithoutExpectations]
class EndToEndTest extends TestCase
{
    /** Subject the stub IdP releases; the stable re-login key. */
    private const SUBJECT = 'idp-subject-1';

    /** Email the stub IdP releases. */
    private const EMAIL = 'shopper@example.com';

    /** Store's default customer group id. */
    private const DEFAULT_GROUP_ID = '1';

    /** Customer group the `vip` IdP group maps to. */
    private const VIP_GROUP_ID = '5';

    /** Id assigned to a JIT-created customer. */
    private const NEW_CUSTOMER_ID = 42;

    /** Id of a pre-existing customer matched by email. */
    private const EXISTING_CUSTOMER_ID = 7;

    /** @var array<string,string> live, mutable storefront config. */
    private array $configValues;

    /** @var array<string,int> in-memory subject → customer-id link table. */
    private array $subjectLinks = [];

    /** @var array<string,CustomerInterface> pre-existing customers keyed by email. */
    private array $customersByEmail = [];

    /** @var Sso */
    private Sso $block;

    /** @var Start */
    private Start $startController;

    /** @var Callback */
    private Callback $callbackController;

    /** @var AuthorizationStateStorageInterface in-memory state round-trip. */
    private AuthorizationStateStorageInterface $stateStorage;

    /** @var Session&MockObject */
    private $session;

    /** @var Redirect&MockObject */
    private $startRedirect;

    /** @var Redirect&MockObject */
    private $callbackRedirect;

    /** @var RequestInterface&MockObject */
    private $callbackRequest;

    /** @var CustomerInterface&MockObject the JIT-created customer. */
    private $newCustomer;

    /** @var array<string,mixed> claims the stub IdP token yields. */
    private array $claims;

    protected function setUp(): void
    {
        $this->configValues = [
            Config::XML_PATH_ENABLED => '1',
            ActiveProviderResolver::XML_PATH_ACTIVE_PROVIDER => 'stub',
            Config::XML_PATH_CLIENT_ID => 'client-123',
            Config::XML_PATH_CLIENT_SECRET => 'enc-secret',
            Config::XML_PATH_ALLOW_PASSWORD_LOGIN => '1',
            Config::XML_PATH_AUTO_LINK_POLICY => Config::AUTO_LINK_AUTO,
            Config::XML_PATH_GROUP_MAP => 'vip=' . self::VIP_GROUP_ID,
        ];
        $this->claims = [
            'sub' => self::SUBJECT,
            'email' => self::EMAIL,
            'name' => 'Jane Shopper',
            'groups' => ['vip'],
        ];

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(fn(string $path) => $this->configValues[$path] ?? null);
        $scopeConfig->method('isSetFlag')
            ->willReturnCallback(fn(string $path): bool => ($this->configValues[$path] ?? '0') === '1');

        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('plain-secret');

        $config = new Config($scopeConfig, $encryptor);
        $presetRegistry = new PresetRegistry([$this->stubPreset()]);
        $resolver = new ActiveProviderResolver($scopeConfig, $presetRegistry);

        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn(string $route): string => 'https://magento.loc/' . $route
        );

        $this->block = (new ObjectManager($this))->getObject(Sso::class, [
            'context' => $this->createStub(\Magento\Framework\View\Element\Template\Context::class),
            'config' => $config,
            'activeProviderResolver' => $resolver,
            'url' => $url,
        ]);

        $metadata = new ProviderMetadata(
            'https://idp.example.com',
            'https://idp.example.com/authorize',
            'https://idp.example.com/token',
            'https://idp.example.com/jwks'
        );
        $discoveryClient = $this->createStub(DiscoveryClient::class);
        $discoveryClient->method('discover')->willReturn($metadata);

        $random = $this->createStub(Random::class);
        $random->method('getRandomBytes')->willReturn(str_repeat("\x1a", 32));

        $this->stateStorage = $this->inMemoryStateStorage();

        $starter = new AuthorizationStarter(
            $config,
            $resolver,
            $discoveryClient,
            new AuthorizationRequestFactory($random),
            $this->stateStorage,
            $url
        );

        $tokenClient = $this->createStub(TokenClient::class);
        $tokenClient->method('exchangeCode')->willReturn(new TokenResponse('id-token-jwt'));
        $jwksClient = $this->createStub(JwksClient::class);
        $jwksClient->method('getKeySet')->willReturn(new JWKSet([]));
        $idTokenValidator = $this->createStub(IdTokenValidator::class);
        $idTokenValidator->method('validate')->willReturnCallback(fn(): array => $this->claims);

        $callbackHandler = new CallbackHandler(
            $config,
            $resolver,
            $discoveryClient,
            $tokenClient,
            $jwksClient,
            $idTokenValidator,
            new IdentityFactory(),
            $this->stateStorage,
            $url
        );

        [$storeManager, $customerRepository] = [$this->storeManager(), $this->customerRepository()];
        $subjectLink = $this->subjectLink();

        $this->newCustomer = $this->customer(self::NEW_CUSTOMER_ID);
        $customerFactory = $this->createStub(CustomerInterfaceFactory::class);
        $customerFactory->method('create')->willReturn($this->newCustomer);

        $provisioner = new CustomerProvisioner(
            $customerFactory,
            $customerRepository,
            $storeManager,
            $subjectLink,
            $config,
            $resolver
        );

        $groupAssigner = new GroupAssigner(
            $config,
            new MappingEngine(),
            $customerRepository,
            $this->groupManagement(),
            $this->groupRepository(),
            $storeManager
        );

        $this->session = $this->createMock(Session::class);
        $sessionCreator = new CustomerSessionCreator($this->session);

        $this->startRedirect = $this->createMock(Redirect::class);
        $this->startController = new Start(
            $starter,
            $this->redirectFactory($this->startRedirect),
            $this->createMock(ManagerInterface::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->callbackRedirect = $this->createMock(Redirect::class);
        $this->callbackRequest = $this->createMock(RequestInterface::class);
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnArgument(0);
        $this->callbackController = new Callback(
            $this->callbackRequest,
            $callbackHandler,
            $provisioner,
            $groupAssigner,
            $sessionCreator,
            $this->redirectFactory($this->callbackRedirect),
            $this->createMock(ManagerInterface::class),
            $escaper,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testConfiguredButtonThenJitLoginAssignsGroupAndEstablishesSession(): void
    {
        // Config → button: the active preset drives a visible, branded button.
        self::assertTrue($this->block->isAvailable());
        self::assertSame('Sign in with Stub', $this->block->getButtonLabel());
        self::assertSame('https://magento.loc/customersso/sso/start', $this->block->getStartUrl());

        // Start: the browser is redirected to the IdP and the one-time state persists.
        $state = $this->runStart();

        // No prior link and no email match → JIT create, then map `vip` → group 5.
        $this->newCustomer->expects(self::once())->method('setEmail')->with(self::EMAIL);
        $this->newCustomer->expects(self::once())->method('setGroupId')->with((int)self::VIP_GROUP_ID);
        $this->session->expects(self::once())->method('setCustomerDataAsLoggedIn')->with($this->newCustomer);

        $this->runCallback('auth-code', $state, 'customer/account');

        // The subject is now linked to the JIT customer (provider-scoped) for stable re-login.
        self::assertSame(self::NEW_CUSTOMER_ID, $this->subjectLinks['stub|' . self::SUBJECT] ?? null);
    }

    public function testExistingCustomerLinkedByEmailUnderAutoLinkPolicy(): void
    {
        $existing = $this->customer(self::EXISTING_CUSTOMER_ID);
        $this->customersByEmail[self::EMAIL] = $existing;

        $state = $this->runStart();

        // Adopt the email match (auto-link), keep it on its group mapping, log in — no JIT.
        $this->newCustomer->expects(self::never())->method('setEmail');
        $existing->expects(self::once())->method('setGroupId')->with((int)self::VIP_GROUP_ID);
        $this->session->expects(self::once())->method('setCustomerDataAsLoggedIn')->with($existing);

        $this->runCallback('auth-code', $state, 'customer/account');

        self::assertSame(self::EXISTING_CUSTOMER_ID, $this->subjectLinks['stub|' . self::SUBJECT] ?? null);
    }

    public function testEmailMatchNotLinkedWhenPolicyRequiresVerification(): void
    {
        $this->configValues[Config::XML_PATH_AUTO_LINK_POLICY] = Config::AUTO_LINK_REQUIRE_VERIFICATION;
        $this->customersByEmail[self::EMAIL] = $this->customer(self::EXISTING_CUSTOMER_ID);

        $state = $this->runStart();

        // Linking is refused → no session, back to the login page, no link stored.
        $this->session->expects(self::never())->method('setCustomerDataAsLoggedIn');

        $this->runCallback('auth-code', $state, 'customer/account/login');

        self::assertArrayNotHasKey('stub|' . self::SUBJECT, $this->subjectLinks);
    }

    public function testButtonHiddenWhenModuleDisabled(): void
    {
        $this->configValues[Config::XML_PATH_ENABLED] = '0';

        // The button gates on isAvailable() (enabled AND a provider); a disabled
        // module renders nothing even though a provider is still selected.
        self::assertFalse($this->block->isAvailable());
    }

    /**
     * Drive the start controller and return the persisted one-time `state`.
     */
    private function runStart(): string
    {
        $this->startRedirect->method('setUrl')->willReturnSelf();
        self::assertSame($this->startRedirect, $this->startController->execute());

        $states = array_keys($this->readSavedStates());
        self::assertCount(1, $states, 'start must persist exactly one auth state');

        return $states[0];
    }

    /**
     * Drive the callback controller with the given params and assert the landing path.
     */
    private function runCallback(string $code, string $state, string $expectedPath): void
    {
        $params = ['code' => $code, 'state' => $state];
        $this->callbackRequest->method('getParam')
            ->willReturnCallback(static fn(string $name) => $params[$name] ?? null);
        $this->callbackRedirect->expects(self::once())->method('setPath')->with($expectedPath);

        self::assertSame($this->callbackRedirect, $this->callbackController->execute());
    }

    /**
     * Stub provider preset standing in for a real `customer-sso-<idp>` plugin.
     */
    private function stubPreset(): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('getCode')->willReturn('stub');
        $preset->method('getLabel')->willReturn('Stub IdP');
        $preset->method('buildDiscoveryUrl')
            ->willReturn('https://idp.example.com/.well-known/openid-configuration');
        $preset->method('getDefaultScopes')->willReturn(['openid', 'email', 'profile']);
        $preset->method('getGroupsClaim')->willReturn('groups');
        $preset->method('getButtonLabel')->willReturn('Sign in with Stub');
        $preset->method('getButtonIconUrl')->willReturn('https://cdn.example.com/stub.svg');

        return $preset;
    }

    /**
     * In-memory {@see AuthorizationStateStorageInterface}: a real state round-trip.
     */
    private function inMemoryStateStorage(): AuthorizationStateStorageInterface
    {
        return new class implements AuthorizationStateStorageInterface {
            /** @var array<string,AuthorizationStateInterface> */
            public array $saved = [];

            public function save(AuthorizationStateInterface $state): void
            {
                $this->saved[$state->getState()] = $state;
            }

            public function consume(string $state): ?AuthorizationStateInterface
            {
                $found = $this->saved[$state] ?? null;
                unset($this->saved[$state]);

                return $found;
            }
        };
    }

    /**
     * @return array<string,AuthorizationStateInterface>
     */
    private function readSavedStates(): array
    {
        return $this->stateStorage->saved; // @phpstan-ignore-line anonymous class member
    }

    /**
     * Stateful subject-link fake backed by {@see self::$subjectLinks}.
     *
     * @return SubjectLink&MockObject
     */
    private function subjectLink(): SubjectLink
    {
        $link = $this->createMock(SubjectLink::class);
        $link->method('findCustomerIdBySubject')
            ->willReturnCallback(
                fn(string $provider, string $subject): ?int => $this->subjectLinks[$provider . '|' . $subject] ?? null
            );
        $link->method('getIdentityByCustomerId')->willReturnCallback(
            function (int $customerId): ?array {
                $key = array_search($customerId, $this->subjectLinks, true);
                if ($key === false) {
                    return null;
                }
                [$provider, $subject] = explode('|', (string)$key, 2);

                return ['provider_code' => $provider, 'subject_id' => $subject];
            }
        );
        $link->method('link')->willReturnCallback(
            function (int $customerId, string $provider, string $subject): void {
                $this->subjectLinks[$provider . '|' . $subject] = $customerId;
            }
        );

        return $link;
    }

    /**
     * Customer repository fake: resolves email matches from {@see self::$customersByEmail},
     * echoes saves back (with an assigned id for JIT creates).
     *
     * @return CustomerRepositoryInterface&MockObject
     */
    private function customerRepository(): CustomerRepositoryInterface
    {
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            function (string $email): CustomerInterface {
                if (isset($this->customersByEmail[$email])) {
                    return $this->customersByEmail[$email];
                }
                throw new NoSuchEntityException(__('No such customer.'));
            }
        );
        $repository->method('save')->willReturnArgument(0);

        return $repository;
    }

    /**
     * Customer mock on the default group with the given (post-save) id.
     *
     * @return CustomerInterface&MockObject
     */
    private function customer(int $id): CustomerInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn((string)$id);
        $customer->method('getGroupId')->willReturn((int)self::DEFAULT_GROUP_ID);

        return $customer;
    }

    /**
     * @return StoreManagerInterface&MockObject
     */
    private function storeManager(): StoreManagerInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    /**
     * @return GroupManagementInterface&MockObject
     */
    private function groupManagement(): GroupManagementInterface
    {
        $defaultGroup = $this->createStub(GroupInterface::class);
        $defaultGroup->method('getId')->willReturn(self::DEFAULT_GROUP_ID);
        $groupManagement = $this->createStub(GroupManagementInterface::class);
        $groupManagement->method('getDefaultGroup')->willReturn($defaultGroup);

        return $groupManagement;
    }

    /**
     * Group repository fake: only the VIP group exists.
     *
     * @return GroupRepositoryInterface&MockObject
     */
    private function groupRepository(): GroupRepositoryInterface
    {
        $repository = $this->createStub(GroupRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(
            function ($id): GroupInterface {
                if ((string)$id !== self::VIP_GROUP_ID) {
                    throw new NoSuchEntityException(__('No such group.'));
                }

                return $this->createStub(GroupInterface::class);
            }
        );

        return $repository;
    }

    /**
     * @param Redirect $redirect
     * @return RedirectFactory&MockObject
     */
    private function redirectFactory(Redirect $redirect): RedirectFactory
    {
        $factory = $this->createStub(RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);

        return $factory;
    }
}
