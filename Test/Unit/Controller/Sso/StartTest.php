<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Controller\Sso;

use MageDevGroup\CustomerSso\Controller\Sso\Start;
use MageDevGroup\CustomerSso\Model\Oidc\AuthorizationStarter;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StartTest extends TestCase
{
    /** @var Redirect&MockObject */
    private $redirect;

    /** @var ManagerInterface&MockObject */
    private $messageManager;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var AuthorizationStarter&Stub */
    private $starter;

    /** @var Start */
    private Start $controller;

    protected function setUp(): void
    {
        $this->redirect = $this->createMock(Redirect::class);

        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->starter = $this->createStub(AuthorizationStarter::class);

        $this->controller = new Start(
            $this->starter,
            $redirectFactory,
            $this->messageManager,
            $this->logger
        );
    }

    public function testRedirectsToIdpUrl(): void
    {
        $this->starter->method('start')->willReturn('https://idp.example/authorize?state=abc');

        $this->redirect->expects(self::once())
            ->method('setUrl')
            ->with('https://idp.example/authorize?state=abc')
            ->willReturnSelf();
        $this->redirect->expects(self::never())->method('setPath');
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testRedirectsBackToLoginOnLocalizedError(): void
    {
        $this->starter->method('start')
            ->willThrowException(new LocalizedException(__('Customer SSO is disabled.')));

        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('Customer SSO is disabled.');
        $this->redirect->expects(self::never())->method('setUrl');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('customer/account/login')
            ->willReturnSelf();
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testRedirectsBackToLoginAndLogsOnUnexpectedError(): void
    {
        $error = new \RuntimeException('discovery down');
        $this->starter->method('start')->willThrowException($error);

        $this->logger->expects(self::once())->method('critical')->with($error);
        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->redirect->expects(self::never())->method('setUrl');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('customer/account/login')
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->controller->execute());
    }
}
