<?php declare(strict_types=1);

namespace Shopware\Core\Content\Test\Product\Repository;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceEntity;
use Shopware\Core\Content\Product\Aggregate\ProductSearchKeyword\ProductSearchKeywordCollection;
use Shopware\Core\Content\Product\Aggregate\ProductSearchKeyword\ProductSearchKeywordEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Context\SystemSource;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\PaginationCriteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Pricing\ListingPrice;
use Shopware\Core\Framework\Pricing\ListingPriceCollection;
use Shopware\Core\Framework\Pricing\Price;
use Shopware\Core\Framework\Pricing\PriceCollection;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Shopware\Core\System\Tax\TaxDefinition;
use Shopware\Core\System\Tax\TaxEntity;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ProductRepositoryTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * @var EntityRepositoryInterface
     */
    private $repository;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var Context
     */
    private $context;

    protected function setUp(): void
    {
        $this->repository = $this->getContainer()->get('product.repository');
        $this->eventDispatcher = $this->getContainer()->get('event_dispatcher');
        $this->connection = $this->getContainer()->get(Connection::class);
        $this->context = Context::createDefaultContext();
    }

    public function testWritePrice()
    {
        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'name' => 'test',
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false],
            ],
            'manufacturer' => ['name' => 'test'],
            'tax' => ['name' => 'test', 'taxRate' => 15],
        ];

        $this->repository->create([$data], $this->context);

        /** @var ProductEntity $product */
        $product = $this->repository
            ->search(new Criteria([$id]), $this->context)
            ->get($id);

        static::assertInstanceOf(PriceCollection::class, $product->getPrice());

        static::assertEquals(
            new Price(Defaults::CURRENCY, 10, 15, false),
            $product->getPrice()->getCurrencyPrice(Defaults::CURRENCY)
        );
    }

    public function testWriteMultipleCurrencyPrices()
    {
        $id = Uuid::randomHex();

        $this->getContainer()->get('currency.repository')->create(
            [
                [
                    'id' => $id,
                    'factor' => 2,
                    'shortName' => 'test',
                    'name' => 'name',
                    'symbol' => 'A',
                    'isoCode' => 'A',
                    'decimalPrecision' => 2,
                ],
            ],
            $this->context
        );

        $data = [
            'id' => $id,
            'name' => 'test',
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false],
                ['currencyId' => $id, 'gross' => 150, 'net' => 100, 'linked' => true],
            ],
            'manufacturer' => ['name' => 'test'],
            'tax' => ['name' => 'test', 'taxRate' => 15],
        ];

        $this->repository->create([$data], $this->context);

        /** @var ProductEntity $product */
        $product = $this->repository
            ->search(new Criteria([$id]), $this->context)
            ->get($id);

        static::assertInstanceOf(PriceCollection::class, $product->getPrice());

        static::assertCount(2, $product->getPrice());

        static::assertEquals(
            new Price(Defaults::CURRENCY, 10, 15, false),
            $product->getCurrencyPrice(Defaults::CURRENCY)
        );

        static::assertEquals(
            new Price($id, 100, 150, true),
            $product->getCurrencyPrice($id)
        );
    }

    public function testVariantNameIsNullable()
    {
        $parentId = Uuid::randomHex();
        $variantId = Uuid::randomHex();

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'price' => [
                    ['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false],
                ],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 15],
                // name should be required
                'name' => 'parent',
            ],
            [
                'id' => $variantId,
                'productNumber' => Uuid::randomHex(),
                'parentId' => $parentId,
                'stock' => 15,
            ],
        ];

        try {
            $this->repository->create($products, $this->context);

            $update = ['name' => null, 'id' => $variantId];

            $this->repository->update([$update], $this->context);
        } catch (\Exception $e) {
            static::fail('Can not reset variant name to null');
        }

        /** @var ProductEntity $variant */
        $variant = $this->repository
            ->search(new Criteria([$variantId]), $this->context)
            ->first();

        static::assertInstanceOf(ProductEntity::class, $variant);

        static::assertNull($variant->getName());
    }

    public function testNameIsRequiredForParent()
    {
        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false]],
            'manufacturer' => ['name' => 'test'],
            'tax' => ['name' => 'test', 'taxRate' => 15],
        ];

        $e = null;
        try {
            $this->repository->create([$data], $this->context);
        } catch (WriteException $e) {
        }

        static::assertInstanceOf(WriteException::class, $e);

        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'name' => 'test',
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false]],
            'manufacturer' => ['name' => 'test'],
            'tax' => ['name' => 'test', 'taxRate' => 15],
        ];
        $this->repository->create([$data], $this->context);

        $product = $this->repository
            ->search(new Criteria([$id]), $this->context)
            ->get($id);

        static::assertInstanceOf(ProductEntity::class, $product);

        $variantId = Uuid::randomHex();

        $data = [
            'id' => $variantId,
            'stock' => 10,
            'productNumber' => 'variant',
            'parentId' => $id,
        ];
        $this->repository->create([$data], $this->context);

        $variant = $this->repository
            ->search(new Criteria([$variantId]), $this->context)
            ->get($variantId);

        static::assertInstanceOf(ProductEntity::class, $variant);

        /** @var ProductEntity $variant */
        static::assertNull($variant->getName());
    }

    public function testPriceUpdateConsideredInListingPriceIndexer()
    {
        $ruleA = Uuid::randomHex();
        $ruleB = Uuid::randomHex();
        $ruleC = Uuid::randomHex();

        $context = Context::createDefaultContext();
        $this->getContainer()->get('rule.repository')->create([
            ['id' => $ruleA, 'name' => 'test', 'priority' => 1],
            ['id' => $ruleB, 'name' => 'test', 'priority' => 1],
            ['id' => $ruleC, 'name' => 'test', 'priority' => 1],
        ], $context);

        $id = Uuid::randomHex();
        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'name' => 'price test',
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false]],
            'manufacturer' => ['name' => 'test'],
            'tax' => ['name' => 'test', 'taxRate' => 15],
            'prices' => [
                $this->formatPrice(15, Defaults::CURRENCY, $ruleA, 1, 10, 10.0, $id),
                $this->formatPrice(10, Defaults::CURRENCY, $ruleA, 11, 20),
                $this->formatPrice(5, Defaults::CURRENCY, $ruleA, 21, null),

                $this->formatPrice(20, Defaults::CURRENCY, $ruleB, 1, 10),
                $this->formatPrice(15, Defaults::CURRENCY, $ruleB, 11, null),

                $this->formatPrice(10, Defaults::CURRENCY, $ruleC, 1, 10),
                $this->formatPrice(5, Defaults::CURRENCY, $ruleC, 11, null),
            ],
        ];

        $this->repository->create([$data], $context);

        /** @var ProductEntity $product */
        $product = $this->repository
            ->search(new Criteria([$id]), $context)
            ->get($id);

        $prices = $product->getListingPrices();

        static::assertInstanceOf(ListingPriceCollection::class, $prices);
        static::assertCount(9, $prices);

        $aPrices = $prices->filterByRuleId($ruleA);
        $aPrices = $aPrices->filterByCurrencyId(Defaults::CURRENCY);

        static::assertCount(1, $aPrices);

        /** @var ListingPrice $aPrice */
        $aPrice = $aPrices->first();

        static::assertEquals(5, $aPrice->getFrom()->getGross());
        static::assertEquals(15, $aPrice->getTo()->getGross());

        $update = [
            'id' => $id,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 30, 'net' => 1, 'linked' => false]],
        ];

        $this->getContainer()->get('product_price.repository')
            ->update([$update], $context);

        /** @var ProductEntity $product */
        $product = $this->repository
            ->search(new Criteria([$id]), $context)
            ->get($id);

        $prices = $product->getListingPrices();

        static::assertInstanceOf(ListingPriceCollection::class, $prices);
        static::assertCount(9, $prices);

        $aPrices = $prices->filterByRuleId($ruleA);
        $aPrices = $aPrices->filterByCurrencyId(Defaults::CURRENCY);

        static::assertCount(1, $aPrices);

        /** @var ListingPrice $aPrice */
        $aPrice = $aPrices->first();

        static::assertEquals(5, $aPrice->getFrom()->getGross());
        static::assertEquals(30, $aPrice->getTo()->getGross());
        static::assertTrue($aPrice->isDifferent());
    }

    public function testSearchKeywordIndexerConsidersUpdate()
    {
        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'name' => 'Default name',
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false]],
            'manufacturer' => ['name' => 'test'],
            'tax' => ['name' => 'test', 'taxRate' => 15],
        ];

        $this->repository->create([$data], $this->context);

        $criteria = new Criteria([$id]);
        $criteria->addAssociationPath('searchKeywords');

        $product = $this->repository
            ->search($criteria, $this->context)
            ->get($id);

        static::assertInstanceOf(ProductEntity::class, $product);

        /** @var ProductEntity $product */
        static::assertInstanceOf(ProductSearchKeywordCollection::class, $product->getSearchKeywords());

        $keywords = $product->getSearchKeywords()->map(function (ProductSearchKeywordEntity $entity) {
            return $entity->getKeyword();
        });

        static::assertContains('default', $keywords);
        static::assertContains('name', $keywords);

        $update = [
            'id' => $id,
            'name' => 'updated',
        ];

        $this->repository->update([$update], $this->context);

        $product = $this->repository
            ->search($criteria, $this->context)
            ->get($id);

        static::assertInstanceOf(ProductEntity::class, $product);

        /** @var ProductEntity $product */
        static::assertInstanceOf(ProductSearchKeywordCollection::class, $product->getSearchKeywords());

        $keywords = $product->getSearchKeywords()->map(function (ProductSearchKeywordEntity $entity) {
            return $entity->getKeyword();
        });

        static::assertNotContains('default', $keywords);
        static::assertNotContains('name', $keywords);
        static::assertContains('updated', $keywords);
    }

    public function testWriteCategories(): void
    {
        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'name' => 'test',
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false]],
            'manufacturer' => ['name' => 'test'],
            'tax' => ['name' => 'test', 'taxRate' => 15],
            'categories' => [
                ['id' => $id, 'name' => 'asd'],
            ],
        ];

        $this->repository->create([$data], $this->context);

        /** @var array $record */
        $record = $this->connection->fetchAssoc('SELECT * FROM product_category WHERE product_id = :id', ['id' => Uuid::fromHexToBytes($id)]);
        static::assertNotEmpty($record);
        static::assertEquals($record['product_id'], Uuid::fromHexToBytes($id));
        static::assertEquals($record['category_id'], Uuid::fromHexToBytes($id));

        $record = $this->connection->fetchAssoc('SELECT * FROM category WHERE id = :id', ['id' => Uuid::fromHexToBytes($id)]);
        static::assertNotEmpty($record);
    }

    public function testWriteProductWithDifferentTaxFormat(): void
    {
        $tax = Uuid::randomHex();

        $data = [
            [
                'id' => Uuid::randomHex(),
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'Test',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['taxRate' => 19, 'name' => 'without id'],
            ],
            [
                'id' => Uuid::randomHex(),
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'Test',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['id' => $tax, 'taxRate' => 17, 'name' => 'with id'],
            ],
            [
                'id' => Uuid::randomHex(),
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'Test',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'taxId' => $tax,
            ],
            [
                'id' => Uuid::randomHex(),
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'Test',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['id' => $tax, 'taxRate' => 18],
            ],
        ];

        $this->repository->create($data, $this->context);
        $ids = array_column($data, 'id');
        $criteria = new Criteria($ids);
        $criteria->addAssociation('tax');
        $products = $this->repository->search($criteria, $this->context);

        $product = $products->get($ids[0]);

        /* @var ProductEntity $product */
        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertInstanceOf(TaxEntity::class, $product->getTax());
        static::assertEquals('without id', $product->getTax()->getName());
        static::assertEquals(19, $product->getTax()->getTaxRate());

        $product = $products->get($ids[1]);
        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertInstanceOf(TaxEntity::class, $product->getTax());
        static::assertEquals($tax, $product->getTaxId());
        static::assertEquals($tax, $product->getTax()->getId());
        static::assertEquals('with id', $product->getTax()->getName());
        static::assertEquals(18, $product->getTax()->getTaxRate());

        $product = $products->get($ids[2]);
        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertInstanceOf(TaxEntity::class, $product->getTax());
        static::assertEquals($tax, $product->getTaxId());
        static::assertEquals($tax, $product->getTax()->getId());
        static::assertEquals('with id', $product->getTax()->getName());
        static::assertEquals(18, $product->getTax()->getTaxRate());

        $product = $products->get($ids[2]);
        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertInstanceOf(TaxEntity::class, $product->getTax());
        static::assertEquals($tax, $product->getTaxId());
        static::assertEquals($tax, $product->getTax()->getId());
        static::assertEquals('with id', $product->getTax()->getName());
        static::assertEquals(18, $product->getTax()->getTaxRate());
    }

    public function testWriteProductWithDifferentManufacturerStructures(): void
    {
        $manufacturerId = Uuid::randomHex();

        $data = [
            [
                'id' => Uuid::randomHex(),
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'Test',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'tax' => ['taxRate' => 17, 'name' => 'test'],
                'manufacturer' => ['name' => 'without id'],
            ],
            [
                'id' => Uuid::randomHex(),
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'Test',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'tax' => ['taxRate' => 17, 'name' => 'test'],
                'manufacturer' => ['id' => $manufacturerId, 'name' => 'with id'],
            ],
            [
                'id' => Uuid::randomHex(),
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'Test',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'tax' => ['taxRate' => 17, 'name' => 'test'],
                'manufacturerId' => $manufacturerId,
            ],
            [
                'id' => Uuid::randomHex(),
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'Test',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'tax' => ['taxRate' => 17, 'name' => 'test'],
                'manufacturer' => ['id' => $manufacturerId, 'link' => 'test'],
            ],
        ];

        $this->repository->create($data, $this->context);
        $ids = array_column($data, 'id');
        $criteria = new Criteria($ids);
        $criteria->addAssociation('manufacturer');

        $products = $this->repository->search($criteria, $this->context);

        $product = $products->get($ids[0]);

        /* @var ProductEntity $product */
        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertInstanceOf(ProductManufacturerEntity::class, $product->getManufacturer());
        static::assertEquals('without id', $product->getManufacturer()->getName());

        $product = $products->get($ids[1]);
        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertInstanceOf(ProductManufacturerEntity::class, $product->getManufacturer());
        static::assertEquals($manufacturerId, $product->getManufacturerId());
        static::assertEquals($manufacturerId, $product->getManufacturer()->getId());
        static::assertEquals('with id', $product->getManufacturer()->getName());

        $product = $products->get($ids[2]);
        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertInstanceOf(ProductManufacturerEntity::class, $product->getManufacturer());
        static::assertEquals($manufacturerId, $product->getManufacturerId());
        static::assertEquals($manufacturerId, $product->getManufacturer()->getId());
        static::assertEquals('with id', $product->getManufacturer()->getName());

        $product = $products->get($ids[2]);
        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertInstanceOf(ProductManufacturerEntity::class, $product->getManufacturer());
        static::assertEquals($manufacturerId, $product->getManufacturerId());
        static::assertEquals($manufacturerId, $product->getManufacturer()->getId());
        static::assertEquals('with id', $product->getManufacturer()->getName());
        static::assertEquals('test', $product->getManufacturer()->getLink());
    }

    public function testReadAndWriteOfProductManufacturerAssociation(): void
    {
        $id = Uuid::randomHex();

        //check nested events are triggered
        $listener = $this->getMockBuilder(CallableClass::class)->getMock();
        $listener->expects(static::exactly(2))->method('__invoke');
        $this->eventDispatcher->addListener('product.written', $listener);
        $this->eventDispatcher->addListener('product_manufacturer.written', $listener);

        $this->repository->create([
            [
                'id' => $id,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'Test',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'tax' => ['name' => 'test', 'taxRate' => 19],
                'manufacturer' => ['name' => 'test'],
            ],
        ], Context::createDefaultContext());

        //validate that nested events are triggered
        $listener = $this->getMockBuilder(CallableClass::class)->getMock();
        $listener->expects(static::exactly(2))->method('__invoke');
        $this->eventDispatcher->addListener('product.loaded', $listener);
        $this->eventDispatcher->addListener('product_manufacturer.loaded', $listener);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('manufacturer');

        $products = $this->repository->search($criteria, Context::createDefaultContext());

        //check only provided id loaded
        static::assertCount(1, $products);
        static::assertTrue($products->has($id));

        /** @var ProductEntity $product */
        $product = $products->get($id);

        //check data loading is as expected
        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertEquals($id, $product->getId());
        static::assertEquals('Test', $product->getName());

        static::assertInstanceOf(ProductManufacturerEntity::class, $product->getManufacturer());

        //check nested element loaded
        $manufacturer = $product->getManufacturer();
        static::assertEquals('test', $manufacturer->getName());
    }

    public function testReadAndWriteProductPrices(): void
    {
        $ruleA = Uuid::randomHex();
        $ruleB = Uuid::randomHex();

        $this->getContainer()->get('rule.repository')->create([
            ['id' => $ruleA, 'name' => 'test', 'priority' => 1],
            ['id' => $ruleB, 'name' => 'test', 'priority' => 2],
        ], Context::createDefaultContext());

        $id = Uuid::randomHex();
        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'name' => 'price test',
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false]],
            'manufacturer' => ['name' => 'test'],
            'tax' => ['name' => 'test', 'taxRate' => 15],
            'prices' => [
                [
                    'id' => $ruleA,
                    'quantityStart' => 1,
                    'ruleId' => $ruleA,
                    'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false]],
                ],
                [
                    'id' => $ruleB,
                    'quantityStart' => 1,
                    'ruleId' => $ruleB,
                    'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 8, 'linked' => false]],
                ],
            ],
        ];

        $this->repository->create([$data], Context::createDefaultContext());

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('prices');

        $products = $this->repository
            ->search($criteria, Context::createDefaultContext())
            ->getEntities();

        static::assertInstanceOf(ProductCollection::class, $products);
        static::assertCount(1, $products);
        static::assertTrue($products->has($id));

        $product = $products->get($id);

        /* @var ProductEntity $product */
        static::assertEquals($id, $product->getId());

        static::assertEquals(new Price(Defaults::CURRENCY, 10, 15, false), $product->getCurrencyPrice(Defaults::CURRENCY));
        static::assertCount(2, $product->getPrices());

        /** @var ProductPriceEntity $price */
        $price = $product->getPrices()->get($ruleA);
        static::assertEquals(15, $price->getPrice()->getCurrencyPrice(Defaults::CURRENCY)->getGross());
        static::assertEquals(10, $price->getPrice()->getCurrencyPrice(Defaults::CURRENCY)->getNet());

        $price = $product->getPrices()->get($ruleB);
        static::assertEquals(10, $price->getPrice()->getCurrencyPrice(Defaults::CURRENCY)->getGross());
        static::assertEquals(8, $price->getPrice()->getCurrencyPrice(Defaults::CURRENCY)->getNet());
    }

    public function testPriceRulesSorting(): void
    {
        $id = Uuid::randomHex();
        $id2 = Uuid::randomHex();
        $id3 = Uuid::randomHex();

        $ruleA = Uuid::randomHex();

        $this->getContainer()->get('rule.repository')->create([
            ['id' => $ruleA, 'name' => 'test', 'priority' => 1],
        ], Context::createDefaultContext());

        $filterId = Uuid::randomHex();

        $data = [
            [
                'id' => $id,
                'productNumber' => Uuid::randomHex(),
                'name' => 'price test 1',
                'stock' => 10,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 500, 'net' => 400, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'ean' => $filterId,
                'prices' => [
                    [
                        'quantityStart' => 1,
                        'ruleId' => $ruleA,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 14, 'linked' => false]],
                    ],
                ],
            ],
            [
                'id' => $id2,
                'productNumber' => Uuid::randomHex(),
                'name' => 'price test 2',
                'stock' => 10,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 500, 'net' => 400, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'ean' => $filterId,
                'prices' => [
                    [
                        'quantityStart' => 1,
                        'ruleId' => $ruleA,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 5, 'net' => 4, 'linked' => false]],
                    ],
                ],
            ],
            [
                'id' => $id3,
                'productNumber' => Uuid::randomHex(),
                'name' => 'price test 3',
                'stock' => 10,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 500, 'net' => 400, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'ean' => $filterId,
                'prices' => [
                    [
                        'quantityStart' => 1,
                        'ruleId' => $ruleA,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                    ],
                ],
            ],
        ];

        $this->repository->create($data, Context::createDefaultContext());

        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('product.prices.price', FieldSorting::ASCENDING));
        $criteria->addFilter(new EqualsFilter('product.ean', $filterId));

        $context = $this->createContext([$ruleA]);

        $products = $this->repository->searchIds($criteria, $context);

        static::assertEquals(
            [$id2, $id3, $id],
            $products->getIds()
        );

        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('product.prices.price', FieldSorting::DESCENDING));
        $criteria->addFilter(new EqualsFilter('product.ean', $filterId));

        /** @var IdSearchResult $products */
        $products = $this->repository->searchIds($criteria, $context);

        static::assertEquals(
            [$id, $id3, $id2],
            $products->getIds()
        );
    }

    public function testVariantInheritancePriceAndName(): void
    {
        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $parentPrice = ['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => true];
        $parentName = 'T-shirt';
        $greenPrice = ['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 14, 'linked' => true];

        $redName = 'Red shirt';

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => $parentName,
                'price' => [$parentPrice],
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'manufacturer' => ['name' => 'test'],
            ],

            //price should be inherited
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => $redName,
                'parentId' => $parentId,
            ],

            //name should be inherited
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'price' => [$greenPrice],
                'parentId' => $parentId,
            ],
        ];

        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);
        $this->repository->create($products, $context);

        $criteria = new Criteria([$redId, $greenId]);
        $products = $this->repository->search($criteria, $context);

        $criteria = new Criteria([$parentId]);
        $parents = $this->repository->search($criteria, $context);

        static::assertTrue($parents->has($parentId));
        static::assertTrue($products->has($redId));
        static::assertTrue($products->has($greenId));

        /** @var ProductEntity $parent */
        $parent = $parents->get($parentId);

        /** @var ProductEntity $red */
        $red = $products->get($redId);

        /** @var ProductEntity $green */
        $green = $products->get($greenId);

        static::assertEquals($parentPrice['gross'], $parent->getCurrencyPrice(Defaults::CURRENCY)->getGross());
        static::assertEquals($parentName, $parent->getName());

        static::assertEquals($parentPrice['gross'], $red->getCurrencyPrice(Defaults::CURRENCY)->getGross());
        static::assertEquals($redName, $red->getName());

        static::assertEquals($greenPrice['gross'], $green->getCurrencyPrice(Defaults::CURRENCY)->getGross());
        static::assertEquals($parentName, $green->getTranslated()['name']);
        static::assertNull($green->getName());

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromHexToBytes($parentId)]);
        static::assertEquals(['c' . Defaults::CURRENCY => $parentPrice], json_decode($row['price'], true));

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product_translation WHERE product_id = :id', ['id' => Uuid::fromHexToBytes($parentId)]);
        static::assertEquals($parentName, $row['name']);

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromHexToBytes($redId)]);
        static::assertNull($row['price']);

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product_translation WHERE product_id = :id', ['id' => Uuid::fromHexToBytes($redId)]);
        static::assertEquals($redName, $row['name']);

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromHexToBytes($greenId)]);
        static::assertEquals(['c' . Defaults::CURRENCY => $greenPrice], json_decode($row['price'], true));

        $row = $this->connection->fetchAssoc('SELECT * FROM product_translation WHERE product_id = :id', ['id' => Uuid::fromHexToBytes($greenId)]);
        static::assertEmpty($row);
    }

    public function testInsertAndUpdateInOneStep(): void
    {
        $id = Uuid::randomHex();
        $filterId = Uuid::randomHex();
        $data = [
            [
                'id' => $id,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'Insert',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'tax' => ['name' => 'test', 'taxRate' => 10],
                'manufacturer' => ['name' => 'test'],
                'ean' => $filterId,
            ],
            [
                'id' => $id,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'Update',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 12, 'net' => 10, 'linked' => false]],
                'ean' => $filterId,
            ],
        ];

        $this->repository->upsert($data, Context::createDefaultContext());

        $products = $this->repository->search(new Criteria([$id]), Context::createDefaultContext());
        static::assertTrue($products->has($id));

        /** @var ProductEntity $product */
        $product = $products->get($id);

        static::assertEquals('Update', $product->getName());
        static::assertEquals(12, $product->getCurrencyPrice(Defaults::CURRENCY)->getGross());

        $count = $this->connection->fetchColumn('SELECT COUNT(id) FROM product WHERE ean = :filterId', ['filterId' => $filterId]);
        static::assertEquals(1, $count);
    }

    public function testSwitchVariantToFullProduct(): void
    {
        $id = Uuid::randomHex();
        $child = Uuid::randomHex();

        $filterId = Uuid::randomHex();
        $data = [
            ['id' => $id, 'productNumber' => Uuid::randomHex(), 'stock' => 10, 'name' => 'Insert', 'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]], 'tax' => ['name' => 'test', 'taxRate' => 10], 'manufacturer' => ['name' => 'test'], 'ean' => $filterId],
            ['id' => $child, 'productNumber' => Uuid::randomHex(), 'stock' => 10, 'parentId' => $id, 'name' => 'Update', 'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 12, 'net' => 11, 'linked' => false]], 'ean' => $filterId],
        ];

        $this->repository->upsert($data, Context::createDefaultContext());

        $products = $this->repository->search(new Criteria([$id, $child]), Context::createDefaultContext());
        static::assertTrue($products->has($id));
        static::assertTrue($products->has($child));

        $raw = $this->connection->fetchAll('SELECT * FROM product WHERE ean = :filterId', ['filterId' => $filterId]);
        static::assertCount(2, $raw);

        $name = $this->connection->fetchColumn('SELECT name FROM product_translation WHERE product_id = :id', ['id' => Uuid::fromHexToBytes($child)]);
        static::assertEquals('Update', $name);

        $data = [
            [
                'id' => $child,
                'parentId' => null,
            ],
        ];

        /** @var WriteException|null $e */
        $e = null;
        try {
            $this->repository->upsert($data, Context::createDefaultContext());
        } catch (\Exception $e) {
        }

        static::assertInstanceOf(WriteException::class, $e);

        /** @var WriteConstraintViolationException $constraintViolation */
        $constraintViolation = $e->getExceptions()[0];
        static::assertInstanceOf(WriteConstraintViolationException::class, $constraintViolation);

        static::assertEquals('manufacturerId', $constraintViolation->getViolations()[0]->getPropertyPath());

        $data = [
            [
                'id' => $child,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'parentId' => null,
                'name' => 'Child transformed to parent',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 13, 'net' => 12, 'linked' => false]],
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'manufacturer' => ['name' => 'test3'],
            ],
        ];

        $this->repository->upsert($data, Context::createDefaultContext());

        $raw = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', [
            'id' => Uuid::fromHexToBytes($child),
        ]);

        static::assertNull($raw['parent_id']);

        $criteria = new Criteria([$child]);
        $criteria->addAssociation('manufacturer');
        $products = $this->repository->search($criteria, Context::createDefaultContext());
        $product = $products->get($child);

        /* @var ProductEntity $product */
        static::assertEquals('Child transformed to parent', $product->getName());
        static::assertEquals(13, $product->getCurrencyPrice(Defaults::CURRENCY)->getGross());
        static::assertEquals('test3', $product->getManufacturer()->getName());
        static::assertEquals(15, $product->getTax()->getTaxRate());
    }

    public function testSwitchVariantToFullProductWithoutName(): void
    {
        static::markTestSkipped('The test should error with because of a missing name.');

        $id = Uuid::randomHex();
        $child = Uuid::randomHex();

        $data = [
            [
                'id' => $id,
                'name' => 'Insert',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'tax' => ['name' => 'test', 'taxRate' => 10],
                'manufacturer' => ['name' => 'test'],
            ],
            [
                'id' => $child,
                'parentId' => $id,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 12, 'net' => 11, 'linked' => false]],
            ],
        ];

        $this->repository->upsert($data, Context::createDefaultContext());

        $products = $this->repository->search(new Criteria([$id, $child]), Context::createDefaultContext());
        static::assertTrue($products->has($id));
        static::assertTrue($products->has($child));

        $raw = $this->connection->fetchAll('SELECT * FROM product');
        static::assertCount(2, $raw);

        $name = $this->connection->fetchColumn('SELECT name FROM product_translation WHERE product_id = :id', ['id' => Uuid::fromHexToBytes($child)]);
        static::assertFalse($name);

        $data = [
            [
                'id' => $child,
                'parentId' => null,
            ],
        ];

        $e = null;
        try {
            $this->repository->upsert($data, Context::createDefaultContext());
        } catch (\Exception $e) {
        }
        static::assertInstanceOf(WriteException::class, $e);

        /* @var WriteException $e */
        static::assertArrayHasKey('/taxId', $e->toArray());
        static::assertArrayHasKey('/manufacturerId', $e->toArray());
        static::assertArrayHasKey('/translations', $e->toArray(), print_r($e->toArray(), true));

        $data = [
            [
                'id' => $child,
                'parentId' => null,
                'name' => 'Child transformed to parent',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 13, 'net' => 12, 'linked' => false]],
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'manufacturer' => ['name' => 'test3'],
            ],
        ];

        $this->repository->upsert($data, Context::createDefaultContext());

        /** @var array $raw */
        $raw = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', [
            'id' => Uuid::fromHexToBytes($child),
        ]);

        static::assertNull($raw['parent_id']);

        $products = $this->repository->search(new Criteria([$child]), Context::createDefaultContext());
        $product = $products->get($child);

        /* @var ProductEntity $product */
        static::assertEquals('Child transformed to parent', $product->getName());
        static::assertEquals(13, $product->getCurrencyPrice(Defaults::CURRENCY)->getGross());
        static::assertEquals('test3', $product->getManufacturer()->getName());
        static::assertEquals(15, $product->getTax()->getTaxRate());
    }

    public function testVariantInheritanceWithTax(): void
    {
        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $parentTax = Uuid::randomHex();
        $greenTax = Uuid::randomHex();

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'price' => [
                    ['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => true],
                ],
                'manufacturer' => ['name' => 'test'],
                'name' => 'parent',
                'tax' => ['id' => $parentTax, 'taxRate' => 13, 'name' => 'green'],
            ],

            //price should be inherited
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'parentId' => $parentId,
            ],

            //name should be inherited
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'parentId' => $parentId,
                'tax' => [
                    'id' => $greenTax,
                    'taxRate' => 13,
                    'name' => 'green',
                ],
            ],
        ];

        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);

        $this->repository->create($products, $context);

        $criteria = new Criteria([$redId, $greenId]);
        $criteria->addAssociation('tax');
        $products = $this->repository->search($criteria, $context);

        $criteria = new Criteria([$parentId]);
        $criteria->addAssociation('tax');
        $context->setConsiderInheritance(false);
        $parents = $this->repository->search($criteria, $context);

        static::assertTrue($parents->has($parentId));
        static::assertTrue($products->has($redId));
        static::assertTrue($products->has($greenId));

        /** @var ProductEntity $parent */
        $parent = $parents->get($parentId);

        /** @var ProductEntity $red */
        $red = $products->get($redId);

        /** @var ProductEntity $green */
        $green = $products->get($greenId);

        static::assertEquals($parentTax, $parent->getTax()->getId());
        static::assertEquals($parentTax, $red->getTax()->getId());
        static::assertEquals($greenTax, $green->getTax()->getId());

        static::assertEquals($parentTax, $parent->getTaxId());
        static::assertEquals($parentTax, $red->getTaxId());
        static::assertEquals($parentTax, $red->getTaxId());
        static::assertEquals($greenTax, $green->getTaxId());

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromHexToBytes($parentId)]);

        static::assertEquals(
            [
                'c' . Defaults::CURRENCY => ['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => true],
            ],
            json_decode($row['price'], true)
        );
        static::assertEquals($parentTax, Uuid::fromBytesToHex($row['tax_id']));

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromHexToBytes($redId)]);
        static::assertNull($row['price']);
        static::assertNull($row['tax_id']);

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromHexToBytes($greenId)]);
        static::assertNull($row['price']);
        static::assertEquals($greenTax, Uuid::fromBytesToHex($row['tax_id']));

        $criteria = new Criteria([$redId, $greenId]);
        $criteria->addAssociation('tax');
        $context->setConsiderInheritance(false);
        $products = $this->repository->search($criteria, $context);

        /** @var ProductEntity $red */
        $red = $products->get($redId);
        static::assertNull($red->getTax());
    }

    public function testWriteProductWithSameTaxes(): void
    {
        $tax = ['id' => Uuid::randomHex(), 'taxRate' => 19, 'name' => 'test'];
        $price = [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]];
        $data = [
            ['productNumber' => Uuid::randomHex(), 'name' => 'test', 'stock' => 10, 'tax' => $tax, 'price' => $price, 'manufacturer' => ['name' => 'test']],
            ['productNumber' => Uuid::randomHex(), 'name' => 'test', 'stock' => 10, 'tax' => $tax, 'price' => $price, 'manufacturer' => ['name' => 'test']],
            ['productNumber' => Uuid::randomHex(), 'name' => 'test', 'stock' => 10, 'tax' => $tax, 'price' => $price, 'manufacturer' => ['name' => 'test']],
            ['productNumber' => Uuid::randomHex(), 'name' => 'test', 'stock' => 10, 'tax' => $tax, 'price' => $price, 'manufacturer' => ['name' => 'test']],
            ['productNumber' => Uuid::randomHex(), 'name' => 'test', 'stock' => 10, 'tax' => $tax, 'price' => $price, 'manufacturer' => ['name' => 'test']],
        ];

        $taxes = $this->repository->create($data, Context::createDefaultContext())->getEventByDefinition(TaxDefinition::class);
        static::assertInstanceOf(EntityWrittenEvent::class, $taxes);
        static::assertCount(1, array_unique($taxes->getIds()));
    }

    public function testVariantInheritanceWithMedia(): void
    {
        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $parentMedia = Uuid::randomHex();
        $greenMedia = Uuid::randomHex();

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'name' => 'T-shirt',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'stock' => 10,
                'media' => [
                    [
                        'id' => $parentMedia,
                        'media' => [
                            'id' => $parentMedia,
                            'name' => 'test file',
                        ],
                    ],
                ],
            ],
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'parentId' => $parentId,
                'name' => 'red',
                'stock' => 10,
            ],
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'parentId' => $parentId,
                'stock' => 10,
                'name' => 'green',
                'media' => [
                    [
                        'id' => $greenMedia,
                        'media' => [
                            'id' => $greenMedia,
                            'name' => 'test file',
                        ],
                    ],
                ],
            ],
        ];

        $this->repository->create($products, Context::createDefaultContext());

        $criteria = new Criteria([$redId, $greenId]);
        $criteria->addAssociation('media');

        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);

        $products = $this->repository->search($criteria, $context);

        $criteria = new Criteria([$parentId]);
        $criteria->addAssociation('media');

        $parents = $this->repository->search($criteria, Context::createDefaultContext());

        static::assertTrue($parents->has($parentId));
        static::assertTrue($products->has($redId));
        static::assertTrue($products->has($greenId));

        /** @var ProductEntity $parent */
        $parent = $parents->get($parentId);

        /** @var ProductEntity $green */
        $green = $products->get($greenId);

        /** @var ProductEntity $red */
        $red = $products->get($redId);

        static::assertCount(1, $parent->getMedia());
        static::assertTrue($parent->getMedia()->has($parentMedia));

        static::assertCount(1, $green->getMedia());
        static::assertTrue($green->getMedia()->has($greenMedia));

        static::assertCount(1, $red->getMedia());
        static::assertTrue($red->getMedia()->has($parentMedia));

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product_media WHERE product_id = :id', ['id' => Uuid::fromHexToBytes($parentId)]);
        static::assertEquals($parentMedia, Uuid::fromBytesToHex($row['media_id']));

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product_media WHERE product_id = :id', ['id' => Uuid::fromHexToBytes($redId)]);
        static::assertEmpty($row['media_id']);

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product_media WHERE product_id = :id', ['id' => Uuid::fromHexToBytes($greenId)]);
        static::assertEquals($greenMedia, Uuid::fromBytesToHex($row['media_id']));
    }

    public function testVariantInheritanceWithCategories(): void
    {
        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $parentCategory = Uuid::randomHex();
        $greenCategory = Uuid::randomHex();

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'T-shirt',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'manufacturer' => ['name' => 'test'],
                'categories' => [
                    ['id' => $parentCategory, 'name' => 'parent'],
                ],
            ],
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'parentId' => $parentId,
                'name' => 'red',
            ],
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'parentId' => $parentId,
                'name' => 'green',
                'categories' => [
                    ['id' => $greenCategory, 'name' => 'green'],
                ],
            ],
        ];

        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);

        $this->repository->create($products, $context);

        $criteria = new Criteria([$redId, $greenId]);
        $criteria->addAssociation('categories');
        $products = $this->repository->search($criteria, $context);

        $criteria = new Criteria([$parentId]);
        $criteria->addAssociation('categories');
        $parents = $this->repository->search($criteria, $context);

        static::assertTrue($parents->has($parentId));
        static::assertTrue($products->has($redId));
        static::assertTrue($products->has($greenId));

        /** @var ProductEntity $parent */
        $parent = $parents->get($parentId);

        /** @var ProductEntity $green */
        $green = $products->get($greenId);

        /** @var ProductEntity $red */
        $red = $products->get($redId);

        static::assertEquals([$parentCategory], array_values($parent->getCategories()->getIds()));
        static::assertEquals([$parentCategory], array_values($red->getCategories()->getIds()));
        static::assertEquals([$greenCategory], array_values($green->getCategories()->getIds()));

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromHexToBytes($parentId)]);
        static::assertContains($parentCategory, json_decode($row['category_tree'], true));
        static::assertEquals($parentId, Uuid::fromBytesToHex($row['categories']));

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromHexToBytes($redId)]);
        static::assertContains($parentCategory, json_decode($row['category_tree'], true));
        static::assertEquals($parentId, Uuid::fromBytesToHex($row['categories']));

        /** @var array $row */
        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromHexToBytes($greenId)]);
        static::assertContains($greenCategory, json_decode($row['category_tree'], true));
        static::assertEquals($greenId, Uuid::fromBytesToHex($row['categories']));
    }

    public function testSearchByInheritedName(): void
    {
        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $parentPrice = ['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false];
        $parentName = 'T-shirt';
        $greenPrice = ['currencyId' => Defaults::CURRENCY, 'gross' => 12, 'net' => 11, 'linked' => false];
        $redName = 'Red shirt';

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => $parentName,
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'price' => [$parentPrice],
            ],

            //price should be inherited
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => $redName,
                'parentId' => $parentId,
            ],

            //name should be inherited
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'price' => [$greenPrice],
                'parentId' => $parentId,
            ],
        ];

        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);

        $this->repository->create($products, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('product.name', $parentName));

        $products = $this->repository->search($criteria, $context);
        static::assertCount(2, $products);
        static::assertTrue($products->has($parentId));
        static::assertTrue($products->has($greenId));

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('product.name', $redName));

        $products = $this->repository->search($criteria, $context);
        static::assertCount(1, $products);
        static::assertTrue($products->has($redId));
    }

    public function testSearchByInheritedPrice(): void
    {
        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $parentPrice = ['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false];
        $parentName = 'T-shirt';
        $greenPrice = ['currencyId' => Defaults::CURRENCY, 'gross' => 12, 'net' => 11, 'linked' => false];
        $redName = 'Red shirt';

        $manufacturerId = Uuid::randomHex();

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'manufacturer' => ['name' => 'test', 'id' => $manufacturerId],
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'name' => $parentName,
                'price' => [$parentPrice],
            ],

            //price should be inherited
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => $redName,
                'parentId' => $parentId,
            ],

            //name should be inherited
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'price' => [$greenPrice],
                'parentId' => $parentId,
            ],
        ];

        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);
        $this->repository->create($products, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('product.price', $parentPrice['gross']));
        $criteria->addFilter(new EqualsFilter('product.manufacturerId', $manufacturerId));

        $products = $this->repository->search($criteria, $context);
        static::assertCount(2, $products);
        static::assertTrue($products->has($parentId));
        static::assertTrue($products->has($redId));

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('product.price', $greenPrice['gross']));

        $products = $this->repository->search($criteria, $context);
        static::assertCount(1, $products);
        static::assertTrue($products->has($greenId));
    }

    public function testSearchCategoriesWithProductsUseInheritance(): void
    {
        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $parentPrice = ['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false];
        $parentName = 'T-shirt';
        $greenPrice = ['currencyId' => Defaults::CURRENCY, 'gross' => 12, 'net' => 11, 'linked' => false];
        $redName = 'Red shirt';

        $categoryId = Uuid::randomHex();

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'name' => $parentName,
                'price' => [$parentPrice],
                'manufacturer' => ['name' => 'test'],
                'categories' => [
                    ['id' => $categoryId, 'name' => 'test'],
                ],
            ],

            //price should be inherited
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => $redName,
                'parentId' => $parentId,
            ],

            //name should be inherited
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'price' => [$greenPrice],
                'parentId' => $parentId,
            ],
        ];

        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);
        $this->repository->create($products, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('category.products.price', $greenPrice['gross']));

        $repository = $this->getContainer()->get('category.repository');
        $categories = $repository->searchIds($criteria, $context);

        static::assertEquals(1, $categories->getTotal());
        static::assertContains($categoryId, $categories->getIds());

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('category.products.price', $parentPrice['gross']));
        $criteria->addFilter(new EqualsFilter('category.products.parentId', null));

        $repository = $this->getContainer()->get('category.repository');
        $categories = $repository->searchIds($criteria, $context);

        static::assertEquals(1, $categories->getTotal());
        static::assertContains($categoryId, $categories->getIds());
    }

    public function testSearchProductsOverInheritedCategories(): void
    {
        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $redCategories = [
            ['id' => $redId, 'name' => 'Red category'],
        ];

        $parentCategories = [
            ['id' => $parentId, 'name' => 'Parent category'],
        ];

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'name' => 'Parent',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'categories' => $parentCategories,
            ],
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => 'Red',
                'parentId' => $parentId,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'categories' => $redCategories,
            ],

            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'parentId' => $parentId,
            ],
        ];

        $this->repository->upsert($products, $this->context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('category.products.name', 'Parent'));

        $repo = $this->getContainer()->get('category.repository');
        $result = $repo->search($criteria, $this->context);
        static::assertCount(1, $result);
        static::assertTrue($result->has($parentId));

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('category.products.name', 'Red'));
        $result = $repo->search($criteria, $this->context);
        static::assertCount(1, $result);
        static::assertTrue($result->has($redId));
    }

    public function testSearchManufacturersWithProductsUseInheritance(): void
    {
        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $parentPrice = ['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false];
        $parentName = 'T-shirt';
        $greenPrice = ['currencyId' => Defaults::CURRENCY, 'gross' => 12, 'net' => 11, 'linked' => false];
        $redName = 'Red shirt';

        $manufacturerId = Uuid::randomHex();
        $manufacturerId2 = Uuid::randomHex();

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'tax' => ['name' => 'test', 'taxRate' => 15],
                'name' => $parentName,
                'price' => [$parentPrice],
                'manufacturer' => [
                    'id' => $manufacturerId,
                    'name' => 'test',
                ],
            ],
            //price should be inherited
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'name' => $redName,
                'parentId' => $parentId,
                'manufacturer' => [
                    'id' => $manufacturerId2,
                    'name' => 'test',
                ],
            ],
            //manufacturer should be inherited
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 10,
                'price' => [$greenPrice],
                'parentId' => $parentId,
            ],
        ];

        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);

        $this->repository->create($products, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('product_manufacturer.products.price', $greenPrice['gross']));

        $result = $this->getContainer()->get('product_manufacturer.repository')->searchIds($criteria, $context);

        static::assertEquals(1, $result->getTotal());
        static::assertContains($manufacturerId, $result->getIds());
    }

    public function testWriteProductOverCategories(): void
    {
        $productId = Uuid::randomHex();
        $categoryId = Uuid::randomHex();

        $categories = [
            [
                'id' => $categoryId,
                'name' => 'Cat1',
                'products' => [
                    [
                        'id' => $productId,
                        'productNumber' => Uuid::randomHex(),
                        'stock' => 10,
                        'tax' => ['name' => 'test', 'taxRate' => 15],
                        'name' => 'test',
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                        'manufacturer' => ['name' => 'test'],
                    ],
                ],
            ],
        ];

        $repository = $this->getContainer()->get('category.repository');

        $repository->create($categories, Context::createDefaultContext());

        $products = $this->repository->search(new Criteria([$productId]), Context::createDefaultContext());

        static::assertCount(1, $products);
        static::assertTrue($products->has($productId));

        /** @var ProductEntity $product */
        $product = $products->get($productId);

        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertContains($categoryId, $product->getCategoryTree());
    }

    public function testWriteProductOverManufacturer(): void
    {
        $productId = Uuid::randomHex();
        $manufacturerId = Uuid::randomHex();

        $manufacturers = [
            [
                'id' => $manufacturerId,
                'name' => 'Manufacturer',
                'products' => [
                    [
                        'id' => $productId,
                        'productNumber' => Uuid::randomHex(),
                        'stock' => 10,
                        'name' => 'test',
                        'tax' => ['name' => 'test', 'taxRate' => 15],
                        'manufacturerId' => $manufacturerId,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                    ],
                ],
            ],
        ];

        $repository = $this->getContainer()->get('product_manufacturer.repository');

        $repository->create($manufacturers, Context::createDefaultContext());

        $products = $this->repository->search(new Criteria([$productId]), Context::createDefaultContext());

        static::assertCount(1, $products);
        static::assertTrue($products->has($productId));

        /** @var ProductEntity $product */
        $product = $products->get($productId);

        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertEquals($manufacturerId, $product->getManufacturerId());
    }

    public function testCreateAndAssignProductProperty(): void
    {
        $id = Uuid::randomHex();
        $redId = Uuid::randomHex();
        $blueId = Uuid::randomHex();
        $colorId = Uuid::randomHex();

        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'name' => 'test',
            'stock' => 10,
            'tax' => ['name' => 'test', 'taxRate' => 15],
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'manufacturer' => ['name' => 'test'],
            'properties' => [
                [
                    'id' => $redId,
                    'name' => 'red',
                    'group' => ['id' => $colorId, 'name' => 'color'],
                ],
                [
                    'id' => $blueId,
                    'name' => 'blue',
                    'groupId' => $colorId,
                ],
            ],
        ];

        $this->repository->create([$data], Context::createDefaultContext());

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('properties');
        /** @var ProductEntity $product */
        $product = $this->repository->search($criteria, Context::createDefaultContext())->get($id);

        $sheet = $product->getProperties();

        static::assertCount(2, $sheet);

        static::assertTrue($sheet->has($redId));
        static::assertTrue($sheet->has($blueId));

        $blue = $sheet->get($blueId);
        $red = $sheet->get($redId);

        static::assertEquals('red', $red->getName());
        static::assertEquals('blue', $blue->getName());

        static::assertEquals($colorId, $red->getGroupId());
        static::assertEquals($colorId, $blue->getGroupId());
    }

    public function testCreateAndAssignProductOption(): void
    {
        $id = Uuid::randomHex();
        $redId = Uuid::randomHex();
        $blueId = Uuid::randomHex();
        $colorId = Uuid::randomHex();

        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'name' => 'test',
            'tax' => ['name' => 'test', 'taxRate' => 15],
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'manufacturer' => ['name' => 'test'],
            'options' => [
                [
                    'id' => $redId,
                    'name' => 'red',
                    'group' => ['id' => $colorId, 'name' => $colorId],
                ],
                [
                    'id' => $blueId,
                    'name' => 'blue',
                    'groupId' => $colorId,
                ],
            ],
        ];

        $this->repository->create([$data], Context::createDefaultContext());

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('options');
        /** @var ProductEntity $product */
        $product = $this->repository->search($criteria, Context::createDefaultContext())->get($id);

        $sheet = $product->getOptions();

        static::assertCount(2, $sheet);

        static::assertTrue($sheet->has($redId));
        static::assertTrue($sheet->has($blueId));

        $blue = $sheet->get($blueId);
        $red = $sheet->get($redId);

        static::assertEquals('red', $red->getName());
        static::assertEquals('blue', $blue->getName());

        static::assertEquals($colorId, $red->getGroupId());
        static::assertEquals($colorId, $blue->getGroupId());
    }

    public function testCreateAndAssignProductConfigurator(): void
    {
        $id = Uuid::randomHex();
        $redId = Uuid::randomHex();
        $blueId = Uuid::randomHex();
        $colorId = Uuid::randomHex();

        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'name' => 'test',
            'stock' => 10,
            'tax' => ['name' => 'test', 'taxRate' => 15],
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'manufacturer' => ['name' => 'test'],
            'configuratorSettings' => [
                [
                    'id' => $redId,
                    'price' => ['currencyId' => Defaults::CURRENCY, 'gross' => 50, 'net' => 25, 'linked' => false],
                    'option' => [
                        'id' => $redId,
                        'name' => 'red',
                        'group' => ['id' => $colorId, 'name' => $colorId],
                    ],
                ],
                [
                    'id' => $blueId,
                    'price' => ['currencyId' => Defaults::CURRENCY, 'gross' => 100, 'net' => 90, 'linked' => false],
                    'option' => [
                        'id' => $blueId,
                        'name' => 'blue',
                        'groupId' => $colorId,
                    ],
                ],
            ],
        ];

        $this->repository->create([$data], Context::createDefaultContext());

        $criteria = new Criteria([$id]);
        $criteria->addAssociationPath('configuratorSettings.option');
        /** @var ProductEntity $product */
        $product = $this->repository->search($criteria, Context::createDefaultContext())->get($id);

        $configuratorSettings = $product->getConfiguratorSettings();

        static::assertCount(2, $configuratorSettings);

        static::assertTrue($configuratorSettings->has($redId));
        static::assertTrue($configuratorSettings->has($blueId));

        $blue = $configuratorSettings->get($blueId);
        $red = $configuratorSettings->get($redId);

        static::assertEquals(['currencyId' => Defaults::CURRENCY, 'gross' => 50, 'net' => 25, 'linked' => false], $red->getPrice());
        static::assertEquals(['currencyId' => Defaults::CURRENCY, 'gross' => 100, 'net' => 90, 'linked' => false], $blue->getPrice());

        static::assertEquals('red', $red->getOption()->getName());
        static::assertEquals('blue', $blue->getOption()->getName());

        static::assertEquals($colorId, $red->getOption()->getGroupId());
        static::assertEquals($colorId, $blue->getOption()->getGroupId());
    }

    public function testListingPriceWithoutVariants(): void
    {
        $ruleA = Uuid::randomHex();
        $ruleB = Uuid::randomHex();

        $this->getContainer()->get('rule.repository')->create([
            ['id' => $ruleA, 'name' => 'test', 'priority' => 1],
            ['id' => $ruleB, 'name' => 'test', 'priority' => 2],
        ], Context::createDefaultContext());

        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'name' => 'price test',
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false],
            ],
            'manufacturer' => ['name' => 'test'],
            'tax' => ['name' => 'test', 'taxRate' => 15],
            'prices' => [
                [
                    'quantityStart' => 1,
                    'quantityEnd' => 20,
                    'ruleId' => $ruleA,
                    'price' => [
                        ['currencyId' => Defaults::CURRENCY, 'gross' => 100, 'net' => 100, 'linked' => false],
                    ],
                ],
                [
                    'quantityStart' => 21,
                    'ruleId' => $ruleA,
                    'price' => [
                        ['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 50, 'linked' => false],
                    ],
                ],
                [
                    'quantityStart' => 1,
                    'ruleId' => $ruleB,
                    'price' => [
                        ['currencyId' => Defaults::CURRENCY, 'gross' => 50, 'net' => 50, 'linked' => false],
                    ],
                ],
            ],
        ];

        $this->repository->create([$data], Context::createDefaultContext());
        $products = $this->repository->search(new Criteria([$id]), Context::createDefaultContext());
        static::assertTrue($products->has($id));

        /** @var ProductEntity $product */
        $product = $products->get($id);

        $price = $product
            ->getListingPrices()
            ->filterByRuleId($ruleA)
            ->filterByCurrencyId(Defaults::CURRENCY);

        static::assertCount(1, $price);
        $price = $price->first();

        /** @var ListingPrice $price */
        static::assertEquals(10, $price->getFrom()->getGross());
    }

    public function testModifyProductPriceMatrix(): void
    {
        $ruleA = Uuid::randomHex();
        $ruleB = Uuid::randomHex();

        $this->getContainer()->get('rule.repository')->create([
            ['id' => $ruleA, 'name' => 'test', 'priority' => 1],
            ['id' => $ruleB, 'name' => 'test', 'priority' => 2],
        ], Context::createDefaultContext());

        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'name' => 'price test',
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false]],
            'manufacturer' => ['name' => 'test'],
            'tax' => ['name' => 'test', 'taxRate' => 15],
            'prices' => [
                [
                    'id' => $id,
                    'quantityStart' => 1,
                    'ruleId' => $ruleA,
                    'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 100, 'net' => 100, 'linked' => false]],
                ],
            ],
        ];

        $context = Context::createDefaultContext();
        $this->repository->create([$data], $context);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('prices');

        $products = $this->repository->search($criteria, $context);
        static::assertTrue($products->has($id));

        /** @var ProductEntity $product */
        $product = $products->get($id);

        static::assertCount(1, $product->getPrices());

        /** @var ProductPriceEntity $price */
        $price = $product->getPrices()->first();
        static::assertEquals($ruleA, $price->getRuleId());

        $data = [
            'id' => $id,
            'prices' => [
                //update existing rule with new price and quantity end to add another graduation
                [
                    'id' => $id,
                    'quantityEnd' => 20,
                    'price' => [
                        ['currencyId' => Defaults::CURRENCY, 'gross' => 5000, 'net' => 4000, 'linked' => false],
                    ],
                ],

                //add new graduation to existing rule
                [
                    'quantityStart' => 21,
                    'ruleId' => $ruleA,
                    'price' => [
                        ['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 50, 'linked' => false],
                    ],
                ],
            ],
        ];

        $this->repository->upsert([$data], $context);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('prices');

        $products = $this->repository->search($criteria, $context);
        static::assertTrue($products->has($id));

        /** @var ProductEntity $product */
        $product = $products->get($id);

        static::assertCount(2, $product->getPrices());

        /** @var ProductPriceEntity $price */
        $price = $product->getPrices()->get($id);

        static::assertEquals($ruleA, $price->getRuleId());
        static::assertInstanceOf(PriceCollection::class, $price->getPrice());

        static::assertEquals(
            new Price(Defaults::CURRENCY, 4000, 5000, false),
            $price->getPrice()->getCurrencyPrice(Defaults::CURRENCY)
        );

        static::assertEquals(1, $price->getQuantityStart());
        static::assertEquals(20, $price->getQuantityEnd());

        $id3 = Uuid::randomHex();

        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'prices' => [
                [
                    'id' => $id3,
                    'currencyId' => Defaults::CURRENCY,
                    'quantityStart' => 1,
                    'ruleId' => $ruleB,
                    'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 50, 'net' => 50, 'linked' => false]],
                ],
            ],
        ];

        $this->repository->upsert([$data], $context);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('prices');

        $products = $this->repository->search($criteria, $context);
        static::assertTrue($products->has($id));

        /** @var ProductEntity $product */
        $product = $products->get($id);

        static::assertCount(3, $product->getPrices());

        /** @var ProductPriceEntity $price */
        $price = $product->getPrices()->get($id3);
        static::assertEquals($ruleB, $price->getRuleId());

        static::assertEquals(
            new Price(Defaults::CURRENCY, 50, 50, false),
            $price->getPrice()->getCurrencyPrice(Defaults::CURRENCY)
        );

        static::assertEquals(1, $price->getQuantityStart());
        static::assertNull($price->getQuantityEnd());
    }

    public function testPaginatedAssociationWithBlacklist(): void
    {
        $manufacturerId = Uuid::randomHex();
        $ruleId = Uuid::randomHex();
        $ruleId2 = Uuid::randomHex();

        $default = [
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'tax' => ['name' => 'test', 'taxRate' => 15, 'id' => $manufacturerId],
            'name' => 'test product',
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'manufacturer' => ['name' => 'test', 'id' => $manufacturerId],
        ];

        $withRules = array_merge($default, ['blacklistIds' => [$ruleId], 'productNumber' => Uuid::randomHex()]);

        $withRules2 = array_merge($default, ['blacklistIds' => [$ruleId], 'productNumber' => Uuid::randomHex()]);

        $default2 = array_merge($default, ['productNumber' => Uuid::randomHex()]);

        $products = [
            $default,
            $withRules,
            $withRules2,
            $default2,
        ];

        $this->repository->create($products, Context::createDefaultContext());

        $criteria = new Criteria([$manufacturerId]);
        $criteria->addAssociation('products', new PaginationCriteria(4));

        $repo = $this->getContainer()->get('product_manufacturer.repository');

        $context = $this->createContext();
        /** @var ProductManufacturerEntity $manufacturer */
        $manufacturer = $repo->search($criteria, $context)->get($manufacturerId);

        //test if all products can be read if context contains no rules
        static::assertInstanceOf(ProductManufacturerEntity::class, $manufacturer);

        static::assertInstanceOf(ProductCollection::class, $manufacturer->getProducts());
        static::assertCount(4, $manufacturer->getProducts());

        //test if two of four products can be read if context contains no rule
        $criteria = new Criteria([$manufacturerId]);
        $criteria->addAssociation('products', new PaginationCriteria(2));

        $repo = $this->getContainer()->get('product_manufacturer.repository');

        $context = $this->createContext();
        /** @var ProductManufacturerEntity $manufacturer */
        $manufacturer = $repo->search($criteria, $context)->get($manufacturerId);

        static::assertInstanceOf(ProductManufacturerEntity::class, $manufacturer);
        static::assertInstanceOf(ProductCollection::class, $manufacturer->getProducts());
        static::assertCount(2, $manufacturer->getProducts());

        //test if two of four products can be read if context contains no rule
        $criteria = new Criteria([$manufacturerId]);
        $criteria->addAssociation('products', new PaginationCriteria(4));

        $repo = $this->getContainer()->get('product_manufacturer.repository');

        $context = $this->createContext([$ruleId, $ruleId2]);
        /** @var ProductManufacturerEntity $manufacturer */
        $manufacturer = $repo->search($criteria, $context)->get($manufacturerId);

        static::assertInstanceOf(ProductManufacturerEntity::class, $manufacturer);
        static::assertInstanceOf(ProductCollection::class, $manufacturer->getProducts());
        static::assertCount(2, $manufacturer->getProducts());
    }

    public function testWriteProductCategoriesWithoutId(): void
    {
        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'name' => 'product',
            'stock' => 10,
            'ean' => 'test',
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false]],
            'manufacturer' => ['name' => 'manufacturer'],
            'tax' => ['name' => 'tax', 'taxRate' => 15],
            'categories' => [
                ['name' => 'category_name'],
            ],
        ];
        $this->connection->executeUpdate('DELETE FROM sales_channel');
        $this->connection->executeUpdate('DELETE FROM category');

        $this->repository->create([$data], Context::createDefaultContext());

        $count = $this->connection->fetchAll('SELECT * FROM category');

        static::assertCount(1, $count, print_r($count, true));
    }

    private function formatPrice(
        float $gross,
        string $currencyId,
        string $ruleId,
        int $quantityStart,
        ?int $quantityEnd,
        ?float $net = null,
        ?string $id = null
    ) {
        $id = $id ?? Uuid::randomHex();

        return [
            'id' => $id,
            'quantityStart' => $quantityStart,
            'quantityEnd' => $quantityEnd,
            'ruleId' => $ruleId,
            'price' => [
                [
                    'currencyId' => $currencyId,
                    'gross' => $gross,
                    'net' => $net ?? $gross / 1.19,
                    'linked' => false,
                ],
            ],
        ];
    }

    private function createContext(array $ruleIds = []): Context
    {
        return new Context(new SystemSource(), $ruleIds);
    }
}

class CallableClass
{
    public function __invoke()
    {
    }
}
