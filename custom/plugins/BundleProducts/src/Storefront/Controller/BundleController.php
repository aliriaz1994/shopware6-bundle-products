<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Storefront\Controller;

use DigiPercep\BundleProducts\Core\Content\Bundle\BundleEntity;
use DigiPercep\BundleProducts\Service\BundleService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class BundleController extends StorefrontController
{
    private const string BUNDLE_LINE_ITEM_PREFIX = 'bundle-';
    private const int SUCCESS_STATUS_CODE = 200;
    private const int INTERNAL_ERROR_STATUS_CODE = 500;
    private const int DEFAULT_QUANTITY = 1;
    private const int MAX_QUANTITY = 999;

    public function __construct(
        private readonly BundleService $bundleService,
        private readonly CartService $cartService,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $productRepository
    ) {
    }

    #[Route(
        path: '/bundle/add-to-cart',
        name: 'frontend.bundle.add-to-cart',
        options: ['seo' => false],
        defaults: [
            '_routeScope' => ['storefront'],
            'XmlHttpRequest' => true,
            'csrf_protected' => false
        ],
        methods: ['POST']
    )]
    public function addToCart(Request $request, SalesChannelContext $context): Response
    {
        try {
            $requestData = $this->parseRequestData($request);
            $bundleId = $this->validateBundleId($requestData);
            $currentProductId = $this->validateCurrentProductId($requestData);
            $quantity = $this->validateAndParseQuantity($requestData);
            $replaceExisting = $this->shouldReplaceExisting($requestData);

            $bundle = $this->loadAndValidateBundle($bundleId, $context);
            $bundlePricing = $this->calculateBundlePricing($bundle, $currentProductId, $context);

            $cart = $this->cartService->getCart($context->getToken(), $context);

            // Handle existing bundle items based on request
            if ($replaceExisting) {
                $this->removeExistingBundleItems($cart, $bundleId, $context);
                // Create new bundle item after removing existing ones
                $bundleLineItem = $this->createBundleLineItem($bundleId, $currentProductId, $bundlePricing, $quantity, $context);
                $cart->add($bundleLineItem);
                $message = sprintf('Bundle - %s (x%d) added to your shopping cart', $bundle->getName(), $quantity);
            } else {
                // Check if we can add more quantity to existing item
                $existingItem = $this->findExistingBundleItem($cart, $bundleId, $currentProductId);
                if ($existingItem) {
                    $newQuantity = $existingItem->getQuantity() + $quantity;
                    if ($newQuantity > self::MAX_QUANTITY) {
                        throw new \InvalidArgumentException(
                            sprintf('Cannot add %d items. Maximum quantity per bundle is %d', $quantity, self::MAX_QUANTITY)
                        );
                    }

                    // Update existing item quantity
                    $this->updateBundleItemQuantity($cart, $existingItem, $newQuantity, $bundlePricing, $context);
                    $message = sprintf('Bundle - %s quantity updated to %d in your shopping cart', $bundle->getName(), $newQuantity);
                } else {
                    // Add new bundle item
                    $bundleLineItem = $this->createBundleLineItem($bundleId, $currentProductId, $bundlePricing, $quantity, $context);
                    $cart->add($bundleLineItem);
                    $message = sprintf('Bundle - %s (x%d) added to your shopping cart', $bundle->getName(), $quantity);
                }
            }

            $cart->markModified();
            $this->recalculateCart($cart, $context);

            $this->addFlash(self::SUCCESS, $message);
            return $this->createActionResponse($request);

        } catch (\InvalidArgumentException $e) {
            $this->addFlash(self::DANGER, $e->getMessage());
            return $this->createActionResponse($request);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error adding bundle to cart', [
                'exception' => $e->getMessage(),
                'bundleId' => $bundleId ?? 'unknown',
                'quantity' => $quantity ?? 'unknown'
            ]);
            $this->addFlash(self::DANGER, 'An unexpected error occurred while adding the bundle to cart');
            return $this->createActionResponse($request);
        }
    }

    #[Route(
        path: '/bundle/update-quantity',
        name: 'frontend.bundle.update-quantity',
        options: ['seo' => false],
        defaults: [
            '_routeScope' => ['storefront'],
            'XmlHttpRequest' => true,
            'csrf_protected' => false
        ],
        methods: ['POST', 'PUT']
    )]
    public function updateQuantity(Request $request, SalesChannelContext $context): Response
    {
        try {
            $requestData = $this->parseRequestData($request);
            $lineItemId = $requestData['line-item-id'] ?? null;
            $newQuantity = $this->validateAndParseQuantity($requestData, 'quantity');

            if (!$lineItemId) {
                throw new \InvalidArgumentException('Line item ID is required');
            }

            $cart = $this->cartService->getCart($context->getToken(), $context);
            $lineItem = $cart->getLineItems()->get($lineItemId);

            if (!$lineItem) {
                throw new \InvalidArgumentException('Bundle item not found in cart');
            }

            if (!$lineItem->getPayloadValue('isBundle')) {
                throw new \InvalidArgumentException('Item is not a bundle');
            }

            $bundleId = $lineItem->getPayloadValue('bundleId');
            $currentProductId = $lineItem->getPayloadValue('mainProductId');

            // Recalculate pricing for new quantity
            $bundle = $this->loadAndValidateBundle($bundleId, $context);
            $bundlePricing = $this->calculateBundlePricing($bundle, $currentProductId, $context);

            $this->updateBundleItemQuantity($cart, $lineItem, $newQuantity, $bundlePricing, $context);
            $cart = $this->cartService->recalculate($cart, $context);

            if (!$this->traceErrors($cart)) {
                $this->addFlash(self::SUCCESS, sprintf('Bundle quantity updated to %d', $newQuantity));
            }

            return $this->createActionResponse($request);

        } catch (\InvalidArgumentException $e) {
            $this->addFlash(self::DANGER, $e->getMessage());
            return $this->createActionResponse($request);
        } catch (\Exception $e) {
            $this->logger->error('Error updating bundle quantity', [
                'exception' => $e->getMessage(),
                'lineItemId' => $lineItemId ?? 'unknown'
            ]);
            $this->addFlash(self::DANGER, 'An error occurred while updating the bundle quantity');
            return $this->createActionResponse($request);
        }
    }

    /**
     * Update existing bundle item quantity
     */
    private function updateBundleItemQuantity(
        Cart $cart,
        LineItem $lineItem,
        int $newQuantity,
        array $bundlePricing,
        SalesChannelContext $context
    ): void {
        if ($newQuantity <= 0) {
            $this->cartService->remove($cart, $lineItem->getId(), $context);
            return;
        }

        if ($newQuantity > self::MAX_QUANTITY) {
            throw new \InvalidArgumentException(
                sprintf('Maximum quantity per bundle is %d', self::MAX_QUANTITY)
            );
        }

        $currentProductId = $lineItem->getPayloadValue('mainProductId');
        $taxRate = $this->getProductTaxRate($currentProductId, $context->getContext());

        // Recalculate prices for new quantity
        $bundleUnitPrice = $bundlePricing['finalPrice'];
        $bundleTotalGrossPrice = $bundleUnitPrice * $newQuantity;
        $bundleTaxAmount = $this->calculateTaxAmount($bundleTotalGrossPrice, $taxRate, $context);
        $bundleTotalNetPrice = $bundleTotalGrossPrice - $bundleTaxAmount;

        // Update tax structures
        $taxRules = new TaxRuleCollection([new TaxRule($taxRate)]);
        $calculatedTaxes = new CalculatedTaxCollection([
            new CalculatedTax($bundleTaxAmount, $taxRate, $bundleTotalGrossPrice)
        ]);

        // Update line item
        $lineItem->setQuantity($newQuantity);

        $calculatedPrice = new CalculatedPrice(
            $bundleTotalNetPrice,
            $bundleTotalGrossPrice,
            $calculatedTaxes,
            $taxRules,
            $newQuantity
        );

        $lineItem->setPrice($calculatedPrice);

        // Update price definition
        $priceDefinition = new QuantityPriceDefinition(
            $bundleUnitPrice,
            $taxRules,
            $newQuantity
        );
        $lineItem->setPriceDefinition($priceDefinition);

        // Update payload
        $payload = $lineItem->getPayload();
        $payload['quantity'] = $newQuantity;
        $payload['unitPrice'] = $bundleUnitPrice;
        $payload['totalPrice'] = $bundleTotalGrossPrice;
        $lineItem->setPayload($payload);

        $cart->markModified();
    }

    /**
     * Find existing bundle item in cart
     */
    private function findExistingBundleItem(Cart $cart, string $bundleId, string $currentProductId): ?LineItem
    {
        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getPayloadValue('bundleId') === $bundleId &&
                $lineItem->getPayloadValue('mainProductId') === $currentProductId) {
                return $lineItem;
            }
        }
        return null;
    }

    /**
     * Validate and parse quantity from request
     */
    private function validateAndParseQuantity(array $requestData, string $key = 'quantity'): int
    {
        $quantity = $requestData[$key] ?? self::DEFAULT_QUANTITY;

        if (is_string($quantity)) {
            $quantity = (int) $quantity;
        }

        if (!is_int($quantity) || $quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be a positive integer');
        }

        if ($quantity > self::MAX_QUANTITY) {
            throw new \InvalidArgumentException(
                sprintf('Maximum quantity per bundle is %d', self::MAX_QUANTITY)
            );
        }

        return $quantity;
    }

    /**
     * Check if existing items should be replaced
     */
    private function shouldReplaceExisting(array $requestData): bool
    {
        return ($requestData['replace-existing'] ?? false) === true ||
            ($requestData['replace-existing'] ?? false) === 'true' ||
            ($requestData['replace-existing'] ?? false) === '1';
    }

    #[Route(
        path: '/bundle/remove-from-cart',
        name: 'frontend.bundle.remove-from-cart',
        options: ['seo' => false],
        defaults: [
            '_routeScope' => ['storefront'],
            'XmlHttpRequest' => true,
            'csrf_protected' => false
        ],
        methods: ['POST', 'DELETE']
    )]
    public function removeFromCart(Request $request, SalesChannelContext $context): Response
    {
        try {
            $requestData = $this->parseRequestData($request);
            $bundleId = $requestData['bundle-id'] ?? null;
            $lineItemId = $requestData['line-item-id'] ?? null;

            if (!$bundleId && !$lineItemId) {
                throw new \InvalidArgumentException('Either bundle-id or line-item-id is required');
            }

            if ($bundleId && !Uuid::isValid($bundleId)) {
                throw new \InvalidArgumentException('Bundle ID must be a valid UUID');
            }

            $cart = $this->cartService->getCart($context->getToken(), $context);
            $removedItems = $bundleId
                ? $this->removeBundleItemsByBundleId($cart, $bundleId, $context)
                : $this->removeBundleItemByLineItemId($cart, $lineItemId, $context);

            if (empty($removedItems)) {
                throw new \InvalidArgumentException('No bundle items found to remove');
            }

            $cart = $this->cartService->recalculate($cart, $context);

            if (!$this->traceErrors($cart)) {
                $this->addFlash(self::SUCCESS, $this->trans('checkout.cartUpdateSuccess'));
            }

            return $this->createActionResponse($request);

        } catch (\InvalidArgumentException $e) {
            $this->addFlash(self::DANGER, $e->getMessage());
            return $this->createActionResponse($request);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error removing bundle from cart', [
                'exception' => $e->getMessage(),
                'bundleId' => $bundleId ?? 'unknown',
                'lineItemId' => $lineItemId ?? 'unknown'
            ]);
            $this->addFlash(self::DANGER, $this->trans('error.message-default'));
            return $this->createActionResponse($request);
        }
    }

    private function traceErrors(Cart $cart): bool
    {
        if ($cart->getErrors()->count() <= 0) {
            return false;
        }
        $this->addCartErrors($cart, fn ($error) => $error->isPersistent());
        return true;
    }

    #[Route(
        path: '/bundle/cart-info',
        name: 'frontend.bundle.cart-info',
        options: ['seo' => false],
        defaults: [
            '_routeScope' => ['storefront'],
            'XmlHttpRequest' => true,
            'csrf_protected' => false
        ],
        methods: ['GET', 'POST']
    )]
    public function getBundleCartInfo(SalesChannelContext $context): JsonResponse
    {
        try {
            $cart = $this->cartService->getCart($context->getToken(), $context);
            $bundleItems = $this->getBundleItems($cart);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'bundleItems' => $bundleItems,
                    'bundleCount' => count($bundleItems),
                    'cart' => [
                        'lineItemCount' => $cart->getLineItems()->count(),
                        'totalPrice' => $cart->getPrice()->getTotalPrice()
                    ]
                ]
            ], self::SUCCESS_STATUS_CODE);

        } catch (\Exception $e) {
            $this->logger->error('Error getting bundle cart info', ['exception' => $e->getMessage()]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Unable to retrieve bundle cart information',
                'error' => [
                    'code' => self::INTERNAL_ERROR_STATUS_CODE,
                    'timestamp' => date('c')
                ]
            ], self::INTERNAL_ERROR_STATUS_CODE);
        }
    }

    /**
     * Enhanced bundle line item creation with quantity support
     */
    private function createBundleLineItem(
        string $bundleId,
        string $currentProductId,
        array $bundlePricing,
        int $quantity,
        SalesChannelContext $context
    ): LineItem {
        $lineItemId = self::BUNDLE_LINE_ITEM_PREFIX . substr($bundleId, 0, 8) . '-' . uniqid();

        // Get tax rate and media data
        $taxRate = $this->getProductTaxRate($currentProductId, $context->getContext());
        $mediaInfo = $this->getProductWithMediaEntity($currentProductId, $context);
        $productData = $mediaInfo['productData'];

        // Calculate prices for the specified quantity
        $bundleUnitPrice = $bundlePricing['finalPrice'];
        $bundleTotalGrossPrice = $bundleUnitPrice * $quantity;
        $bundleTaxAmount = $this->calculateTaxAmount($bundleTotalGrossPrice, $taxRate, $context);
        $bundleTotalNetPrice = $bundleTotalGrossPrice - $bundleTaxAmount;

        // Create tax structures
        $taxRules = new TaxRuleCollection([new TaxRule($taxRate)]);
        $calculatedTaxes = new CalculatedTaxCollection([
            new CalculatedTax($bundleTaxAmount, $taxRate, $bundleTotalGrossPrice)
        ]);

        // Create bundle line item with quantity
        $bundleLineItem = new LineItem(
            $lineItemId,
            LineItem::CUSTOM_LINE_ITEM_TYPE,
            $currentProductId,
            $quantity  // Set the actual quantity
        );

        $bundleLineItem->setLabel($bundlePricing['currentProduct']['name']);
        $bundleLineItem->setGood(true);
        $bundleLineItem->setStackable(true);
        $bundleLineItem->setRemovable(true);

        // Set calculated price for total quantity
        $calculatedPrice = new CalculatedPrice(
            $bundleTotalNetPrice,
            $bundleTotalGrossPrice,
            $calculatedTaxes,
            $taxRules,
            $quantity
        );

        $bundleLineItem->setPrice($calculatedPrice);

        // Set price definition with unit price
        $priceDefinition = new QuantityPriceDefinition(
            $bundleUnitPrice,  // Unit price
            $taxRules,
            $quantity
        );
        $bundleLineItem->setPriceDefinition($priceDefinition);

        // Enhanced payload with quantity information
        $payload = $this->createBundlePayload($bundleId, $currentProductId, $bundlePricing, $productData);
        $payload['quantity'] = $quantity;
        $payload['unitPrice'] = $bundleUnitPrice;
        $payload['totalPrice'] = $bundleTotalGrossPrice;

        $bundleLineItem->setPayload($payload);

        return $bundleLineItem;
    }

    /**
     * Get tax rate from product entity
     */
    private function getProductTaxRate(string $productId, Context $context): float
    {
        try {
            $criteria = new Criteria([$productId]);
            $criteria->addAssociation('tax');

            $product = $this->productRepository->search($criteria, $context)->first();

            if (!$product || !$product->getTax()) {
                $this->logger->warning('No tax found for product, using default', ['productId' => $productId]);
                return 19.0; // Default fallback
            }

            $taxRate = $product->getTax()->getTaxRate();

            $this->logger->info('Found tax rate for product', [
                'productId' => $productId,
                'taxRate' => $taxRate,
                'taxName' => $product->getTax()->getName()
            ]);

            return $taxRate;

        } catch (\Exception $e) {
            $this->logger->error('Error getting product tax rate', [
                'productId' => $productId,
                'error' => $e->getMessage()
            ]);

            return 19.0; // Default fallback
        }
    }

    /**
     * Calculate tax amount based on sales channel tax calculation type
     */
    private function calculateTaxAmount(float $price, float $taxRate, SalesChannelContext $context): float
    {
        $taxCalculationType = $context->getSalesChannel()->getTaxCalculationType();

        // Check if customer is tax exempt
        $customer = $context->getCustomer();
        if ($customer && $customer->getGroup() && $customer->getGroup()->getDisplayGross() === false) {
            // B2B customer - price is net, need to add tax
            return $price * ($taxRate / 100);
        }

        // Default: price includes tax (gross price)
        if ($taxCalculationType === 'vertical') {
            // Vertical tax calculation: tax = gross - (gross / (1 + tax_rate))
            return $price - ($price / (1 + ($taxRate / 100)));
        } else {
            // Horizontal tax calculation: tax = gross - (gross / (1 + tax_rate))
            return $price - ($price / (1 + ($taxRate / 100)));
        }
    }

    /**
     * Get product media data AND return the actual MediaEntity object
     */
    private function getProductWithMediaEntity(string $productId, SalesChannelContext $context): array
    {
        try {
            $criteria = new Criteria([$productId]);
            $criteria->addAssociation('cover.media');
            $criteria->addAssociation('media.media');

            $product = $this->productRepository->search($criteria, $context->getContext())->first();

            if (!$product) {
                $this->logger->warning('Product not found for image data', ['productId' => $productId]);
                return [
                    'productData' => [],
                    'mediaEntity' => null
                ];
            }

            $mediaEntity = null; // This will hold the actual MediaEntity
            $imageData = [];

            // Get cover image (main product image)
            if ($product->getCover() && $product->getCover()->getMedia()) {
                $media = $product->getCover()->getMedia();
                $mediaEntity = $media; // Store the actual MediaEntity object

                $imageData['cover'] = [
                    'id' => $media->getId(),
                    'url' => $media->getUrl(),
                    'alt' => $media->getAlt() ?? $product->getName(),
                    'title' => $media->getTitle() ?? $product->getName(),
                    'mediaId' => $media->getId(),
                    'media' => [
                        'id' => $media->getId(),
                        'url' => $media->getUrl(),
                        'alt' => $media->getAlt(),
                        'title' => $media->getTitle(),
                        'fileName' => $media->getFileName(),
                        'fileExtension' => $media->getFileExtension(),
                        'fileSize' => $media->getFileSize(),
                        'mimeType' => $media->getMimeType(),
                        'thumbnails' => $media->getThumbnails() ? $media->getThumbnails()->getElements() : []
                    ]
                ];
            } else {
                $this->logger->info('No cover image found for product', ['productId' => $productId]);
            }

            $productData = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'productNumber' => $product->getProductNumber(),
                'images' => $imageData
            ];

            return [
                'productData' => $productData,
                'mediaEntity' => $mediaEntity
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error fetching product media data', [
                'productId' => $productId,
                'error' => $e->getMessage()
            ]);

            return [
                'productData' => [],
                'mediaEntity' => null
            ];
        }
    }

    private function createBundlePayload(string $bundleId, string $currentProductId, array $bundlePricing, array $productData = []): array
    {
        $payload = [
            'bundleId' => $bundleId,
            'isBundle' => true,
            'bundleLineItem' => true,
            'mainProductId' => $currentProductId,
            'mainProductName' => $bundlePricing['currentProduct']['name'],
            'isMainProduct' => true,
            'currentProduct' => $bundlePricing['currentProduct'],
            'originalPrice' => $bundlePricing['originalPrice'],
            'finalPrice' => $bundlePricing['finalPrice'],
            'savings' => $bundlePricing['savings'],
            'discountType' => $bundlePricing['discountType'],
            'discountValue' => $bundlePricing['discountValue'],
            'bundleProducts' => $bundlePricing['bundleProducts'],
            'allBundleProducts' => $bundlePricing['allProducts'],
            'totalProductCount' => $bundlePricing['productCount'],
            'bundleDescription' => $this->createBundleDescription(
                $bundlePricing['bundleProducts'],
                $bundlePricing['currentProduct']['name']
            ),
            // CRITICAL: Flags to prevent Shopware from overriding bundle price
            'customPrice' => true,
            'lockPrice' => true,
            'skipPriceRecalculation' => true,
        ];

        // Add comprehensive image data for template fallbacks
        if (!empty($productData) && isset($productData['images'])) {
            $payload['productData'] = $productData;

            // Add cover image data in multiple formats for template compatibility
            if (isset($productData['images']['cover'])) {
                $cover = $productData['images']['cover'];

                // Direct image URL for fallback
                $payload['imageUrl'] = $cover['url'];
                $payload['imageAlt'] = $cover['alt'];

                // Shopware-compatible cover data
                $payload['cover'] = $cover;
                $payload['coverMedia'] = $cover['media'] ?? null;

                // Delivery information with image (Shopware pattern)
                $payload['deliveryInformation'] = [
                    'image' => $cover
                ];
            }

            // Add media collection for compatibility
            if (isset($productData['images']['media'])) {
                $payload['media'] = $productData['images']['media'];
            }
        }

        return $payload;
    }

    protected function createActionResponse(Request $request): Response
    {
        $redirectTo = $request->get('redirectTo', 'frontend.checkout.cart.page');
        $redirectParameters = $request->get('redirectParameters', []);

        if (is_string($redirectParameters)) {
            $parsedParameters = [];
            parse_str($redirectParameters, $parsedParameters);
            $redirectParameters = $parsedParameters;
        }

        if (!is_array($redirectParameters)) {
            $redirectParameters = [];
        }

        if ($redirectTo === 'frontend.checkout.cart.page' ||
            $request->headers->get('referer') &&
            strpos($request->headers->get('referer'), '/checkout/cart') !== false) {
            return $this->redirectToRoute('frontend.checkout.cart.page', $redirectParameters);
        }

        if ($redirectTo === 'frontend.cart.offcanvas') {
            return $this->redirectToRoute('frontend.cart.offcanvas', $redirectParameters);
        }

        return $this->redirectToRoute($redirectTo, $redirectParameters);
    }

    private function parseRequestData(Request $request): array
    {
        $requestData = $request->request->all();
        if (empty($requestData) && $request->getContent()) {
            parse_str($request->getContent(), $requestData);
        }
        return $requestData;
    }

    private function validateBundleId(array $requestData): string
    {
        $bundleId = $requestData['bundle-id'] ?? null;
        if ($bundleId === null || is_string($bundleId) === false) {
            throw new \InvalidArgumentException('Bundle ID is required and must be a valid string');
        }
        if (Uuid::isValid($bundleId) === false) {
            throw new \InvalidArgumentException('Bundle ID must be a valid UUID');
        }
        return $bundleId;
    }

    private function validateCurrentProductId(array $requestData): string
    {
        $currentProductId = $requestData['bundle-product-id'] ?? null;
        if ($currentProductId === null || Uuid::isValid($currentProductId) === false) {
            throw new \InvalidArgumentException('Current product ID is required and must be valid');
        }
        return $currentProductId;
    }

    private function loadAndValidateBundle(string $bundleId, SalesChannelContext $context): BundleEntity
    {
        $bundle = $this->bundleService->getBundleById($bundleId, $context->getContext());
        if ($bundle === null) {
            throw new \InvalidArgumentException('Bundle not found');
        }
        if ($bundle->getActive() === false) {
            throw new \InvalidArgumentException('Bundle is not active');
        }
        return $bundle;
    }

    private function calculateBundlePricing(BundleEntity $bundle, string $currentProductId, SalesChannelContext $context): array
    {
        try {
            return $this->calculateBundlePricingWithCurrentProduct($bundle, $currentProductId, $context);
        } catch (\Exception $e) {
            return $this->calculateBundlePricingFallback($bundle, $currentProductId, $context);
        }
    }

    private function calculateBundlePricingWithCurrentProduct(BundleEntity $bundle, string $currentProductId, SalesChannelContext $context): array
    {
        $criteria = new Criteria([$currentProductId]);
        $currentProduct = $this->productRepository->search($criteria, $context->getContext())->first();

        if ($currentProduct === null) {
            throw new \InvalidArgumentException('Current product not found');
        }

        $currentProductBasePrice = $this->getProductBasePrice($currentProduct);
        $originalPrice = $currentProductBasePrice;
        $bundleProducts = [];
        $allProducts = [];

        $currentProductData = [
            'id' => $currentProductId,
            'name' => $currentProduct->getName(),
            'unitPrice' => $currentProductBasePrice,
            'totalPrice' => $currentProductBasePrice
        ];
        $allProducts[] = $currentProductData;

        if ($bundle->getBundleProducts()) {
            foreach ($bundle->getBundleProducts() as $bundleProduct) {
                $product = $bundleProduct->getProduct();

                if ($product === null || $product->getId() === $currentProductId) {
                    continue;
                }

                $productBasePrice = $this->getProductBasePrice($product);
                $quantity = $bundleProduct->getQuantity() ?: 1;
                $totalProductPrice = $productBasePrice * $quantity;
                $originalPrice += $totalProductPrice;

                $productData = [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'unitPrice' => $productBasePrice,
                    'totalPrice' => $totalProductPrice,
                    'quantity' => $quantity
                ];

                $bundleProducts[] = $productData;
                $allProducts[] = $productData;
            }
        }

        $discountAmount = $this->calculateDiscountAmount($bundle, $originalPrice);
        $finalPrice = max(0, $originalPrice - $discountAmount);

        return [
            'currentProduct' => $currentProductData,
            'bundleProducts' => $bundleProducts,
            'allProducts' => $allProducts,
            'originalPrice' => $originalPrice,
            'finalPrice' => $finalPrice,
            'savings' => $discountAmount,
            'discountType' => $bundle->getDiscountType(),
            'discountValue' => $bundle->getDiscount(),
            'productCount' => count($allProducts)
        ];
    }

    private function calculateBundlePricingFallback(BundleEntity $bundle, string $currentProductId, SalesChannelContext $context): array
    {
        $originalPrice = 0;
        $productDetails = [];

        if ($bundle->getBundleProducts()) {
            foreach ($bundle->getBundleProducts() as $bundleProduct) {
                $product = $bundleProduct->getProduct();
                if ($product === null) {
                    continue;
                }

                $productPrice = $this->getProductPrice($product);
                $quantity = $bundleProduct->getQuantity() ?: 1;
                $totalProductPrice = $productPrice * $quantity;
                $originalPrice += $totalProductPrice;

                $productDetails[] = [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'quantity' => $quantity,
                    'unitPrice' => $productPrice,
                    'totalPrice' => $totalProductPrice,
                    'isOptional' => $bundleProduct->isOptional(),
                    'source' => 'bundleProduct'
                ];
            }
        }

        [$currentProduct, $bundleProducts] = $this->separateCurrentAndBundleProducts($productDetails, $currentProductId);

        if ($currentProduct === null) {
            throw new \InvalidArgumentException('No products found in bundle');
        }

        $allProducts = array_merge([$currentProduct], $bundleProducts);
        $discountAmount = $this->calculateDiscountAmount($bundle, $originalPrice);
        $finalPrice = max(0, $originalPrice - $discountAmount);

        return [
            'currentProduct' => $currentProduct,
            'bundleProducts' => $bundleProducts,
            'allProducts' => $allProducts,
            'originalPrice' => $originalPrice,
            'discountType' => $bundle->getDiscountType(),
            'discountValue' => $bundle->getDiscount(),
            'discountAmount' => $discountAmount,
            'finalPrice' => $finalPrice,
            'savings' => $discountAmount,
            'productCount' => count($allProducts)
        ];
    }

    private function calculateDiscountAmount(BundleEntity $bundle, float $originalPrice): float
    {
        if ($bundle->getDiscount() === null || $bundle->getDiscount() <= 0) {
            return 0.0;
        }

        return $bundle->getDiscountType() === 'percentage'
            ? ($originalPrice * $bundle->getDiscount()) / 100
            : $bundle->getDiscount();
    }

    private function separateCurrentAndBundleProducts(array $productDetails, string $currentProductId): array
    {
        $currentProduct = null;
        $bundleProducts = [];

        foreach ($productDetails as $product) {
            if ($product['id'] === $currentProductId) {
                $currentProduct = $product;
            } else {
                $bundleProducts[] = $product;
            }
        }

        if ($currentProduct === null && empty($productDetails) === false) {
            $currentProduct = array_shift($productDetails);
            $bundleProducts = $productDetails;
        }

        return [$currentProduct, $bundleProducts];
    }

    private function createBundleDescription(array $bundleProducts, string $currentProductName): string
    {
        if (empty($bundleProducts)) {
            return "Bundle containing {$currentProductName}";
        }

        $productNames = array_column($bundleProducts, 'name');
        $productCount = count($productNames);
        $description = "Bundle: {$currentProductName}";

        if ($productCount === 1) {
            $description .= " + {$productNames[0]}";
        } elseif ($productCount === 2) {
            $description .= " + " . implode(" + ", $productNames);
        } else {
            $description .= " + {$productCount} other products";
        }

        return $description;
    }

    private function removeExistingBundleItems($cart, string $bundleId, SalesChannelContext $context): void
    {
        $lineItemsToRemove = [];

        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getPayloadValue('bundleId') === $bundleId) {
                $lineItemsToRemove[] = $lineItem->getId();
            }
        }

        if (empty($lineItemsToRemove) === false) {
            foreach ($lineItemsToRemove as $lineItemId) {
                $this->cartService->remove($cart, $lineItemId, $context);
            }
        }
    }

    private function recalculateCart($cart, SalesChannelContext $context): Cart
    {
        try {
            return $this->cartService->recalculate($cart, $context);
        } catch (\Exception $e) {
            return $cart;
        }
    }

    private function removeBundleItemsByBundleId($cart, string $bundleId, SalesChannelContext $context): array
    {
        $removedItems = [];
        $lineItemsToRemove = [];

        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getPayloadValue('bundleId') === $bundleId) {
                $lineItemsToRemove[] = $lineItem->getId();
                $removedItems[] = $this->createRemovedItemData($lineItem, $bundleId);
            }
        }

        foreach ($lineItemsToRemove as $lineItemId) {
            $this->cartService->remove($cart, $lineItemId, $context);
        }

        return $removedItems;
    }

    private function removeBundleItemByLineItemId($cart, string $lineItemId, SalesChannelContext $context): array
    {
        $lineItem = $cart->getLineItems()->get($lineItemId);

        if ($lineItem === null) {
            throw new \InvalidArgumentException('Line item not found in cart');
        }

        if ($lineItem->getPayloadValue('isBundle') !== true) {
            throw new \InvalidArgumentException('Line item is not a bundle item');
        }

        $this->cartService->remove($cart, $lineItemId, $context);

        return [$this->createRemovedItemData($lineItem, $lineItem->getPayloadValue('bundleId'))];
    }

    private function createRemovedItemData(LineItem $lineItem, string $bundleId): array
    {
        return [
            'lineItemId' => $lineItem->getId(),
            'bundleId' => $bundleId,
            'label' => $lineItem->getLabel(),
            'price' => $lineItem->getPrice() ? $lineItem->getPrice()->getTotalPrice() : 0,
            'bundleName' => $lineItem->getPayloadValue('bundleName')
        ];
    }

    private function getBundleItems($cart): array
    {
        $bundleItems = [];

        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getPayloadValue('isBundle') === true) {
                $bundleItems[] = [
                    'id' => $lineItem->getId(),
                    'bundleId' => $lineItem->getPayloadValue('bundleId'),
                    'label' => $lineItem->getLabel(),
                    'price' => $lineItem->getPrice() ? $lineItem->getPrice()->getTotalPrice() : 0,
                    'originalPrice' => $lineItem->getPayloadValue('originalPrice'),
                    'savings' => $lineItem->getPayloadValue('savings'),
                    'currentProduct' => [
                        'id' => $lineItem->getPayloadValue('mainProductId'),
                        'name' => $lineItem->getPayloadValue('mainProductName'),
                        'inCart' => true,
                        'price' => $lineItem->getPrice() ? $lineItem->getPrice()->getTotalPrice() : 0,
                        'details' => $lineItem->getPayloadValue('currentProduct')
                    ],
                    'includedProducts' => $lineItem->getPayloadValue('bundleProducts') ?: [],
                    'bundleDetails' => [
                        'description' => $lineItem->getPayloadValue('bundleDescription'),
                        'totalProductCount' => $lineItem->getPayloadValue('totalProductCount'),
                        'bundleName' => $lineItem->getPayloadValue('bundleName'),
                        'allProducts' => $lineItem->getPayloadValue('allBundleProducts') ?: []
                    ]
                ];
            }
        }

        return $bundleItems;
    }

    private function getProductPrice($product): float
    {
        if ($product->getPrice() !== null && $product->getPrice()->count() > 0) {
            $firstPrice = $product->getPrice()->first();
            if ($firstPrice !== null) {
                return $firstPrice->getGross();
            }
        }

        return 0.0;
    }

    private function getProductBasePrice($product): float
    {
        if ($product->getPrice() !== null && $product->getPrice()->count() > 0) {
            $firstPrice = $product->getPrice()->first();
            if ($firstPrice !== null) {
                return $firstPrice->getGross();
            }
        }

        $this->logger->error('No base price available for product', [
            'productId' => $product->getId(),
            'productName' => $product->getName()
        ]);

        return 0.0;
    }
}
