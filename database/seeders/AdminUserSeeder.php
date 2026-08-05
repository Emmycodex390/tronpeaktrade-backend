<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'emmanuelobi50006@gmail.com'], // <-- Consistent email
            [
                'username' => 'Raymond',
                'name' => 'Raymond',
                'email' => 'emmanuelobi50006@gmail.com',
                'password' => Hash::make('investment'),
                'role' => 'admin',
                'phone' => '08000000000',
                'country' => 'Nigeria',
                'address' => 'Lagos',

                // --- KYC Fields ---
                'id_type' => 'national_id',
                'id_document' => 'seeded-admin-account', // placeholder — this is a CLI-seeded admin, not a real KYC upload
                'id_document_front' => null,
                'id_document_back' => null,
                'face_image' => null,
                'id_status' => 'verified',
                'face_match_score' => 100,

                // --- Payment Fields ---
                'bank_name' => 'GTBank',
                'account_name' => 'Emmanuel Gibson Obi',
                'account_number' => '0000000000',
                'paypal_email' => 'admin@company.com',

                // --- Balances ---
                'total_usdt' => 9008765554,
                'conversion_ngn' => 500000000,
                'asset_balance' => 73636255,
                'investment_balance' => 0,
                'ai_investment_balance' => 0,

                // --- Avatar ---
                'avatar' => null,
            ]
        );
    }
}