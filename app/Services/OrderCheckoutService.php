<?php

namespace App\Services;

use App\Models\Order;
use App\Jobs\NotifyVendorJob;
use App\DTOs\CartItemDTO;
use App\DTOs\CheckoutResultDTO;
use App\Services\Checkout\OrderCheckoutManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class OrderCheckoutService
{
    public function __construct(
        private readonly OrderCheckoutManager $checkoutManager
    ) {}

    public function checkout(array $payload): CheckoutResultDTO
    {
        $items = $this->parseItems($payload);

        $result = $this->checkoutManager->createFromCart($items);

        if ($result->success) {
            $this->dispatchNotifications($result->order);
        }

        return $result;
    }

    private function parseItems(array $payload): array
    {
        return collect($payload['items'] ?? [])
            ->map(fn($item) => CartItemDTO::fromArray($item))
            ->all();
    }

    private function dispatchNotifications(Order $order): void
    {
        DB::afterCommit(function () use ($order) {
            $order->subOrders->each(function ($subOrder) {
                NotifyVendorJob::dispatch($subOrder);
            });
        });
    }
}
