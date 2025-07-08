<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Administration\Controller;

use DigiPercep\BundleProducts\Service\BundleSyncService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ProductBundleController extends AbstractController
{
    public function __construct(
        private readonly BundleSyncService $bundleSyncService
    ) {
    }

    /**
     * @Route("/api/_action/digipercep-bundle/sync-product-bundles", name="api.action.digipercep.bundle.sync", methods={"POST"})
     */
    public function syncProductBundles(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $productId = $data['productId'] ?? null;
        $bundles = $data['bundles'] ?? [];
        if (!$productId) {
            return new JsonResponse(['error' => 'Product ID is required'], 400);
        }

        // Validate bundle data
        $validationErrors = $this->bundleSyncService->validateBundleData($bundles);
        if (!empty($validationErrors)) {
            return new JsonResponse(['error' => 'Validation failed', 'details' => $validationErrors], 400);
        }

        try {
            $this->bundleSyncService->syncProductBundles($productId, $bundles, $context);
            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @Route("/api/_action/digipercep-bundle/get-product-bundles", name="api.action.digipercep.bundle.get", methods={"GET"})
     */
    public function getProductBundles(Request $request, Context $context): JsonResponse
    {
        $productId = $request->query->get('productId');
        if (!$productId) {
            return new JsonResponse(['error' => 'Product ID is required'], 400);
        }

        try {
            $result = $this->bundleSyncService->getProductBundles($productId, $context);
            return new JsonResponse(['data' => $result]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}