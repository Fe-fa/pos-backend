<?php

namespace App\Providers;

use App\Models\Billing;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Payment;
use App\Models\Product;
use App\Policies\BillingPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\InventoryPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ProductPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Category::class  => CategoryPolicy::class,
        Product::class   => ProductPolicy::class,
        Billing::class   => BillingPolicy::class,
        Payment::class   => PaymentPolicy::class,
        Inventory::class => InventoryPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // 1. CRITICAL: Initialize parent auth & policy mappings first so login works perfectly
        $this->registerPolicies();

        // 2. SAFE QUERY AUDITOR: Only triggers in your local development workspace
        if (config('app.env') === 'local') {
            DB::listen(function ($query) {
                Log::info(sprintf(
                    "\n[POS QUERY] Time: %s ms\nSQL: %s\nBindings: %s\n-----------------------",
                    $query->time,
                    $query->sql,
                    json_encode($query->bindings)
                ));
            });
        }
    }
}