<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Discounts\DiscountEngine;
use App\Discounts\QuantityThresholdDiscountRule;
use App\Discounts\CategoryDiscountRule;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DiscountEngine::class, function ($app) {
            return new DiscountEngine([
                $app->make(QuantityThresholdDiscountRule::class),
                $app->make(CategoryDiscountRule::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
