<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\SubOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyVendorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly SubOrder $subOrder
    ) {}

    public function handle(): void
    {
        $this->logNotification();

        $this->markAsNotified();
    }

    private function logNotification(): void
    {
        $itemsDescription = collect($this->subOrder->items_snapshot)
            ->map(fn($item) => "{$item['product_code']} (x{$item['quantity']})")
            ->implode(', ');

        Log::info(sprintf(
            "Vendor: %s | Order: %s | Items: %s",
            $this->subOrder->vendor_code,
            $this->subOrder->order_id,
            $itemsDescription
        ));
    }

    private function markAsNotified(): void
    {
        $this->subOrder->update([
            'status' => OrderStatus::VENDOR_NOTIFIED,
            'vendor_notified_at' => now(),
        ]);
    }
}
