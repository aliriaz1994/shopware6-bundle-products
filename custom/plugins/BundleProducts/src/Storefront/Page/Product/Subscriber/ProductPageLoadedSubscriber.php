<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Storefront\Page\Product\Subscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use DigiPercep\BundleProducts\Service\BundleService;
use DigiPercep\BundleProducts\Service\CustomFieldBundleService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;

class ProductPageLoadedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BundleService $bundleService,
        private readonly CustomFieldBundleService $customFieldBundleService,
        private readonly EntityRepository $bundleRepository,
        private readonly SalesChannelRepository $salesChannelProductRepository,
        private readonly EntityRepository $productRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $product = $page->getProduct();
        $salesChannelContext = $event->getSalesChannelContext();

        // Load bundle assignments with details (returns ArrayStruct)
        $bundleAssignments = $this->customFieldBundleService->getBundleAssignmentsWithDetails(
            $product->getId(),
            $salesChannelContext->getContext()
        );

        // Extract bundle data from assignments and load full bundle details with products
        $assignmentsData = $bundleAssignments->getVars();
        $bundlesForDisplay = [];

        foreach ($assignmentsData as $slotName => $assignment) {
            if ($assignment && isset($assignment['bundle']) && $assignment['bundle']) {
                $bundleId = $assignment['bundle']['id'];

                // Load full bundle details with associated products
                $fullBundle = $this->loadBundleWithProducts($bundleId, $salesChannelContext);

                if ($fullBundle) {
                    // Replace the basic bundle data with full details
                    $assignment['bundle'] = [
                        'id' => $fullBundle->getId(),
                        'name' => $fullBundle->getName(),
                        'discount' => $fullBundle->getDiscount(),
                        'discountType' => $fullBundle->getDiscountType(),
                        'active' => $fullBundle->getActive(),
                        'bundleProducts' => $fullBundle->getBundleProducts()
                    ];

                    // Update the assignment in the original data
                    $assignmentsData[$slotName] = $assignment;

                    $bundlesForDisplay[] = $assignment['bundle'];
                }
            }
        }

        // Create new ArrayStruct with updated data
        $updatedBundleAssignments = new ArrayStruct($assignmentsData);

        // Add extensions to page
        $page->addExtension('bundleAssignments', $updatedBundleAssignments);
        $page->addExtension('bundlesForDisplay', new ArrayStruct($bundlesForDisplay));
    }

    private function loadBundleWithProducts(string $bundleId, $salesChannelContext)
    {
        // First, load the bundle from admin repository
        $criteria = new Criteria([$bundleId]);
        $criteria->addAssociation('bundleProducts');
        $criteria->addFilter(new EqualsFilter('active', true));

        $bundle = $this->bundleRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$bundle || !$bundle->getBundleProducts()) {
            return null;
        }

        // Extract product IDs from bundle products
        $productIds = [];
        foreach ($bundle->getBundleProducts() as $bundleProduct) {
            if ($bundleProduct->getProductId()) {
                $productIds[] = $bundleProduct->getProductId();
            }
        }

        if (empty($productIds)) {
            return $bundle;
        }

        // Load products with calculated prices using sales channel repository
        $productCriteria = new Criteria($productIds);
        $productCriteria->addFilter(new ProductAvailableFilter($salesChannelContext->getSalesChannel()->getId()));
        $productCriteria->addAssociation('cover.media');
        $productCriteria->addAssociation('media');
        $productCriteria->addAssociation('options.group');

        // Load products from sales channel to get calculated prices
        $products = $this->salesChannelProductRepository->search(
            $productCriteria,
            $salesChannelContext
        );

        // Create a map of products by ID for easy lookup
        $productsById = [];
        $parentIds = [];

        foreach ($products->getEntities() as $product) {
            $productsById[$product->getId()] = $product;

            // Collect parent IDs for variants
            if ($product->getParentId()) {
                $parentIds[] = $product->getParentId();
            }
        }

        // Load parent products separately using the admin repository
        // (because sales channel repository blocks parent associations)
        $parentProducts = [];
        if (!empty($parentIds)) {
            $parentCriteria = new Criteria(array_unique($parentIds));
            $parentCriteria->addAssociation('cover.media');
            $parentCriteria->addAssociation('media');

            $parentResult = $this->productRepository->search($parentCriteria, $salesChannelContext->getContext());
            foreach ($parentResult->getEntities() as $parent) {
                $parentProducts[$parent->getId()] = $parent;
            }
        }

        // Update bundle products with the loaded product data and parent information
        foreach ($bundle->getBundleProducts() as $bundleProduct) {
            $productId = $bundleProduct->getProductId();
            if (isset($productsById[$productId])) {
                $product = $productsById[$productId];

                // If this is a variant and we have the parent, add it as an extension
                if ($product->getParentId() && isset($parentProducts[$product->getParentId()])) {
                    $product->addExtension('parentProduct', $parentProducts[$product->getParentId()]);
                }

                $bundleProduct->setProduct($product);
            }
        }

        return $bundle;
    }
}
