<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

/**
 * Test class for \Magento\Checkout\Model\Session
 */
namespace Artem\Checkout\Test\Unit\Model;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\State;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\CollectionFactory;
use Magento\Framework\Session\Storage;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SessionTest extends TestCase
{
    /**
     * @var ObjectManager
     */
    protected $_helper;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_session;

    protected function setUp(): void
    {
        $this->_helper = new ObjectManager($this);
    }

    /**
     * @param int|null $orderId
     * @param int|null $incrementId
     * @param Order|MockObject $orderMock
     * @dataProvider getLastRealOrderDataProvider
     */
    public function testGetLastRealOrder($orderId, $incrementId, $orderMock)
    {
        $orderFactory = $this->getMockBuilder(OrderFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $orderFactory->expects($this->once())->method('create')->willReturn($orderMock);

        $messageCollectionFactory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $quoteRepository = $this->getMockForAbstractClass(CartRepositoryInterface::class);

        $appState = $this->getMockBuilder(State::class)
            ->addMethods(['isInstalled'])
            ->disableOriginalConstructor()
            ->getMock();
        $appState->expects($this->any())->method('isInstalled')->willReturn(true);

        $request = $this->createMock(Http::class);
        $request->expects($this->any())->method('getHttpHost')->willReturn([]);

        $constructArguments = $this->_helper->getConstructArguments(
            Session::class,
            [
                'request' => $request,
                'orderFactory' => $orderFactory,
                'messageCollectionFactory' => $messageCollectionFactory,
                'quoteRepository' => $quoteRepository,
                'storage' => new Storage()
            ]
        );
        $this->_session = $this->_helper->getObject(Session::class, $constructArguments);
        $this->_session->setLastRealOrderId($orderId);

        $this->assertSame($orderMock, $this->_session->getLastRealOrder());
        if ($orderId == $incrementId) {
            $this->assertSame($orderMock, $this->_session->getLastRealOrder());
        }
    }

    /**
     * @return array
     */
    public function getLastRealOrderDataProvider()
    {
        return [
            [null, 1, $this->_getOrderMock(1, null)],
            [1, 1, $this->_getOrderMock(1, 1)],
            [1, null, $this->_getOrderMock(null, 1)]
        ];
    }

    /**
     * @param int|null $incrementId
     * @param int|null $orderId
     * @return Order|MockObject
     */
    protected function _getOrderMock($incrementId, $orderId)
    {
        /** @var MockObject|\Magento\Sales\Model\Order $order */
        $order = $this->getMockBuilder(
            Order::class
        )->disableOriginalConstructor()
            ->setMethods(
                ['getIncrementId', 'loadByIncrementId', '__sleep']
            )->getMock();

        if ($orderId && $incrementId) {
            $order->expects($this->once())->method('getIncrementId')->willReturn($incrementId);
            $order->expects($this->once())->method('loadByIncrementId')->with($orderId);
        }

        return $order;
    }

    /**
     * @param $paramToClear
     * @dataProvider clearHelperDataDataProvider
     */
    public function testClearHelperData($paramToClear)
    {
        $storage = new Storage('default', [$paramToClear => 'test_data']);
        $this->_session = $this->_helper->getObject(Session::class, ['storage' => $storage]);

        $this->_session->clearHelperData();
        $this->assertNull($this->_session->getData($paramToClear));
    }

    /**
     * @return array
     */
    public function clearHelperDataDataProvider()
    {
        return [
            ['redirect_url'],
            ['last_order_id'],
            ['last_real_order_id'],
            ['additional_messages']
        ];
    }

    /**
     * @param bool $hasOrderId
     * @param bool $hasQuoteId
     * @dataProvider restoreQuoteDataProvider
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testRestoreQuote($hasOrderId, $hasQuoteId)
    {
        $order = $this->createPartialMock(
            Order::class,
            ['getId', 'loadByIncrementId']
        );
        $order->expects($this->once())->method('getId')->willReturn($hasOrderId ? 'order id' : null);
        $orderFactory = $this->createPartialMock(OrderFactory::class, ['create']);
        $orderFactory->expects($this->once())->method('create')->willReturn($order);
        $quoteRepository = $this->getMockBuilder(CartRepositoryInterface::class)
            ->setMethods(['save'])
            ->getMockForAbstractClass();
        $storage = new Storage();
        $store = $this->createMock(Store::class);
        $storeManager = $this->getMockForAbstractClass(StoreManagerInterface::class);
        $storeManager->expects($this->any())->method('getStore')->willReturn($store);
        $eventManager = $this->getMockForAbstractClass(ManagerInterface::class);

        /** @var Session $session */
        $session = $this->_helper->getObject(
            Session::class,
            [
                'orderFactory' => $orderFactory,
                'quoteRepository' => $quoteRepository,
                'storage' => $storage,
                'storeManager' => $storeManager,
                'eventManager' => $eventManager
            ]
        );
        $lastOrderId = 'last order id';
        $quoteId = 'quote id';
        $anotherQuoteId = 'another quote id';
        $session->setLastRealOrderId($lastOrderId);
        $session->setQuoteId($quoteId);

        if ($hasOrderId) {
            $order->setQuoteId($quoteId);
            $quote = $this->createPartialMock(
                Quote::class,
                ['setIsActive', 'getId', 'setReservedOrderId', 'save']
            );
            if ($hasQuoteId) {
                $quoteRepository->expects($this->once())->method('get')->with($quoteId)->willReturn($quote);
                $quote->expects(
                    $this->any()
                )->method(
                    'getId'
                )->willReturn(
                    $anotherQuoteId
                );
                $eventManager->expects(
                    $this->once()
                )->method(
                    'dispatch'
                )->with(
                    'restore_quote',
                    ['order' => $order, 'quote' => $quote]
                );
                $quote->expects(
                    $this->once()
                )->method(
                    'setIsActive'
                )->with(
                    1
                )->willReturnSelf();
                $quote->expects(
                    $this->once()
                )->method(
                    'setReservedOrderId'
                )->with(
                    $this->isNull()
                )->willReturnSelf();
                $quoteRepository->expects($this->once())->method('save')->with($quote);
            } else {
                $quoteRepository->expects($this->once())
                    ->method('get')
                    ->with($quoteId)
                    ->willThrowException(
                        new NoSuchEntityException()
                    );
                $quote->expects($this->never())->method('setIsActive');
                $quote->expects($this->never())->method('setReservedOrderId');
                $quote->expects($this->never())->method('save');
            }
        }
        $result = $session->restoreQuote();
        if ($hasOrderId && $hasQuoteId) {
            $this->assertNull($session->getLastRealOrderId());
            $this->assertEquals($anotherQuoteId, $session->getQuoteId());
        } else {
            $this->assertEquals($lastOrderId, $session->getLastRealOrderId());
            $this->assertEquals($quoteId, $session->getQuoteId());
        }
        $this->assertEquals($result, $hasOrderId && $hasQuoteId);
    }

    /**
     * @return array
     */
    public function restoreQuoteDataProvider()
    {
        return [[true, true], [true, false], [false, true], [false, false]];
    }

    public function testHasQuote()
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->getMock();
        $session = $this->_helper->getObject(Session::class, ['quote' => $quote]);
        $this->assertFalse($session->hasQuote());
    }

    public function testReplaceQuote()
    {
        $replaceQuoteId = 3;
        $websiteId = 1;

        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->setMethods(['getWebsiteId'])
            ->getMock();
        $store->expects($this->any())
            ->method('getWebsiteId')
            ->willReturn($websiteId);

        $storeManager = $this->getMockForAbstractClass(StoreManagerInterface::class);
        $storeManager->expects($this->any())
            ->method('getStore')
            ->willReturn($store);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->getMock();
        $quote->expects($this->once())
            ->method('getId')
            ->willReturn($replaceQuoteId);

        $storage = $this->getMockBuilder(Storage::class)
            ->disableOriginalConstructor()
            ->setMethods(['setData', 'getData'])
            ->getMock();

        $storage->expects($this->any())
            ->method('getData')
            ->willReturn($replaceQuoteId);
        $storage->expects($this->any())
            ->method('setData');

        $quoteIdMaskMock = $this->getMockBuilder(QuoteIdMask::class)
            ->addMethods(['getMaskedId', 'setQuoteId'])
            ->onlyMethods(['load', 'save'])
            ->disableOriginalConstructor()
            ->getMock();
        $quoteIdMaskMock->expects($this->once())->method('load')->with($replaceQuoteId, 'quote_id')->willReturnSelf();
        $quoteIdMaskMock->expects($this->once())->method('getMaskedId')->willReturn(null);
        $quoteIdMaskMock->expects($this->once())->method('setQuoteId')->with($replaceQuoteId)->willReturnSelf();
        $quoteIdMaskMock->expects($this->once())->method('save');

        $quoteIdMaskFactoryMock = $this->createPartialMock(QuoteIdMaskFactory::class, ['create']);
        $quoteIdMaskFactoryMock->expects($this->once())->method('create')->willReturn($quoteIdMaskMock);

        $session = $this->_helper->getObject(
            Session::class,
            [
                'storeManager' => $storeManager,
                'storage' => $storage,
                'quoteIdMaskFactory' => $quoteIdMaskFactoryMock
            ]
        );

        $session->replaceQuote($quote);

        $this->assertSame($quote, $session->getQuote());
        $this->assertEquals($replaceQuoteId, $session->getQuoteId());
    }

    public function testClearStorage()
    {
        $storage = $this->getMockBuilder(Storage::class)
            ->disableOriginalConstructor()
            ->setMethods(['unsetData'])
            ->getMock();
        $storage->expects($this->once())
            ->method('unsetData');

        $session = $this->_helper->getObject(
            Session::class,
            [
                'storage' => $storage
            ]
        );

        $this->assertInstanceOf(Session::class, $session->clearStorage());
        $this->assertFalse($session->hasQuote());
    }

    public function testResetCheckout()
    {
        /** @var $session \Magento\Checkout\Model\Session */
        $session = $this->_helper->getObject(
            Session::class,
            ['storage' => new Storage()]
        );
        $session->resetCheckout();
        $this->assertEquals(Session::CHECKOUT_STATE_BEGIN, $session->getCheckoutState());
    }

    public function testGetStepData()
    {
        $stepData = [
            'simple' => 'data',
            'complex' => [
                'key' => 'value',
            ],
        ];
        /** @var $session \Magento\Checkout\Model\Session */
        $session = $this->_helper->getObject(
            Session::class,
            ['storage' => new Storage()]
        );
        $session->setSteps($stepData);
        $this->assertEquals($stepData, $session->getStepData());
        $this->assertFalse($session->getStepData('invalid_key'));
        $this->assertEquals($stepData['complex'], $session->getStepData('complex'));
        $this->assertFalse($session->getStepData('simple', 'invalid_sub_key'));
        $this->assertEquals($stepData['complex']['key'], $session->getStepData('complex', 'key'));
    }

    /**
     * Ensure that if quote not exist for customer quote will be null
     *
     * @return void
     */
    public function testGetQuote(): void
    {
        $storeManager = $this->getMockForAbstractClass(StoreManagerInterface::class);
        $customerSession = $this->createMock(\Magento\Customer\Model\Session::class);
        $quoteRepository = $this->getMockForAbstractClass(CartRepositoryInterface::class);
        $quoteFactory = $this->createMock(QuoteFactory::class);
        $quote = $this->createMock(Quote::class);
        $logger = $this->getMockForAbstractClass(LoggerInterface::class);
        $loggerMethods = get_class_methods(LoggerInterface::class);

        $quoteFactory->expects($this->once())
            ->method('create')
            ->willReturn($quote);
        $customerSession->expects($this->exactly(3))
            ->method('isLoggedIn')
            ->willReturn(true);
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->setMethods(['getWebsiteId'])
            ->getMock();
        $storeManager->expects($this->any())
            ->method('getStore')
            ->willReturn($store);
        $storage = $this->getMockBuilder(Storage::class)
            ->disableOriginalConstructor()
            ->setMethods(['setData', 'getData'])
            ->getMock();
        $storage->expects($this->at(0))
            ->method('getData')
            ->willReturn(1);
        $quoteRepository->expects($this->once())
            ->method('getActiveForCustomer')
            ->willThrowException(new NoSuchEntityException());

        foreach ($loggerMethods as $method) {
            $logger->expects($this->never())->method($method);
        }

        $quote->expects($this->once())
            ->method('setCustomer')
            ->with(null);

        $constructArguments = $this->_helper->getConstructArguments(
            Session::class,
            [
                'storeManager' => $storeManager,
                'quoteRepository' => $quoteRepository,
                'customerSession' => $customerSession,
                'storage' => $storage,
                'quoteFactory' => $quoteFactory,
                'logger' => $logger
            ]
        );
        $this->_session = $this->_helper->getObject(Session::class, $constructArguments);
        $this->_session->getQuote();
    }

    public function testSetStepData()
    {
        $stepData = [
            'complex' => [
                'key' => 'value',
            ],
        ];
        /** @var $session \Magento\Checkout\Model\Session */
        $session = $this->_helper->getObject(
            Session::class,
            ['storage' => new Storage()]
        );
        $session->setSteps($stepData);

        $session->setStepData('complex', 'key2', 'value2');
        $session->setStepData('simple', ['key' => 'value']);
        $session->setStepData('simple', 'key2', 'value2');
        $expectedResult = [
            'complex' => [
                'key' => 'value',
                'key2' => 'value2',
            ],
            'simple' => [
                'key' => 'value',
                'key2' => 'value2',
            ],
        ];
        $this->assertEquals($expectedResult, $session->getSteps());
    }
}
