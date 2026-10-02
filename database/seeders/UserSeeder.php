<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Chat;
use App\Models\ChatUser;
use App\Models\Message;
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

        User::updateOrCreate(
            ['email' => 'test3@example.com'], // Уникальный критерий для поиска
            [
                'name' => 'Test3 User3',
                'password' => Hash::make('password'),
            ]
        );

        // Можно добавить второго пользователя по аналогии
        User::updateOrCreate(
            ['email' => 'test4@example.com'],
            [
                'name' => 'Test4 User4',
                'password' => Hash::make('password'),
            ]
        );

        Chat::create([
            'type' => 'direct',
        ]);
        Chat::create([
            'type' => 'direct',
        ]);
        Chat::create([
            'name' => 'Test group chat',
            'type' => 'group',
        ]);

        ChatUser::create([
            'chat_id' => 1,
            'user_id' => 1,
        ]);
        ChatUser::create([
            'chat_id' => 1,
            'user_id' => 2,
        ]);
        ChatUser::create([
            'chat_id' => 2,
            'user_id' => 3,
        ]);
        ChatUser::create([
            'chat_id' => 2,
            'user_id' => 4,
        ]);
        ChatUser::create([
            'chat_id' => 3,
            'user_id' => 1,
        ]);
        ChatUser::create([
            'chat_id' => 3,
            'user_id' => 2,
        ]);
        ChatUser::create([
            'chat_id' => 3,
            'user_id' => 3,
        ]);
        ChatUser::create([
            'chat_id' => 3,
            'user_id' => 4,
        ]);

        Message::create([
            'chat_id' => 1,
            'user_id' => 1,
            'body' => 'Hello, how are you?',
        ]);
        Message::create([
            'chat_id' => 1,
            'user_id' => 2,
            'body' => 'I\'m fine, thank you!',
        ]);


        Message::create([
            'chat_id' => 3,
            'user_id' => 3,
            'body' => 'Hello, how are you?',
        ]);
        Message::create([
            'chat_id' => 3,
            'user_id' => 4,
            'body' => 'I\'m fine, thank you!',
        ]);
    }
}