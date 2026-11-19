<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

/** Clients fictifs de la démo. */
class CustomerSeeder extends Seeder
{
    public const CUSTOMERS = [
        ['C-0142', 'Atlas Béton', 'Mounir Haddad', '+212 5 22 00 01 42', 'Zone industrielle Sidi Maârouf', 'Casablanca'],
        ['C-0098', 'Maghreb Agro', 'Leila Bouzid', '+212 5 22 00 00 98', 'Bd Abdelmoumen, Hay Hassani', 'Casablanca'],
        ['C-0117', 'Sahara Distribution', 'Rachid Mansouri', '+212 5 22 00 01 17', 'Zone industrielle Sidi Bernoussi', 'Casablanca'],
        ['C-0123', 'Casa Matériaux', 'Imane Rami', '+212 5 22 00 01 23', 'Route de Médiouna, Aïn Chock', 'Casablanca'],
    ];

    public function run(): void
    {
        foreach (self::CUSTOMERS as [$code, $name, $contact, $phone, $address, $city]) {
            Customer::updateOrCreate(['code' => $code], [
                'name' => $name,
                'contact_name' => $contact,
                'phone' => $phone,
                'email' => 'contact@'.str($name)->slug().'.example',
                'address' => $address,
                'city' => $city,
            ]);
        }
    }
}
