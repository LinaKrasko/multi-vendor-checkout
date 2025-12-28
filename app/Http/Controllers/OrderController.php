<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateOrderRequest;
use App\Services\OrderCheckoutService;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $orders = Order::with(['subOrders'])->latest()->get();

        return OrderResource::collection($orders);
    }

    public function store(CreateOrderRequest $request, OrderCheckoutService $service): OrderResource|JsonResponse
    {
        $result = $service->checkout($request->validated());

        if (!$result->success) {
            return $this->handleFailure($result);
        }

        return $this->handleSuccess($result->order);
    }

    private function handleSuccess(Order $order): OrderResource
    {
        $order->unsetRelation('subOrders');

        return new OrderResource($order);
    }

    private function handleFailure(\App\DTOs\CheckoutResultDTO $result): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => $result->errorCode->value,
            'product_code' => $result->productCode,
            'message' => $this->getFailureMessage($result),
        ], 422);
    }

    private function getFailureMessage(\App\DTOs\CheckoutResultDTO $result): string
    {
        $message = "Checkout failed: {$result->errorCode->value}";

        if ($result->productCode) {
            $message .= " for product: {$result->productCode}";
        }

        return $message;
    }
}
