<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Artem\Checkout\Test\Unit\Model;

use Magento\Checkout\Api\Data\TotalsInformationInterface;
use Magento\Checkout\Model\TotalsInformationManagement;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Model\Quote\Address;

class TotalsInformationManagementTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var CartRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $cartRepositoryMock;

    /**
     * @var CartTotalRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $cartTotalRepositoryMock;

    /**
     * @var TotalsInformationManagement
     */
    private $totalsInformationManagement;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->cartRepositoryMock = $this->createMock(
            CartRepositoryInterface::class
        );
        $this->cartTotalRepositoryMock = $this->createMock(
            CartTotalRepositoryInterface::class
        );

        $this->totalsInformationManagement = $this->objectManager->getObject(
            TotalsInformationManagement::class,
            [
                'cartRepository' => $this->cartRepositoryMock,
                'cartTotalRepository' => $this->cartTotalRepositoryMock,
            ]
        );
    }

    /**
     * Test for \Magento\Checkout\Model\TotalsInformationManagement::calculate.
     *
     * @param string|null $carrierCode
     * @param string|null $carrierMethod
     * @param int $methodSetCount
     * @dataProvider dataProviderCalculate
     */
    public function testCalculate(?string $carrierCode, ?string $carrierMethod, int $methodSetCount)
    {
        $cartId = 1;
        $cartMock = $this->createMock(
            \Magento\Quote\Model\Quote::class
        );
        $cartMock->expects($this->once())->method('getItemsCount')->willReturn(1);
        $cartMock->expects($this->once())->method('getIsVirtual')->willReturn(false);
        $this->cartRepositoryMock->expects($this->once())->method('get')->with($cartId)->willReturn($cartMock);
        $this->cartTotalRepositoryMock->expects($this->once())->method('get')->with($cartId);

        $addressInformationMock = $this->createMock(
            TotalsInformationInterface::class
        );
        $addressMock = $this->getMockBuilder(Address::class)
            ->addMethods(
                [
                    'setShippingMethod',
                    'setCollectShippingRates',
                ]
            )
            ->disableOriginalConstructor()
            ->getMock();

        $addressInformationMock->expects($this->once())->method('getAddress')->willReturn($addressMock);
        $addressInformationMock->expects($this->any())->method('getShippingCarrierCode')->willReturn($carrierCode);
        $addressInformationMock->expects($this->any())->method('getShippingMethodCode')->willReturn($carrierMethod);
        $cartMock->expects($this->once())->method('setShippingAddress')->with($addressMock);
        $cartMock->expects($this->exactly($methodSetCount))->method('getShippingAddress')->willReturn($addressMock);
        $addressMock->expects($this->exactly($methodSetCount))
            ->method('setCollectShippingRates')->with(true)->willReturn($addressMock);
        $addressMock->expects($this->exactly($methodSetCount))
            ->method('setShippingMethod')->with($carrierCode . '_' . $carrierMethod);
        $cartMock->expects($this->once())->method('collectTotals');

        $this->totalsInformationManagement->calculate($cartId, $addressInformationMock);
    }

    /**
     * Data provider for testCalculate.
     *
     * @return array
     */
    public function dataProviderCalculate(): array
    {
        return [
            [
                null,
                null,
                0
            ],
            [
                null,
                'carrier_method',
                0
            ],
            [
                'carrier_code',
                null,
                0
            ],
            [
                'carrier_code',
                'carrier_method',
                1
            ]
        ];
    }
}
