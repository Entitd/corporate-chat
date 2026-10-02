<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

use App\Models\User;
use App\Models\Chat;
use App\Models\ChatUser;
use App\Models\Message;
use App\Models\Attachment;

/**
 * Демонстрационная страница корпоративного чата.
 *
 * Вся переписка и список чатов захардкожены в демо-данных — базы ещё нет.
 * Когда появятся модели Conversation и Message, методы demo*() заменяются
 * на запросы к Eloquent, а состояние ($activeChatId, $readChatIds) — на
 * реальные идентификаторы.
 */
new #[Layout('layouts::chat')] #[Title('Чат')] class extends Component
{
    /** Поисковый запрос по списку чатов. */
    public string $search = '';

    /** Активный фильтр списка: all, direct или group. */
    public string $chatFilter = 'all';

    /** Идентификатор открытого чата. */
    public int $activeChatId = 1;

    /** Панель с информацией о чате. */
    public bool $showDetails = false;

    /** На мобильных список чатов и переписка занимают экран по очереди. */
    public bool $showChatList = true;

    /** Модальное окно создания нового чата. */
    public bool $showNewChatModal = false;

    /** Режим создания чата: личный (direct) или групповой (group). */
    public string $newChatMode = 'direct';

    /** Поиск коллег в модальном окне нового чата. */
    public string $colleagueSearch = '';

    /** Выбранные коллеги для нового группового чата. */
    public array $selectedColleagues = [];

    /** Чаты, которые пользователь уже открыл в этой сессии. */
    public array $readChatIds = [];

    /**
     * Открыть чат.
     */
    public function selectChat(int $chatId): void
    {
        $this->activeChatId = $chatId;
        $this->readChatIds = array_values(array_unique([...$this->readChatIds, $chatId]));
        $this->showChatList = false;
        $this->showDetails = false;
    }

    /**
     * Вернуться к списку чатов (мобильные).
     */
    public function backToList(): void
    {
        $this->showChatList = true;
    }

    /**
     * Переключить фильтр списка чатов.
     */
    public function setFilter(string $filter): void
    {
        $this->chatFilter = in_array($filter, ['all', 'direct', 'group'], true) ? $filter : 'all';
    }

    /**
     * Показать или скрыть панель с информацией о чате.
     */
    public function toggleDetails(): void
    {
        $this->showDetails = ! $this->showDetails;
    }

    /**
     * Переключить режим создания чата.
     */
    public function setNewChatMode(string $mode): void
    {
        if (! in_array($mode, ['direct', 'group'], true)) {
            return;
        }

        $this->newChatMode = $mode;
        $this->selectedColleagues = [];
    }

    /**
     * Отметить все чаты прочитанными.
     */
    public function markAllAsRead(): void
    {
        $this->readChatIds = array_column($this->demoChats(), 'id');
    }

    /**
     * Отфильтрованный и отсортированный список чатов.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function chats(): array
    {
        $search = mb_strtolower(trim($this->search));

        return collect($this->demoChats())
            ->map(function (array $chat): array {
                if (in_array($chat['id'], $this->readChatIds, true)) {
                    $chat['unread'] = 0;
                }

                return $chat;
            })
            ->when($this->chatFilter !== 'all', fn ($chats) => $chats->where('type', $this->chatFilter))
            ->when($search !== '', fn ($chats) => $chats->filter(
                fn (array $chat): bool => str_contains(mb_strtolower($chat['title']), $search)
                    || str_contains(mb_strtolower($chat['subtitle']), $search)
                    || str_contains(mb_strtolower($chat['last_message']['text']), $search),
            ))
            ->sortByDesc('pinned')
            ->values()
            ->all();
    }

    /**
     * Количество личных чатов.
     */
    #[Computed]
    public function directCount(): int
    {
        return count(array_filter($this->demoChats(), fn (array $chat): bool => $chat['type'] === 'direct'));
    }

    /**
     * Количество групповых чатов.
     */
    #[Computed]
    public function groupCount(): int
    {
        return count(array_filter($this->demoChats(), fn (array $chat): bool => $chat['type'] === 'group'));
    }

    /**
     * Количество непрочитанных сообщений в отфильтрованном списке.
     */
    #[Computed]
    public function unreadTotal(): int
    {
        return (int) collect($this->chats)->sum('unread');
    }

    /**
     * Открытый чат.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function activeChat(): array
    {
        // dd(collect($this->demoChats())->firstWhere('id', $this->activeChatId)
        // ?? $this->demoChats()[0]);

        return collect($this->demoChats())->firstWhere('id', $this->activeChatId)
            ?? $this->demoChats()[0];
    }

    /**
     * Сообщения открытого чата с разметкой групп и разделителей дат.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    // public function messages(): array
    // {
    //     $messages = $this->demoMessages()
    //         ?? $this->fallbackMessages($this->activeChat);

    //     dd($messages);
    //     $previous = null;

    //     return array_map(function (array $message) use (&$previous): array {
    //         $message['show_day'] = $previous === null || $previous['day'] !== $message['day'];
    //         $message['first_of_group'] = $previous === null
    //             || $previous['author'] !== $message['author']
    //             || $previous['own'] !== $message['own']
    //             || $previous['day'] !== $message['day'];

    //         $previous = $message;

    //         return $message;
    //     }, $messages);
    // }

    public function messages(): array
    {
        $paginatorData = $this->demoMessages()
            ?? $this->fallbackMessages($this->activeChat);

        // Достаем массив самих сообщений из пагинатора
        $messages = $paginatorData['data'] ?? [];

        $previous = null;

        $processedMessages = array_map(function (array $message) use (&$previous): array {
            // 1. Безопасно вытаскиваем день из created_at (если null — ставим текущую дату)
            $currentDay = $message['created_at'] 
                ? date('Y-m-d', strtotime($message['created_at'])) 
                : date('Y-m-d');

            // 2. Вычисляем, принадлежит ли сообщение текущему пользователю
            $isOwn = isset($message['user_id']) && $message['user_id'] === auth()->id();
            
            // Добавляем флаг own в массив сообщения
            $message['own'] = $isOwn;

            // 3. Вычисляем день для предыдущего сообщения (для сравнения)
            $previousDay = $previous && $previous['created_at']
                ? date('Y-m-d', strtotime($previous['created_at']))
                : ($previous ? date('Y-m-d') : null);

            // 4. Считаем флаги отображения
            $message['show_day'] = $previous === null || $previousDay !== $currentDay;
            
            $message['first_of_group'] = $previous === null
                || $previous['user_id'] !== ($message['user_id'] ?? null)
                || $previous['own'] !== $isOwn
                || $previousDay !== $currentDay;

            // Сохраняем текущее сообщение как предыдущее для следующей итерации
            $previous = $message;

            return $message;
        }, $messages);

        // Записываем обработанные сообщения обратно в пагинатор
        $paginatorData['data'] = $processedMessages;

        // dd($paginatorData);
        return $paginatorData;
    }




    /**
     * Файлы, которыми поделились в открытом чате.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function sharedFiles(): array
    {
        return array_values(array_filter(array_map(
            fn (array $message): ?array => $message['attachment'] === null ? null : [
                ...$message['attachment'],
                'author' => $message['own'] ? auth()->user()->name : $message['author'],
                'at' => $message['at'],
            ],
            $this->messages,
        )));
    }

    /**
     * Ссылки, которыми поделились в открытом чате.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function sharedLinks(): array
    {
        return array_values(array_filter(array_map(
            fn (array $message): ?array => $message['link'] === null ? null : [
                ...$message['link'],
                'author' => $message['own'] ? auth()->user()->name : $message['author'],
                'at' => $message['at'],
            ],
            $this->messages,
        )));
    }

    /**
     * Участники открытого чата с должностями и статусом.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function participants(): array
    {
        $colleagues = $this->demoColleagues();

        // dd(ChatUser::where('chat_id', $this->activeChatId)->pluck('user_id')->toArray());

        // dd($users = Chat::find($this->activeChatId)->users()->pluck('name')->toArray());
        // dd(User::where('', ChatUser::where('chat_id', $this->activeChatId)->pluck('user_id')->toArray())->value('name'));
        // dd(ChatUser::where('chat_id', $this->activeChatId)->get('user_id')->toArray());

        return array_values(
            array_map(
                fn (string $name): array => $colleagues[$name] ?? [
                    'name' => $name,
                    'position' => 'Сотрудник',
                    'online' => false,
                    'last_seen' => null,
                ],
                $users = Chat::find($this->activeChatId)->users()->pluck('name')->toArray(),
            )
        );
    }

    /**
     * Справочник сотрудников для модального окна нового чата.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function colleagues(): array
    {
        $search = mb_strtolower(trim($this->colleagueSearch));

        return array_values(array_filter(
            $this->demoColleagues(),
            fn (array $colleague): bool => $search === ''
                || str_contains(mb_strtolower($colleague['name']), $search)
                || str_contains(mb_strtolower($colleague['position']), $search)
                || str_contains(mb_strtolower($colleague['department']), $search),
        ));
    }

    /**
     * Список чатов (демо-данные).
     *
     * @return array<int, array<string, mixed>>
     */
    private function demoChats(): array
    {
        return Chat::all()->toArray();
        dd(Chat::all()->toArray());
        return [
            [
                'id' => 10,
                'type' => 'group',
                'title' => 'Отдел разработки',
                'subtitle' => '7 участников, 4 онлайн',
                'online' => false,
                'members' => [
                    'Дмитрий Соколов', 'Анна Ковалёва', 'Игорь Петров', 'Мария Лебедева',
                    'Ольга Новикова', 'Сергей Морозов', 'Екатерина Волкова',
                ],
                'unread' => 5,
                'pinned' => true,
                'muted' => false,
                'typing' => 'Дмитрий Соколов',
                'last_message' => ['author' => 'Ольга Новикова', 'text' => 'Проверю и поправлю, спасибо!', 'at' => '10:26'],
            ],
            [
                'id' => 1,
                'type' => 'direct',
                'title' => 'Анна Ковалёва',
                'subtitle' => 'Руководитель отдела маркетинга',
                'presence' => 'в сети',
                'online' => true,
                'members' => ['Анна Ковалёва', 'Вы'],
                'unread' => 2,
                'pinned' => false,
                'muted' => false,
                'typing' => null,
                'last_message' => ['author' => 'Анна Ковалёва', 'text' => 'И тексты обновила, забирай.', 'at' => '10:41'],
            ],
            [
                'id' => 11,
                'type' => 'group',
                'title' => 'Проект «Атлас»',
                'subtitle' => '4 участника, 2 онлайн',
                'online' => false,
                'members' => ['Сергей Морозов', 'Ольга Новикова', 'Дмитрий Соколов', 'Екатерина Волкова'],
                'unread' => 0,
                'pinned' => false,
                'muted' => false,
                'typing' => null,
                'last_message' => ['author' => 'Сергей Морозов', 'text' => 'Миграцию прогнал на стейдже, всё зелёное.', 'at' => '09:58'],
            ],
            [
                'id' => 3,
                'type' => 'direct',
                'title' => 'Мария Лебедева',
                'subtitle' => 'HR-менеджер',
                'presence' => 'была 20 минут назад',
                'online' => false,
                'members' => ['Мария Лебедева', 'Вы'],
                'unread' => 1,
                'pinned' => false,
                'muted' => false,
                'typing' => null,
                'last_message' => ['author' => 'Мария Лебедева', 'text' => 'Напомни, пожалуйста, даты отпуска.', 'at' => 'Вчера'],
            ],
            [
                'id' => 12,
                'type' => 'group',
                'title' => 'Общие объявления',
                'subtitle' => '3 участника',
                'online' => false,
                'members' => ['Игорь Петров', 'Мария Лебедева', 'Анна Ковалёва'],
                'unread' => 3,
                'pinned' => false,
                'muted' => true,
                'typing' => null,
                'last_message' => ['author' => 'Мария Лебедева', 'text' => 'С пятницы доступен новый пропускной режим.', 'at' => 'Вчера'],
            ],
            [
                'id' => 4,
                'type' => 'direct',
                'title' => 'Ольга Новикова',
                'subtitle' => 'Продуктовый дизайнер',
                'presence' => 'в сети',
                'online' => true,
                'members' => ['Ольга Новикова', 'Вы'],
                'unread' => 0,
                'pinned' => false,
                'muted' => false,
                'typing' => null,
                'last_message' => ['author' => 'Ольга Новикова', 'text' => 'Скинула макеты на ревью.', 'at' => 'Вчера'],
            ],
            [
                'id' => 5,
                'type' => 'direct',
                'title' => 'Сергей Морозов',
                'subtitle' => 'DevOps-инженер',
                'presence' => 'был 3 часа назад',
                'online' => false,
                'members' => ['Сергей Морозов', 'Вы'],
                'unread' => 0,
                'pinned' => false,
                'muted' => false,
                'typing' => null,
                'last_message' => ['author' => 'Вы', 'text' => 'Спасибо, деплой прошёл.', 'at' => 'Вчера'],
            ],
            [
                'id' => 13,
                'type' => 'group',
                'title' => 'Маркетинг и продажи',
                'subtitle' => '4 участника, 3 онлайн',
                'online' => false,
                'members' => ['Анна Ковалёва', 'Игорь Петров', 'Мария Лебедева', 'Ольга Новикова'],
                'unread' => 0,
                'pinned' => false,
                'muted' => false,
                'typing' => null,
                'last_message' => ['author' => 'Анна Ковалёва', 'text' => 'Сводка по лидам за неделю во вложении.', 'at' => 'Пн'],
            ],
        ];
    }

    /**
     * Справочник сотрудников (демо-данные).
     *
     * @return array<string, array<string, mixed>>
     */
    private function demoColleagues(): array
    {
        return User::all()->toArray();
        dd($res);
        return [
            'Анна Ковааааааааалёва' => [
                'name' => 'Анна Коваааааааааааалёва',
                'position' => 'Руководитель отдела маркетинга',
                'department' => 'Маркетинг',
                'online' => true,
                'last_seen' => null,
            ],
            'Дмитрий Соколов' => [
                'name' => 'Дмитрий Соколов',
                'position' => 'Backend-разработчик',
                'department' => 'Разработка',
                'online' => true,
                'last_seen' => null,
            ],
            'Мария Лебедева' => [
                'name' => 'Мария Лебедева',
                'position' => 'HR-менеджер',
                'department' => 'Персонал',
                'online' => false,
                'last_seen' => 'была 20 минут назад',
            ],
            'Игорь Петров' => [
                'name' => 'Игорь Петров',
                'position' => 'Финансовый аналитик',
                'department' => 'Финансы',
                'online' => true,
                'last_seen' => null,
            ],
            'Ольга Новикова' => [
                'name' => 'Ольга Новикова',
                'position' => 'Продуктовый дизайнер',
                'department' => 'Продукт',
                'online' => true,
                'last_seen' => null,
            ],
            'Сергей Морозов' => [
                'name' => 'Сергей Морозов',
                'position' => 'DevOps-инженер',
                'department' => 'Разработка',
                'online' => false,
                'last_seen' => 'был 3 часа назад',
            ],
            'Екатерина Волкова' => [
                'name' => 'Екатерина Волкова',
                'position' => 'QA-лид',
                'department' => 'Разработка',
                'online' => false,
                'last_seen' => 'была вчера в 19:40',
            ],
        ];
    }

    /**
     * Переписка по чатам (демо-данные).
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function demoMessages(): array
    {
        //dd($this->activeChatId);
        // $chat = Chat::find($this->activeChatId);


        $chat = Chat::find($this->activeChatId);

        return $chat->messages()
            ->with('user:id,name') // Сразу подгружаем автора (имя, аватар) одним запросом
            ->latest()                    // Сортируем от новых к старым (ORDER BY created_at DESC)
            ->paginate(30)->toArray();


        // 2. Вытаскиваем сообщения через связь
        // $messages = $chat->messages()
        //     ->with('user:id,name') // Сразу подгружаем автора (имя, аватар) одним запросом
        //     ->latest()                    // Сортируем от новых к старым (ORDER BY created_at DESC)
        //     ->paginate(30);               // Берем порциями по 30 штук (для бесконечного скролла)
        // dd(
        //     $chat->messages()
        //     ->with('user:id,name') // Сразу подгружаем автора (имя, аватар) одним запросом
        //     ->latest()                    // Сортируем от новых к старым (ORDER BY created_at DESC)
        //     ->paginate(30)->toArray()
        // );
        return [
            10 => [
                $this->message('Екатерина Волкова', 'Загрузила результаты регресса по релизу 1.7 — два падения на оплате, задачи завела в трекер.', '18:24', ['day' => 'Вчера']),
                $this->ownMessage('Спасибо, посмотрю утром первым делом.', '18:31', ['day' => 'Вчера']),
                $this->message('Игорь Петров', 'Коллеги, доброе утро! Напоминаю: сегодня в 15:00 демо спринта в переговорной «Восток».', '09:12'),
                $this->message('Мария Лебедева', 'Буду, подключусь из офиса.', '09:15', [
                    'reactions' => [['emoji' => '👍', 'count' => 2, 'mine' => false]],
                ]),
                $this->ownMessage('Я подготовлю сборку к 14:30, чтобы успели прогнать smoke-тесты.', '09:21'),
                $this->message('Дмитрий Соколов', 'Собрал черновик release notes, посмотрите до обеда.', '09:34', [
                    'attachment' => ['name' => 'release-notes-1.7.md', 'size' => '24 КБ', 'kind' => 'Markdown'],
                ]),
                $this->message('Анна Ковалёва', 'Обновила макеты релиза 1.8, ссылка в задаче ATL-482.', '10:02', [
                    'link' => [
                        'host' => 'figma.com',
                        'title' => 'Макеты релиза 1.8 — корпоративный портал',
                        'description' => 'Экраны чата, профиля и настроек уведомлений. Комментарии оставляйте прямо во фреймах.',
                        'url' => 'https://figma.com/file/atlas-1-8',
                    ],
                    'reactions' => [['emoji' => '🔥', 'count' => 3, 'mine' => true]],
                ]),
                $this->ownMessage('Отлично, забираю в работу.', '10:05'),
                $this->message('Дмитрий Соколов', 'Уточню по отступам в таблице участников — кажется, на мобильных поедет.', '10:18', [
                    'reply' => ['author' => 'Анна Ковалёва', 'body' => 'Обновила макеты релиза 1.8, ссылка в задаче ATL-482.'],
                ]),
                $this->message('Ольга Новикова', 'Проверю и поправлю, спасибо!', '10:26'),
            ],
            1 => [
                $this->message('Анна Ковалёва', 'Привет! Есть минутка обсудить лендинг для конференции?', '10:31'),
                $this->ownMessage('Привет, да. Что нужно поправить?', '10:33'),
                $this->message('Анна Ковалёва', 'Первый экран: нужно поднять форму регистрации выше, на мобильных она уезжает под фолд.', '10:36'),
                $this->ownMessage('Понял, поправлю сегодня до конца дня. Заведу отдельную задачу, чтобы не потерялось.', '10:38'),
                $this->message('Анна Ковалёва', 'И тексты обновила, забирай.', '10:41', [
                    'attachment' => ['name' => 'conference-landing-copy.docx', 'size' => '1,2 МБ', 'kind' => 'Документ'],
                ]),
            ],
            11 => [
                $this->message('Сергей Морозов', 'Коллеги, сегодня ночью переносим интеграцию с 1С на новый шлюз.', '09:40', ['day' => 'Вчера']),
                $this->ownMessage('Нужно ли останавливать приём заявок?', '09:44', ['day' => 'Вчера']),
                $this->message('Сергей Морозов', 'Нет, очередь дособерёт. Просто будет задержка до 5 минут.', '09:46', ['day' => 'Вчера']),
                $this->message('Екатерина Волкова', 'Тогда прогоню сценарий с задержкой на стейдже до 22:00.', '09:51'),
                $this->message('Сергей Морозов', 'Миграцию прогнал на стейдже, всё зелёное.', '09:58'),
            ],
            12 => [
                $this->message('Мария Лебедева', 'Коллеги, с пятницы доступен новый пропускной режим: вход по карте с двух сторон здания.', '17:10', ['day' => 'Вчера']),
                $this->message('Игорь Петров', 'Корпоративная связь переехала на новый тариф, счета придут в новом формате.', '17:40', ['day' => 'Вчера']),
                $this->message('Мария Лебедева', 'Напоминаю про диспансеризацию — запись открыта до конца месяца.', '09:05'),
            ],
            3 => [
                $this->message('Мария Лебедева', 'Привет! Напомни, пожалуйста, даты отпуска — нужно обновить график.', 'Вчера'),
                $this->ownMessage('Привет! С 12 по 26 августа.', 'Вчера'),
                $this->message('Мария Лебедева', 'Записала, спасибо!', 'Вчера'),
            ],
            4 => [
                $this->message('Ольга Новикова', 'Скинула макеты на ревью, посмотри блок с участниками чата.', 'Вчера'),
                $this->ownMessage('Посмотрю завтра утром, спасибо!', 'Вчера'),
            ],
            5 => [
                $this->message('Сергей Морозов', 'Деплой в прод прошёл, мониторинг чистый.', 'Вчера'),
                $this->ownMessage('Спасибо, деплой прошёл.', 'Вчера'),
            ],
            13 => [
                $this->message('Анна Ковалёва', 'Сводка по лидам за неделю во вложении.', 'Пн', [
                    'attachment' => ['name' => 'leads-week-32.xlsx', 'size' => '318 КБ', 'kind' => 'Таблица'],
                ]),
            ],
        ];
    }

    /**
     * Заготовка переписки для чата без явной истории.
     *
     * @param  array<string, mixed>  $chat
     * @return array<int, array<string, mixed>>
     */
    private function fallbackMessages(array $chat): array
    {
        $members = array_values(array_filter($chat['members'], fn (string $member): bool => $member !== 'Вы'));
        $first = $members[0] ?? 'Коллега';
        $second = $members[1] ?? $first;

        return [
            $this->message($first, 'Добрый день! Подскажите, статус по задаче не менялся?', '09:20', ['day' => 'Вчера']),
            $this->ownMessage('Добрый! Вчера закончил, сегодня отправлю на проверку.', '09:26', ['day' => 'Вчера']),
            $this->message($second, 'Если нужна помощь с тестами — пишите, подключусь.', '09:32'),
            $this->message($first, 'Спасибо, держу в курсе.', '09:40'),
        ];
    }

    /**
     * Собрать сообщение от коллеги.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function message(string $author, string $body, string $at, array $extra = []): array
    {
        return array_merge([
            'author' => $author,
            'own' => false,
            'body' => $body,
            'at' => $at,
            'day' => 'Сегодня',
            'status' => 'sent',
            'attachment' => null,
            'link' => null,
            'reactions' => [],
            'reply' => null,
        ], $extra);
    }

    /**
     * Собрать сообщение от текущего пользователя.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function ownMessage(string $body, string $at, array $extra = []): array
    {
        return $this->message('', $body, $at, array_merge(['own' => true, 'status' => 'read'], $extra));
    }
}; ?>

<div class="flex h-dvh w-full overflow-hidden bg-white dark:bg-zinc-800">
    {{-- Список чатов --}}
    <aside class="{{ $showChatList ? 'flex w-full' : 'hidden' }} shrink-0 flex-col border-e border-zinc-200 bg-zinc-50 lg:flex lg:w-80 xl:w-[22rem] dark:border-zinc-700 dark:bg-zinc-900">
        <x-chat.sidebar
            :chats="$this->chats"
            :active-chat-id="$activeChatId"
            :chat-filter="$chatFilter"
            :unread-total="$this->unreadTotal"
            :direct-count="$this->directCount"
            :group-count="$this->groupCount"
        />
    </aside>

    {{-- Переписка --}}
    <main class="{{ $showChatList ? 'hidden' : 'flex' }} min-w-0 flex-1 flex-col lg:flex">
        <x-chat.header
            :chat="$this->activeChat"
            :participants-count="count($this->participants)"
            :show-details="$showDetails"
        />

        <x-chat.thread
            :chat="$this->activeChat"
            :messages="$this->messages"
        />

        <x-chat.composer :chat="$this->activeChat" />
    </main>

    {{-- Информация о чате --}}
    @if ($showDetails)
        <x-chat.details
            :chat="$this->activeChat"
            :participants="$this->participants"
            :files="$this->sharedFiles"
            :links="$this->sharedLinks"
        />
    @endif

    <x-chat.new-chat-modal
        :colleagues="$this->colleagues"
        :mode="$newChatMode"
        :selected="$selectedColleagues"
    />
</div>
