<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Criação de um usuário
        $user = User::create([
            'name' => 'Aplicativo Tábua das Marés',
            'email' => 'apptabuadasmares@webevolui.com',
            'password' => bcrypt('0£pae#^W4:4x]@golMZ-:WKgJ5mtO4gsddT1*0<D6OveF6^t/='),
        ]);

        // Geração de um token usando Laravel Sanctum
        $token = $user->createToken('AppToken')->plainTextToken;

        // Exibe o token no console para referência
        $this->command->info("Token gerado para o App: {$token}");
    }
}
