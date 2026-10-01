<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ищем по уникальному email, если нашли — обновляем имя/пароль, если нет — создаем
        User::updateOrCreate(
            ['email' => 'test@example.com'], // Уникальный критерий для поиска
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
            ]
        );

        // Можно добавить второго пользователя по аналогии
        User::updateOrCreate(
            ['email' => 'test2@example.com'],
            [
                'name' => 'Test2 User2',
                'password' => Hash::make('password'),
            ]
        );
    }
}