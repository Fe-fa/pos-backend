<?php

namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
    public function run(): void
    {
        // Avoid creating duplicate entries if seeder runs multiple times
        if (Store::where('store_name', 'Unity Main Branch')->exists()) {
            return;
        }

        Store::create([
            'store_name'       => 'Unity Main Branch',
            'location'         => 'Headquarters',
            'currency'         => 'KSH', // You can change this to your preferred currency code (e.g., KSH, EUR)
            'logo_url'         => 'assets/images/default-logo.png',
            'telephone'        => '+1234567890',
            'pin'              => 'PIN123456789', // Tax identification/KRA PIN number if applicable
            'physical_address' => '123 Business Avenue, Suite 100',
            'email_address'    => 'mainbranch@unitystore.com',
            'is_active'        => true,
        ]);
    }
}