<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        User::create([
            'name' => 'Usuario Prueba',
            'email' => 'HCRAMIREZR@medilaser.com.co',
            'password' => bcrypt('1081393901'), // contraseña para login
        ]);
    }
}
