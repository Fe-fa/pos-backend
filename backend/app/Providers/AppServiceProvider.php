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
}
 
