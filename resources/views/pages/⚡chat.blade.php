<?php

use App\Models\Attachment;
use App\Models\Chat;
use App\Models\ChatUser;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

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
    use WithFileUploads;

    /** Поисковый запрос по списку чатов. */
    public string $search = '';

    /** Активный фильтр списка: all, direct или group. */
    public string $chatFilter = 'all';

    /** Идентификатор открытого чата. */
    public int $activeChatId = 0;

    /** Поиск по сообщениям открытого чата. */
    public bool $showMessageSearch = false;

    public string $messageSearch = '';

    public int $messagePage = 1;

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

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $pendingFiles = [];

    /** @var array<int, int> */
    public array $selectedMentionIds = [];

    public bool $showMentionPicker = false;

    public string $mentionSearch = '';

    public function mount(): void
    {
        $this->activeChatId = auth()->user()->chats()->orderBy('chats.id')->value('chats.id') ?? 0;
    }

    /**
     * Открыть чат.
     */
    public function selectChat(int $chatId): void
    {
        abort_unless(auth()->user()->chats()->whereKey($chatId)->exists(), 403);

        $this->activeChatId = $chatId;
        $this->readChatIds = array_values(array_unique([...$this->readChatIds, $chatId]));
        $this->showChatList = false;
        $this->showDetails = false;
        $this->showMessageSearch = false;
        $this->messageSearch = '';
        $this->messagePage = 1;
        $this->reset('messageBody', 'pendingFiles', 'selectedMentionIds', 'showMentionPicker', 'mentionSearch');
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

        $chat = Chat::query()->whereKey($this->activeChatId)->first();

        abort_unless(
            $chat !== null && $chat->users()->whereKey(auth()->id())->exists(),
            403,
        );

        if ($body === '' && $this->pendingFiles === []) {
            $this->reset('messageBody');

            return;
        }

        $this->validate([
            'messageBody' => ['string', 'max:5000'],
            'pendingFiles' => ['array', 'max:3'],
            'pendingFiles.*' => ['file', 'max:2048'],
        ], [
            'pendingFiles.max' => __('Можно прикрепить не больше 3 файлов.'),
            'pendingFiles.*.max' => __('Файл должен быть не больше 2 МБ.'),
        ]);

        $mentionIds = array_values(array_unique(array_map('intval', $this->selectedMentionIds)));
        $mentionedUsers = $chat->users()->whereIn('users.id', $mentionIds)->get(['users.id', 'users.name']);
        abort_unless($mentionedUsers->count() === count($mentionIds), 403);
        $mentionIds = $mentionedUsers
            ->filter(fn (User $user): bool => str_contains($body, '@'.$user->name))
            ->pluck('id')
            ->all();

        $storedPaths = [];

        try {
            DB::transaction(function () use ($chat, $body, $mentionIds, &$storedPaths): void {
                $message = $chat->messages()->create([
                    'user_id' => auth()->id(),
                    'body' => $body === '' ? null : $body,
                ]);

                $message->mentions()->attach($mentionIds);

                foreach ($this->pendingFiles as $file) {
                    $fileName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                    $fileName = mb_strimwidth(preg_replace('/[[:cntrl:]]/u', '', $fileName) ?: 'file', 0, 255);
                    $path = $file->store('chat-attachments/'.$chat->id, 'local');

                    if ($path === false) {
                        throw new \RuntimeException('Не удалось сохранить файл.');
                    }

                    $storedPaths[] = $path;

                    $message->attachments()->create([
                        'file_path' => $path,
                        'file_name' => $fileName,
                        'file_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'file_size' => $file->getSize(),
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);

            throw $exception;
        }

        $this->reset('messageBody', 'pendingFiles', 'selectedMentionIds', 'showMentionPicker', 'mentionSearch');

        $this->dispatch('message-sent');
    }

    public function removePendingFile(int $index): void
    {
        unset($this->pendingFiles[$index]);
        $this->pendingFiles = array_values($this->pendingFiles);
    }

    public function openMentionPicker(): void
    {
        abort_unless($this->activeChatId && auth()->user()->chats()->whereKey($this->activeChatId)->exists(), 403);
        $this->showMentionPicker = true;
        $this->mentionSearch = '';
    }

    public function updatedMessageBody(): void
    {
        if (preg_match('/(?:^|\s)@([\p{L}\p{N}._-]*)$/u', $this->messageBody, $matches)) {
            $this->showMentionPicker = true;
            $this->mentionSearch = $matches[1];
        } else {
            $this->showMentionPicker = false;
            $this->mentionSearch = '';
        }
    }

    /** @return array<int, array{id: int, name: string, title: string|null}> */
    #[Computed]
    public function mentionCandidates(): array
    {
        if (! $this->activeChatId) {
            return [];
        }

        $this->activeChat;

        $search = mb_strtolower(trim($this->mentionSearch));

        return Chat::findOrFail($this->activeChatId)->users()
            ->where('users.id', '!=', auth()->id())
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.title'])
            ->filter(fn (User $user): bool => $search === '' || str_contains(mb_strtolower($user->name), $search))
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'title' => $user->title])
            ->values()
            ->all();
    }

    public function mentionColleague(int $userId): void
    {
        $chat = Chat::query()->whereKey($this->activeChatId)->first();
        abort_unless($chat !== null && $chat->users()->whereKey(auth()->id())->exists(), 403);

        $colleague = $chat->users()->whereKey($userId)->where('users.id', '!=', auth()->id())->first();
        abort_unless($colleague !== null, 403);

        $text = '@'.$colleague->name.' ';
        if (preg_match('/(?:^|\s)@[\p{L}\p{N}._-]*$/u', $this->messageBody)) {
            $this->messageBody = preg_replace_callback('/@[\p{L}\p{N}._-]*$/u', fn (): string => $text, $this->messageBody);
        } else {
            $this->messageBody = ltrim(rtrim($this->messageBody).' '.$text);
        }

        $this->selectedMentionIds = array_values(array_unique([...$this->selectedMentionIds, $colleague->id]));
        $this->showMentionPicker = false;
        $this->mentionSearch = '';
        $this->dispatch('mention-inserted');
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
        abort_unless($this->activeChatId && auth()->user()->chats()->whereKey($this->activeChatId)->exists(), 403);
        $this->showDetails = ! $this->showDetails;
    }

    public function showParticipants(): void
    {
        abort_unless($this->activeChatId && auth()->user()->chats()->whereKey($this->activeChatId)->exists(), 403);
        $this->showDetails = true;
        $this->dispatch('show-chat-participants');
    }

    public function toggleMessageSearch(): void
    {
        abort_unless($this->activeChatId && auth()->user()->chats()->whereKey($this->activeChatId)->exists(), 403);
        $this->showMessageSearch = ! $this->showMessageSearch;
        $this->messageSearch = '';
        $this->messagePage = 1;
    }

    public function updatedMessageSearch(): void
    {
        $this->messagePage = 1;
        $this->dispatch('message-search-updated');
    }

    public function changeMessagePage(int $page): void
    {
        abort_unless($this->activeChatId && auth()->user()->chats()->whereKey($this->activeChatId)->exists(), 403);
        $lastPage = max(1, (int) ceil($this->messages['total'] / 30));
        $this->messagePage = min(max($page, 1), $lastPage);
        unset($this->messages);
        $this->dispatch('message-search-updated');
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
            ->when($this->chatFilter !== 'all', fn ($chats) => $chats->where('type', $this->chatFilter))
            ->when($search !== '', fn ($chats) => $chats->filter(
                fn (array $chat): bool => str_contains(mb_strtolower($chat['name']), $search),
            ))
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

        if (! $this->activeChatId) {
            return [];
        }

        abort_unless(auth()->user()->chats()->whereKey($this->activeChatId)->exists(), 403);

        return collect($this->demoChats())->firstWhere('id', $this->activeChatId) ?? [];
    }

    /**
     * Сообщения открытого чата с разметкой групп и разделителей дат.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function messages(): array
    {
        $paginatorData = $this->activeChatId ? $this->demoMessages() : ['data' => [], 'total' => 0];

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
        if (! $this->activeChatId) {
            return [];
        }

        $this->activeChat;

        return Attachment::query()
            ->join('messages', 'attachments.message_id', '=', 'messages.id')
            ->join('users', 'messages.user_id', '=', 'users.id')
            ->where('messages.chat_id', $this->activeChatId)
            ->whereNull('messages.deleted_at')
            ->select('attachments.id', 'attachments.file_name', 'attachments.file_size', 'users.name as author')
            ->get()
            ->map(fn (Attachment $file): array => [
                'name' => $file->file_name,
                'size' => $file->file_size === null ? '—' : number_format($file->file_size / 1024, 1).' КБ',
                'author' => $file->author,
                'url' => route('chat.attachments.download', $file->id),
            ])
            ->all();
    }

    /**
     * Ссылки, которыми поделились в открытом чате.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function sharedLinks(): array
    {
        if (! $this->activeChatId) {
            return [];
        }

        $this->activeChat;

        return Chat::findOrFail($this->activeChatId)->messages()
            ->whereNotNull('body')
            ->latest()
            ->get(['body', 'created_at'])
            ->flatMap(function (Message $message): array {
                preg_match_all('~https?://[^\s<>]+~u', $message->body, $matches);

                return array_map(fn (string $url): array => [
                    'url' => $url,
                    'title' => $url,
                    'host' => parse_url($url, PHP_URL_HOST) ?: $url,
                    'at' => $message->created_at?->format('d.m.Y') ?? '',
                ], $matches[0]);
            })
            ->all();
    }

    /**
     * Участники открытого чата с должностями и статусом.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function participants(): array
    {
        if (! $this->activeChatId) {
            return [];
        }

        $this->activeChat;

        return Chat::findOrFail($this->activeChatId)->users()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.title'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'position' => $user->title ?: __('Сотрудник'),
            ])
            ->all();
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
                } else {
                    $chats[$key]['name'] = $interlocutorName;
                }
            } else {
                $chats[$key]['name'] = $chat['name'] ?: __('Групповой чат');
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
        $this->activeChat;
        $chat = Chat::findOrFail($this->activeChatId);

        $query = $chat->messages()
            ->with(['user:id,name', 'attachments:id,message_id,file_name,file_type,file_size', 'mentions:id,name'])
            ->oldest()
            ->orderBy('id');
        $search = trim($this->messageSearch);

        if ($search === '') {
            return $query->paginate(30)->toArray();
        }

        $matches = [];
        $total = 0;

        foreach ($query->lazy(200) as $message) {
            if (mb_stripos($message->body ?? '', $search) === false) {
                continue;
            }

            if ($total >= ($this->messagePage - 1) * 30 && count($matches) < 30) {
                $matches[] = $message->toArray();
            }

            $total++;
        }

        return ['data' => $matches, 'total' => $total];
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
    @if ($this->activeChat !== [])
    <main class="{{ $showChatList ? 'hidden' : 'flex' }} min-w-0 flex-1 flex-col lg:flex">
        <x-chat.header
            :chat="$this->activeChat"
            :participants-count="count($this->participants)"
            :show-details="$showDetails"
        />

        @if ($showMessageSearch)
            <div class="flex items-center gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700" data-test="message-search-panel">
                <flux:input wire:model.live.debounce.300ms="messageSearch" class="flex-1" size="sm" icon="magnifying-glass" clearable :placeholder="__('Поиск по сообщениям')" :aria-label="__('Поиск по сообщениям')" data-test="message-search-input" />
                @if (trim($messageSearch) !== '')
                    <span class="shrink-0 text-xs text-zinc-500">{{ __('Найдено: :count', ['count' => $this->messages['total']]) }}</span>
                @endif
                <flux:button size="sm" variant="ghost" icon="x-mark" square wire:click="toggleMessageSearch" :aria-label="__('Закрыть поиск')" />
            </div>
        @endif

        <x-chat.thread
            :chat="$this->activeChat"
            :messages="$this->messages"
        />

        @if ($showMessageSearch && trim($messageSearch) !== '' && $this->messages['total'] > 30)
            <div class="flex items-center justify-center gap-3 border-t border-zinc-200 px-3 py-2 text-xs dark:border-zinc-700" data-test="message-search-pages">
                <flux:button size="sm" variant="ghost" wire:click="changeMessagePage({{ $messagePage - 1 }})" :disabled="$messagePage === 1">{{ __('Назад') }}</flux:button>
                <span>{{ $messagePage }} / {{ (int) ceil($this->messages['total'] / 30) }}</span>
                <flux:button size="sm" variant="ghost" wire:click="changeMessagePage({{ $messagePage + 1 }})" :disabled="$messagePage >= ceil($this->messages['total'] / 30)">{{ __('Далее') }}</flux:button>
            </div>
        @endif

        <x-chat.composer
            :chat="$this->activeChat"
            :show-mention-picker="$showMentionPicker"
            :mention-candidates="$showMentionPicker ? $this->mentionCandidates : []"
            :pending-files="$pendingFiles"
        />
    </main>
    @else
        <main class="flex min-w-0 flex-1 items-center justify-center text-sm text-zinc-500">{{ __('Выберите чат') }}</main>
    @endif

    {{-- Информация о чате --}}
    @if ($showDetails && $this->activeChat !== [])
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
