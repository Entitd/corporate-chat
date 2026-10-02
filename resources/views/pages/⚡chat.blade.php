<?php

use App\Models\Attachment;
use App\Models\Chat;
use App\Models\ChatUser;
use App\Models\Message;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

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
    public string $chatFilter = 'direct';

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

    /** Текст нового сообщения в редакторе. */
    public string $messageBody = '';

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
     * Отправить сообщение в открытый чат.
     */
    public function sendMessage(): void
    {
        $body = trim($this->messageBody);

        if ($body === '') {
            $this->reset('messageBody');

            return;
        }

        $this->validate([
            'messageBody' => ['string', 'max:5000'],
        ]);

        $chat = Chat::query()->whereKey($this->activeChatId)->first();

        abort_unless(
            $chat !== null && $chat->users()->whereKey(auth()->id())->exists(),
            403,
        );

        $chat->messages()->create([
            'user_id' => auth()->id(),
            'body' => $body,
        ]);

        $this->reset('messageBody');

        $this->dispatch('message-sent');
    }

    /**
     * Переключить фильтр списка чатов.
     */
    public function setFilter(string $filter): void
    {
        $this->chatFilter = in_array($filter, ['all', 'direct', 'group'], true) ? $filter : 'all';
        // dd($this->chatFilter);
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

        // $this->chatFilter = 'direct';
        
        // dd(collect($this->demoChats())
        //     ->map(function (array $chat): array {
        //         if (in_array($chat['id'], $this->readChatIds, true)) {
        //             $chat['unread'] = 0;
        //         }

        //         return $chat;
        //     })
        //     ->when($this->chatFilter !== 'all', fn ($chats) => $chats->where('type', $this->chatFilter))
        //     ->when($search !== '', fn ($chats) => $chats->filter(
        //         fn (array $chat): bool => str_contains(mb_strtolower($chat['title']), $search)
        //             || str_contains(mb_strtolower($chat['subtitle']), $search)
        //             || str_contains(mb_strtolower($chat['last_message']['text']), $search),
        //     ))
        //     ->sortByDesc('pinned')
        //     ->values()
        //     ->all()
        // );


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
        return auth()->user()->chats()
            ->where('type', 'direct')
            ->count();
    }

    /**
     * Количество групповых чатов.
     */
    #[Computed]
    public function groupCount(): int
    {
        return auth()->user()->chats()
            ->where('type', 'group')
            ->count();
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
        // $chats = Chat::users()->all()->toArray();
        $chats = auth()->user()->chats()->get()->toArray();

        foreach ($chats as $key => $chat) {
            if (($chat['type'] ?? 'direct') === 'direct') {
                // Ищем среди участников чата того, чей ID НЕ совпадает с вашим

                // dd($chat);
                $chat = Chat::find($chat['id']);

                // Находим первого участника, чей ID не равен ID текущего пользователя
                $interlocutor = $chat->users->firstWhere('id', '!=', auth()->id());

                // Получаем его имя (или ставим заглушку, если в чате пока никого нет)
                $interlocutorName = $interlocutor ? $interlocutor->name : 'Пустой чат';
                // dd($interlocutorName);

                // $interlocutor ;
                if ($interlocutor) {
                    // Заменяем технический title чата на имя собеседника
                    $chats[$key]['name'] = $interlocutor['name'];

                    // (Опционально) Если в массиве чата есть аватарка, меняем и её
                    if (isset($interlocutor['avatar'])) {
                        $chats[$key]['avatar'] = $interlocutor['avatar'];
                    }
                }
            }
        }

        return $chats;
    }

    /**
     * Справочник сотрудников (демо-данные).
     *
     * @return array<string, array<string, mixed>>
     */
    private function demoColleagues(): array
    {
        return User::all()->toArray();
    }

    /**
     * Переписка по чатам (демо-данные).
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function demoMessages(): array
    {
        $chat = Chat::find($this->activeChatId);

        return $chat->messages()
            ->with('user:id,name') // Сразу подгружаем автора (имя, аватар) одним запросом
            ->oldest()                    // Сортируем от старых к новым (ORDER BY created_at ASC)
            ->paginate(30)->toArray();
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
