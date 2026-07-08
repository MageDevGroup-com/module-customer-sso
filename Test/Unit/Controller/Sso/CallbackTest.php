<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Controller\Sso;

use MageDevGroup\CustomerSso\Controller\Sso\Callback;
use MageDevGroup\CustomerSso\Model\CustomerProvisioner;
use MageDevGroup\CustomerSso\Model\CustomerSessionCreator;
use MageDevGroup\CustomerSso\Model\GroupAssigner;
use MageDevGroup\CustomerSso\Model\Oidc\CallbackHandler;
use MageDevGroup\SsoCore\Model\Data\Identity;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CallbackTest extends TestCase
{
    /** @var Redirect&MockObject */
    private $redirect;

    /** @var RequestInterface&Stub */
    private $request;

    /** @var ManagerInterface&MockObject */
    private $messageManager;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var CallbackHandler&MockObject */
    private $handler;

    /** @var CustomerProvisioner&MockObject */
    private $provisioner;

    /** @var GroupAssigner&MockObject */
    private $groupAssigner;

    /** @var CustomerSessionCreator&MockObject */
    private $sessionCreator;

    /** @var Callback */
    private Callback $controller;

    protected function setUp(): void
    {
        $this->redirect = $this->createMock(Redirect::class);

        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $this->request = $this->createStub(RequestInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = $this->createMock(CallbackHandler::class);
        $this->provisioner = $this->createMock(CustomerProvisioner::class);
        $this->groupAssigner = $this->createMock(GroupAssigner::class);
        $this->sessionCreator = $this->createMock(CustomerSessionCreator::class);

        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnArgument(0);

        $this->controller = new Callback(
            $this->request,
            $this->handler,
            $this->provisioner,
            $this->groupAssigner,
            $this->sessionCreator,
            $redirectFactory,
            $this->messageManager,
            $escaper,
            $this->logger
        );
    }

    /**
     * @param array<string,string> $params
     */
    private function withParams(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn(string $name) => $params[$name] ?? null
        );
    }

    /**
     * A failure path ends on the customer login page without completing the exchange.
     */
    private function expectLoginRedirect(): void
    {
        $this->redirect->expects(self::once())->method('setPath')->with('customer/account/login');
    }

    /**
     * A failure before a verified identity must neither provision nor log the shopper in.
     */
    private function expectNoProvisioning(): void
    {
        $this->provisioner->expects(self::never())->method('provision');
        $this->groupAssigner->expects(self::never())->method('assign');
        $this->sessionCreator->expects(self::never())->method('create');
    }

    public function testProvisionsCustomerEstablishesSessionAndRedirectsToAccountOnSuccess(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);

        $identity = new Identity('user-1', 'user@example.com', 'User One', ['shoppers']);
        $customer = $this->createStub(CustomerInterface::class);
        $groupedCustomer = $this->createStub(CustomerInterface::class);

        $this->handler->expects(self::once())
            ->method('handle')
            ->with('the-code', 'the-state')
            ->willReturn($identity);
        $this->provisioner->expects(self::once())
            ->method('provision')
            ->with($identity)
            ->willReturn($customer);
        // The group assigner runs on the provisioned customer; the session is
        // established for whatever customer it returns (re-saved when the group changed).
        $this->groupAssigner->expects(self::once())
            ->method('assign')
            ->with($customer, $identity)
            ->willReturn($groupedCustomer);
        $this->sessionCreator->expects(self::once())->method('create')->with($groupedCustomer);

        $this->redirect->expects(self::once())->method('setPath')->with('customer/account');
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testShowsLocalizedErrorWhenProvisioningFails(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectLoginRedirect();

        $this->handler->expects(self::once())
            ->method('handle')
            ->willReturn(new Identity('user-1', null, null, []));
        $this->provisioner->expects(self::once())
            ->method('provision')
            ->willThrowException(new LocalizedException(
                __('The identity provider did not release an email address required to create a customer account.')
            ));
        // A failed provision must never assign a group or establish a session.
        $this->groupAssigner->expects(self::never())->method('assign');
        $this->sessionCreator->expects(self::never())->method('create');

        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The identity provider did not release an email address required to create a customer account.');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testEscapesLocalizedProvisioningErrorBeforeShowing(): void
    {
        // A repository/validation exception can embed IdP-supplied email/name; since
        // storefront messages render as raw HTML it must pass through escapeHtml.
        $payload = '<img src=x onerror=alert(1)>';
        $escaped = '&lt;img src=x onerror=alert(1)&gt;';

        $escaper = $this->createMock(Escaper::class);
        $escaper->expects(self::once())
            ->method('escapeHtml')
            ->with($payload)
            ->willReturn($escaped);

        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $controller = new Callback(
            $this->request,
            $this->handler,
            $this->provisioner,
            $this->groupAssigner,
            $this->sessionCreator,
            $redirectFactory,
            $this->messageManager,
            $escaper,
            $this->logger
        );

        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectLoginRedirect();

        $this->handler->expects(self::once())
            ->method('handle')
            ->willReturn(new Identity('user-1', null, null, []));
        $this->provisioner->expects(self::once())
            ->method('provision')
            ->willThrowException(new LocalizedException(__($payload)));
        // A failed provision must never assign a group or establish a session.
        $this->groupAssigner->expects(self::never())->method('assign');
        $this->sessionCreator->expects(self::never())->method('create');

        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with($escaped);

        self::assertSame($this->redirect, $controller->execute());
    }

    public function testRedirectsToLoginOnIdpError(): void
    {
        $this->withParams(['error' => 'access_denied', 'error_description' => 'User denied access']);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $this->handler->expects(self::never())->method('handle');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The identity provider rejected the sign-in: User denied access');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testEscapesAttackerControlledIdpErrorDescription(): void
    {
        // Storefront messages render as raw HTML: the IdP-supplied error_description
        // must pass through escapeHtml before it reaches the message manager.
        $payload = '<script>alert(1)</script>';
        $escaped = '&lt;script&gt;alert(1)&lt;/script&gt;';

        $escaper = $this->createMock(Escaper::class);
        $escaper->expects(self::once())
            ->method('escapeHtml')
            ->with($payload)
            ->willReturn($escaped);

        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $controller = new Callback(
            $this->request,
            $this->handler,
            $this->provisioner,
            $this->groupAssigner,
            $this->sessionCreator,
            $redirectFactory,
            $this->messageManager,
            $escaper,
            $this->logger
        );

        $this->withParams(['error' => 'invalid_request', 'error_description' => $payload]);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $this->handler->expects(self::never())->method('handle');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The identity provider rejected the sign-in: ' . $escaped);

        self::assertSame($this->redirect, $controller->execute());
    }

    public function testRedirectsToLoginOnIdpErrorWithoutDescription(): void
    {
        $this->withParams(['error' => 'server_error']);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $this->handler->expects(self::never())->method('handle');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The identity provider rejected the sign-in: server_error');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testRedirectsToLoginOnIncompleteResponse(): void
    {
        $this->withParams(['code' => '', 'state' => 'the-state']);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $this->handler->expects(self::never())->method('handle');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The SSO sign-in response was incomplete. Please try again.');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testShowsLocalizedErrorFromHandler(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $this->handler->expects(self::once())
            ->method('handle')
            ->willThrowException(new LocalizedException(
                __('The SSO sign-in session is invalid or has expired. Please try again.')
            ));

        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The SSO sign-in session is invalid or has expired. Please try again.');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testLogsAndShowsGenericOnUnexpectedError(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $error = new \RuntimeException('token endpoint down');
        $this->handler->expects(self::once())->method('handle')->willThrowException($error);

        $this->logger->expects(self::once())->method('critical')->with($error);
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('Could not complete SSO sign-in. Please try again or contact the store owner.');

        self::assertSame($this->redirect, $this->controller->execute());
    }
}
