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
        private BundleService $bundleService,
        private CustomFieldBundleService $customFieldBundleService,
        private EntityRepository $bundleRepository,
        private SalesChannelRepository $salesChannelProductRepository
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

        // Keep the original bundles extension for backward compatibility
        $bundles = $this->bundleService->getBundlesForProduct(
            $product->getId(),
            $salesChannelContext
        );
        $page->addExtension('bundles', $bundles);
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

        // Load products from sales channel to get calculated prices
        $products = $this->salesChannelProductRepository->search(
            $productCriteria,
            $salesChannelContext
        );

        // Create a map of products by ID for easy lookup
        $productsById = [];
        foreach ($products->getEntities() as $product) {
            $productsById[$product->getId()] = $product;
        }

        // Update bundle products with the loaded product data (including calculated prices)
        foreach ($bundle->getBundleProducts() as $bundleProduct) {
            $productId = $bundleProduct->getProductId();
            if (isset($productsById[$productId])) {
                $bundleProduct->setProduct($productsById[$productId]);
            }
        }

        return $bundle;
    }
}
