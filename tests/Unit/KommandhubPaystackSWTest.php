<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests\Unit;

use Kommandhub\PaystackSW\KommandhubPaystackSW;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;

class KommandhubPaystackSWTest extends TestCase
{
    private KommandhubPaystackSW $plugin;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->plugin = new KommandhubPaystackSW(true, '');
        $this->container = $this->createMock(ContainerInterface::class);
    }

    public function testExecuteComposerCommandsReturnsTrue(): void
    {
        $this->assertTrue($this->plugin->executeComposerCommands());
    }

    public function testInstall(): void
    {
        $context = Context::createDefaultContext();
        $installContext = $this->createMock(InstallContext::class);
        $installContext->method('getContext')->willReturn($context);

        $paymentRepository = $this->createMock(EntityRepository::class);
        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn(null); // Payment method doesn't exist
        $paymentRepository->method('searchIds')->willReturn($idSearchResult);

        $pluginIdProvider = $this->createMock(PluginIdProvider::class);
        $pluginIdProvider->method('getPluginIdByBaseClass')->willReturn('plugin-id');

        $this->container->method('get')->willReturnMap([
            ['payment_method.repository', $paymentRepository],
            [PluginIdProvider::class, $pluginIdProvider],
        ]);

        $this->plugin->setContainer($this->container);

        $paymentRepository->expects($this->once())->method('create');

        $this->plugin->install($installContext);
    }

    public function testInstallWhenPaymentMethodExists(): void
    {
        $context = Context::createDefaultContext();
        $installContext = $this->createMock(InstallContext::class);
        $installContext->method('getContext')->willReturn($context);

        $paymentRepository = $this->createMock(EntityRepository::class);
        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('existing-id');
        $paymentRepository->method('searchIds')->willReturn($idSearchResult);

        $this->container->method('get')->with('payment_method.repository')->willReturn($paymentRepository);
        $this->plugin->setContainer($this->container);

        $paymentRepository->expects($this->never())->method('create');

        $this->plugin->install($installContext);
    }

    public function testUninstall(): void
    {
        $context = Context::createDefaultContext();
        $uninstallContext = $this->createMock(UninstallContext::class);
        $uninstallContext->method('getContext')->willReturn($context);
        $uninstallContext->method('keepUserData')->willReturn(true);

        $paymentRepository = $this->createMock(EntityRepository::class);
        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('existing-id');
        $paymentRepository->method('searchIds')->willReturn($idSearchResult);

        $this->container->method('get')->with('payment_method.repository')->willReturn($paymentRepository);
        $this->plugin->setContainer($this->container);

        $paymentRepository->expects($this->once())->method('update')->with([
            ['id' => 'existing-id', 'active' => false],
        ], $context);

        $this->plugin->uninstall($uninstallContext);
    }

    public function testActivate(): void
    {
        $context = Context::createDefaultContext();
        $activateContext = $this->createMock(ActivateContext::class);
        $activateContext->method('getContext')->willReturn($context);

        $paymentRepository = $this->createMock(EntityRepository::class);
        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('existing-id');
        $paymentRepository->method('searchIds')->willReturn($idSearchResult);

        $this->container->method('get')->with('payment_method.repository')->willReturn($paymentRepository);
        $this->plugin->setContainer($this->container);

        $paymentRepository->expects($this->once())->method('update')->with([
            ['id' => 'existing-id', 'active' => true],
        ], $context);

        $this->plugin->activate($activateContext);
    }

    public function testDeactivate(): void
    {
        $context = Context::createDefaultContext();
        $deactivateContext = $this->createMock(DeactivateContext::class);
        $deactivateContext->method('getContext')->willReturn($context);

        $paymentRepository = $this->createMock(EntityRepository::class);
        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('existing-id');
        $paymentRepository->method('searchIds')->willReturn($idSearchResult);

        $this->container->method('get')->with('payment_method.repository')->willReturn($paymentRepository);
        $this->plugin->setContainer($this->container);

        $paymentRepository->expects($this->once())->method('update')->with([
            ['id' => 'existing-id', 'active' => false],
        ], $context);

        $this->plugin->deactivate($deactivateContext);
    }

    public function testMethodsWhenContainerIsNull(): void
    {
        $context = Context::createDefaultContext();
        $installContext = $this->createMock(InstallContext::class);
        $installContext->method('getContext')->willReturn($context);

        // Should not throw exception and just return
        $this->plugin->install($installContext);

        $activateContext = $this->createMock(ActivateContext::class);
        $activateContext->method('getContext')->willReturn($context);
        $this->plugin->activate($activateContext);

        $this->assertTrue(true); // Assertion to avoid risky test
    }
    public function testUninstallWithKeepUserDataFalse(): void
    {
        $context = Context::createDefaultContext();
        $uninstallContext = $this->createMock(UninstallContext::class);
        $uninstallContext->method('getContext')->willReturn($context);
        $uninstallContext->method('keepUserData')->willReturn(false);

        $paymentRepository = $this->createMock(EntityRepository::class);
        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('existing-id');
        $paymentRepository->method('searchIds')->willReturn($idSearchResult);

        $this->container->method('get')->with('payment_method.repository')->willReturn($paymentRepository);
        $this->plugin->setContainer($this->container);

        $this->plugin->uninstall($uninstallContext);
        $this->assertTrue(true);
    }
}
