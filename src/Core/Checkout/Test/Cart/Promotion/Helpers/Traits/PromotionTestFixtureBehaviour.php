<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Test\Cart\Promotion\Helpers\Traits;

use Shopware\Core\Checkout\Promotion\Aggregate\PromotionDiscount\PromotionDiscountEntity;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

trait PromotionTestFixtureBehaviour
{
    /**
     * Creates a new product in the database.
     */
    public function createTestFixtureProduct(string $productId, float $grossPrice, float $taxRate, ContainerInterface $container)
    {
        /** @var EntityRepositoryInterface $productRepository */
        $productRepository = $container->get('product.repository');

        $context = $container->get(SalesChannelContextFactory::class)->create(Uuid::randomHex(), Defaults::SALES_CHANNEL);

        $productRepository->create(
            [
                [
                    'id' => $productId,
                    'productNumber' => $productId,
                    'stock' => 1,
                    'name' => 'Test',
                    'active' => true,
                    'price' => [
                        [
                            'currencyId' => Defaults::CURRENCY,
                            'gross' => $grossPrice,
                            'net' => 9, 'linked' => false,
                        ],
                    ],
                    'manufacturer' => ['name' => 'test'],
                    'tax' => ['taxRate' => $taxRate, 'name' => 'with id'],
                    'visibilities' => [
                        ['salesChannelId' => $context->getSalesChannel()->getId(), 'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL],
                    ],
                    'categories' => [
                        ['id' => Uuid::randomHex(), 'name' => 'Clothing'],
                    ],
                ],
            ],
            $context->getContext()
        );
    }

    /**
     * Creates a new absolute promotion in the database.
     */
    public function createTestFixtureAbsolutePromotion(string $promotionId, string $code, float $value, ContainerInterface $container)
    {
        /** @var EntityRepositoryInterface $promotionRepository */
        $promotionRepository = $container->get('promotion.repository');

        $context = $container->get(SalesChannelContextFactory::class)->create(Uuid::randomHex(), Defaults::SALES_CHANNEL);

        $this->createPromotion(
            $promotionId,
            $code,
            PromotionDiscountEntity::TYPE_ABSOLUTE,
            $value,
            $promotionRepository,
            $context
        );
    }

    /**
     * Creates a new percentage promotion in the database.
     */
    public function createTestFixturePercentagePromotion(string $promotionId, string $code, float $percentage, ContainerInterface $container)
    {
        /** @var EntityRepositoryInterface $promotionRepository */
        $promotionRepository = $container->get('promotion.repository');

        $context = $container->get(SalesChannelContextFactory::class)->create(Uuid::randomHex(), Defaults::SALES_CHANNEL);

        $this->createPromotion(
            $promotionId,
            $code,
            PromotionDiscountEntity::TYPE_PERCENTAGE,
            $percentage,
            $promotionRepository,
            $context
        );
    }

    private function createPromotion(string $promotionId, string $code, string $discountType, float $percentage, EntityRepositoryInterface $promotionRepository, SalesChannelContext $context)
    {
        $promotionRepository->create(
            [
                [
                    'id' => $promotionId,
                    'name' => 'Black Friday',
                    'active' => true,
                    'code' => $code,
                    'useCodes' => true,
                    'salesChannels' => [
                        ['salesChannelId' => Defaults::SALES_CHANNEL, 'priority' => 1],
                    ],
                    'discounts' => [
                        [
                            'id' => Uuid::randomHex(),
                            'scope' => PromotionDiscountEntity::SCOPE_CART,
                            'type' => $discountType,
                            'value' => $percentage,
                            'considerAdvancedRules' => false,
                        ],
                    ],
                ],
            ],
            $context->getContext()
        );
    }
}
