<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\SalesChannel;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Framework\Pricing\CalculatedListingPrice;

class SalesChannelProductEntity extends ProductEntity
{
    public const VISIBILITY_FILTERED = 'product-visibility';

    /**
     * @var CalculatedListingPrice
     */
    protected $calculatedListingPrice;

    /**
     * @var PriceCollection
     */
    protected $calculatedPrices;

    /**
     * @var CalculatedPrice
     */
    protected $calculatedPrice;

    /**
     * @var PropertyGroupCollection|null
     */
    protected $sortedProperties;

    public function isAvailable(): bool
    {
        if (!$this->getIsCloseout()) {
            return true;
        }

        return $this->getStock() >= $this->getMinPurchase();
    }

    public function getCalculatedListingPrice(): CalculatedListingPrice
    {
        return $this->calculatedListingPrice;
    }

    public function setCalculatedListingPrice(CalculatedListingPrice $calculatedListingPrice): void
    {
        $this->calculatedListingPrice = $calculatedListingPrice;
    }

    public function setCalculatedPrices(PriceCollection $prices): void
    {
        $this->calculatedPrices = $prices;
    }

    public function getCalculatedPrices(): PriceCollection
    {
        return $this->calculatedPrices;
    }

    public function getCalculatedPrice(): CalculatedPrice
    {
        return $this->calculatedPrice;
    }

    public function setCalculatedPrice(CalculatedPrice $calculatedPrice): void
    {
        $this->calculatedPrice = $calculatedPrice;
    }

    public function getSortedProperties(): ?PropertyGroupCollection
    {
        return $this->sortedProperties;
    }

    public function setSortedProperties(?PropertyGroupCollection $sortedProperties): void
    {
        $this->sortedProperties = $sortedProperties;
    }

    public function hasPriceRange(): bool
    {
        return $this->getCalculatedListingPrice()->hasRange() || $this->getCalculatedPrices()->count() > 1;
    }
}
