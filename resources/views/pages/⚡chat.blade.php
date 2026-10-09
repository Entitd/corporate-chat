<?php

use App\Events\ChatRead;
use App\Events\MessageCreated;
use App\Models\Attachment;
use App\Models\Chat;
use App\Models\ChatUser;
use App\Models\Message;
use App\Models\User;
use App\Notifications\ChatMentioned;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    #[Locked]
    public int $visibleMessageCount = 30;

    /** Панель с информацией о чате. */
    public bool $showDetails = false;

    /** На мобильных список чатов и переписка занимают экран по очереди. */
    public bool $showChatList = true;

    /** Модальное окно создания нового чата. */
    public bool $showNewChatModal = false;

    public bool $showInviteModal = false;

    #[Locked]
    public ?int $inviteChatId = null;

    public ?int $selectedInviteeId = null;

    public string $inviteSearch = '';

    public bool $showRemoveParticipantModal = false;

    #[Locked]
    public ?int $participantRemovalChatId = null;

    #[Locked]
    public ?int $participantToRemoveId = null;

    #[Locked]
    public string $participantToRemoveName = '';

    /** Режим создания чата: личный (direct) или групповой (group). */
    public string $newChatMode = 'direct';

    /** Поиск коллег в модальном окне нового чата. */
    public string $colleagueSearch = '';

    /** Выбранные коллеги для нового чата. */
    public array $selectedColleagues = [];

    public string $groupName = '';

    /** Текст нового сообщения в редакторе. */
    public string $messageBody = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $pendingFiles = [];

    /** @var array<int, int> */
    public array $selectedMentionIds = [];

    public bool $showMentionPicker = false;

    public string $mentionSearch = '';

    public bool $showMemberModal = false;

    public ?int $profileMemberId = null;

    public bool $showMessageReadersModal = false;

    #[Locked]
    public ?int $messageReadersMessageId = null;

    #[Locked]
    public ?int $openedReadMessageId = null;

    #[Locked]
    public int $unreadDividerCount = 0;

    public function mount(): void
    {
        $requestedChatId = request()->integer('chat');

        if ($requestedChatId <= 0) {
            return;
        }

        $chat = auth()->user()->chats()->whereKey($requestedChatId)->first();
        abort_unless($chat, 403);

        $this->activeChatId = $chat->id;
        $this->showChatList = false;
        $this->prepareChatOpening($chat);
    }

    /**
     * Открыть чат.
     */
    public function selectChat(int $chatId): void
    {
        if ($chatId < 0) {
            $this->selectColleague(-$chatId);
            $this->newChatMode = 'direct';
            $this->createChat();

            return;
        }

        $chat = auth()->user()->chats()->whereKey($chatId)->first();
        abort_unless($chat, 403);

        $this->activeChatId = $chatId;
        unset($this->chats, $this->unreadTotal, $this->activeChat);
        $this->showChatList = false;
        $this->showDetails = false;
        $this->showRemoveParticipantModal = false;
        $this->participantRemovalChatId = null;
        $this->participantToRemoveId = null;
        $this->participantToRemoveName = '';
        $this->showMemberModal = false;
        $this->profileMemberId = null;
        $this->showMessageReadersModal = false;
        $this->messageReadersMessageId = null;
        $this->showMessageSearch = false;
        $this->messageSearch = '';
        $this->messagePage = 1;
        $this->visibleMessageCount = 30;
        $this->prepareChatOpening($chat);
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
    public function sendMessage(?string $currentBody = null): void
    {
        if ($currentBody !== null) {
            $this->messageBody = $currentBody;
        }

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
        $mentionedUsers = $chat->users()->whereIn('users.id', $mentionIds)->get(['users.id', 'users.name'])->keyBy('id');
        abort_unless($mentionedUsers->count() === count($mentionIds), 403);
        $mentionCounts = [];
        $mentionIds = array_values(array_filter($mentionIds, function (int $userId) use ($mentionedUsers, $body, &$mentionCounts): bool {
            if ($userId === auth()->id()) {
                return false;
            }

            $token = '@'.$mentionedUsers[$userId]->name;
            $mentionCounts[$token] ??= preg_match_all('/(?<![\p{L}\p{N}_])'.preg_quote($token, '/').'(?![\p{L}\p{N}_])/u', $body) ?: 0;

            if ($mentionCounts[$token] === 0) {
                return false;
            }

            $mentionCounts[$token]--;

            return true;
        }));

        $storedPaths = [];

        try {
            $message = DB::transaction(function () use ($chat, $body, $mentionIds, $mentionedUsers, &$storedPaths): Message {
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

                foreach ($mentionIds as $userId) {
                    $recipient = $mentionedUsers[$userId];
                    $recipient->notify(new ChatMentioned(
                        $chat->id,
                        $message->id,
                        $chat->type === 'group' ? ($chat->name ?: __('Групповой чат')) : auth()->user()->name,
                        auth()->user()->name,
                        Str::limit($body, 120),
                    ));
                }

                return $message;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);

            throw $exception;
        }

        $this->markChatAsRead($chat);
        unset($this->chats, $this->unreadTotal, $this->activeChat);
        MessageCreated::dispatch(
            $chat->id,
            $message->id,
            $chat->users()->pluck('users.id')->all(),
        );

        $this->reset('messageBody', 'pendingFiles', 'selectedMentionIds', 'showMentionPicker', 'mentionSearch');
        $this->showMessageSearch = false;
        $this->messageSearch = '';
        $this->messagePage = 1;
        $this->visibleMessageCount = 30;
        unset($this->messages, $this->sharedFiles, $this->sharedLinks);

        $this->dispatch('message-sent');
    }

    /** @return array<string, string> */
    protected function getListeners(): array
    {
        return [
            'echo-private:users.'.auth()->id().',.chat.message.created' => 'refreshFromBroadcast',
            'echo-private:users.'.auth()->id().',.chat.read' => 'refreshReadStatus',
        ];
    }

    /** @param array{chatId?: int} $event */
    public function refreshReadStatus(array $event): void
    {
        $chatId = (int) ($event['chatId'] ?? 0);

        if ($chatId !== $this->activeChatId || ! auth()->user()->chats()->whereKey($chatId)->exists()) {
            return;
        }

        unset($this->messages, $this->messageReaders);
    }

    public function refreshOpenChat(): void
    {
        if ($this->activeChatId <= 0) {
            return;
        }

        $chat = auth()->user()->chats()->whereKey($this->activeChatId)->first();
        abort_unless($chat, 403);

        unset($this->chats, $this->unreadTotal, $this->messages, $this->messageReaders);
    }

    /** @param array{chatId?: int, messageId?: int} $event */
    public function refreshFromBroadcast(array $event): void
    {
        $chatId = (int) ($event['chatId'] ?? 0);

        $chat = auth()->user()->chats()->whereKey($chatId)->first();
        $message = $chat?->messages()->with('user')->find((int) ($event['messageId'] ?? 0));

        if (! $message) {
            return;
        }

        unset($this->chats, $this->unreadTotal, $this->mentionNotifications);

        if ($chatId === $this->activeChatId) {
            unset($this->activeChat, $this->messages, $this->sharedFiles, $this->sharedLinks);

            if ($message->user_id !== auth()->id()) {
                $this->unreadDividerCount++;
            }
        }

        if ($message->user_id !== auth()->id()) {
            $this->dispatch('incoming-chat-message',
                chatId: $chatId,
                messageId: $message->id,
                author: $message->user->name,
                chat: $chat->type === 'group' ? ($chat->name ?: __('Групповой чат')) : $message->user->name,
                body: Str::limit($message->body ?: __('Вложение'), 120),
            );
        }
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

    public function closeMentionPicker(): void
    {
        $this->showMentionPicker = false;
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

    public function showMentionProfile(int $userId): void
    {
        abort_unless($this->activeChatId && auth()->user()->chats()->whereKey($this->activeChatId)->exists(), 403);
        abort_unless(Chat::findOrFail($this->activeChatId)->users()->whereKey($userId)->exists(), 404);

        $this->profileMemberId = $userId;
        $this->showMemberModal = true;
    }

    /** @return array{id: int, name: string, title: string|null}|array{} */
    #[Computed]
    public function profileMember(): array
    {
        if (! $this->profileMemberId || ! $this->showMemberModal) {
            return [];
        }

        $this->activeChat;
        $member = Chat::findOrFail($this->activeChatId)->users()->whereKey($this->profileMemberId)->firstOrFail();

        return ['id' => $member->id, 'name' => $member->name, 'title' => $member->title];
    }

    public function showMessageReaders(int $messageId): void
    {
        $this->ownGroupMessage($messageId);

        $this->messageReadersMessageId = $messageId;
        $this->showMessageReadersModal = true;
    }

    /** @return array{read: array<int, array{id: int, name: string}>, unread: array<int, array{id: int, name: string}>} */
    #[Computed]
    public function messageReaders(): array
    {
        if (! $this->showMessageReadersModal || $this->messageReadersMessageId === null) {
            return ['read' => [], 'unread' => []];
        }

        $message = $this->ownGroupMessage($this->messageReadersMessageId);
        $participants = $message->chat->users()
            ->withPivot('last_read_message_id')
            ->whereKeyNot(auth()->id())
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);

        $readers = ['read' => [], 'unread' => []];

        foreach ($participants as $participant) {
            $status = $participant->pivot->last_read_message_id !== null
                && $participant->pivot->last_read_message_id >= $message->id ? 'read' : 'unread';

            $readers[$status][] = ['id' => $participant->id, 'name' => $participant->name];
        }

        return $readers;
    }

    private function ownGroupMessage(int $messageId): Message
    {
        $chat = auth()->user()->chats()->whereKey($this->activeChatId)->where('type', 'group')->first();
        abort_unless($chat, 403);

        $message = $chat->messages()->findOrFail($messageId);
        abort_unless($message->user_id === auth()->id(), 403);

        return $message;
    }

    /** @return array<int, array{id: string, chat_id: int, message_id: int, author_name: string, chat_name: string, excerpt: string, read: bool}> */
    #[Computed]
    public function mentionNotifications(): array
    {
        $chatIds = auth()->user()->chats()->pluck('chats.id')->all();

        return auth()->user()->notifications()
            ->where('type', ChatMentioned::class)
            ->latest()
            ->limit(20)
            ->get()
            ->filter(fn ($notification): bool => in_array((int) ($notification->data['chat_id'] ?? 0), $chatIds, true))
            ->map(fn ($notification): array => [
                'id' => $notification->id,
                'chat_id' => (int) $notification->data['chat_id'],
                'message_id' => (int) $notification->data['message_id'],
                'author_name' => $notification->data['author_name'],
                'chat_name' => $notification->data['chat_name'],
                'excerpt' => $notification->data['excerpt'],
                'read' => $notification->read_at !== null,
            ])
            ->values()
            ->all();
    }

    public function openMentionNotification(string $notificationId): void
    {
        $notification = auth()->user()->notifications()
            ->whereKey($notificationId)
            ->where('type', ChatMentioned::class)
            ->firstOrFail();

        $chat = auth()->user()->chats()->whereKey((int) ($notification->data['chat_id'] ?? 0))->firstOrFail();
        $message = $chat->messages()->whereKey((int) ($notification->data['message_id'] ?? 0))
            ->whereHas('mentions', fn ($users) => $users->whereKey(auth()->id()))
            ->firstOrFail();

        $notification->markAsRead();
        $this->selectChat($chat->id);

        $newerMessages = $chat->messages()
            ->where(function ($query) use ($message): void {
                $query->where('created_at', '>', $message->created_at)
                    ->orWhere(function ($sameTime) use ($message): void {
                        $sameTime->where('created_at', $message->created_at)->where('id', '>', $message->id);
                    });
            })
            ->count();
        $this->visibleMessageCount = max(30, (int) ceil(($newerMessages + 1) / 30) * 30);
        unset($this->mentionNotifications, $this->messages);
        $this->dispatch('focus-chat-message', id: $message->id);
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

    public function openInviteModal(int $chatId): void
    {
        auth()->user()->chats()->whereKey($chatId)->where('type', 'group')->firstOrFail();

        $this->inviteChatId = $chatId;
        $this->selectedInviteeId = null;
        $this->inviteSearch = '';
        $this->resetValidation();
        unset($this->invitableColleagues);
        $this->showInviteModal = true;
    }

    public function inviteColleague(): void
    {
        $chat = auth()->user()->chats()
            ->whereKey($this->inviteChatId)
            ->where('type', 'group')
            ->firstOrFail();

        $validated = $this->validate([
            'selectedInviteeId' => ['required', 'integer', 'exists:users,id', Rule::notIn([auth()->id()])],
        ]);

        $inviteeId = (int) $validated['selectedInviteeId'];

        if ($chat->users()->whereKey($inviteeId)->exists()) {
            $this->addError('selectedInviteeId', __('Коллега уже состоит в чате.'));

            return;
        }

        $chat->users()->syncWithoutDetaching([
            $inviteeId => ['last_read_message_id' => $chat->messages()->max('id')],
        ]);

        $this->showInviteModal = false;
        $this->selectedInviteeId = null;
        $this->inviteChatId = null;
        unset($this->participants, $this->invitableColleagues);
    }

    public function confirmRemoveParticipant(int $userId): void
    {
        $chat = $this->ownedGroup($this->activeChatId);
        $participant = $chat->users()
            ->whereKey($userId)
            ->wherePivot('role', 'member')
            ->firstOrFail();

        $this->participantRemovalChatId = $chat->id;
        $this->participantToRemoveId = $participant->id;
        $this->participantToRemoveName = $participant->name;
        $this->showRemoveParticipantModal = true;
    }

    public function removeParticipant(): void
    {
        abort_unless($this->showRemoveParticipantModal, 404);

        $chat = $this->ownedGroup($this->participantRemovalChatId ?? 0);

        $chat->users()
            ->whereKey($this->participantToRemoveId)
            ->wherePivot('role', 'member')
            ->firstOrFail();

        $chat->users()->detach($this->participantToRemoveId);

        $this->showRemoveParticipantModal = false;
        $this->participantRemovalChatId = null;
        $this->participantToRemoveId = null;
        $this->participantToRemoveName = '';
        unset($this->participants, $this->invitableColleagues);
    }

    private function ownedGroup(int $chatId): Chat
    {
        return auth()->user()->chats()
            ->whereKey($chatId)
            ->where('type', 'group')
            ->wherePivot('role', 'admin')
            ->firstOrFail();
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

    public function loadOlderMessages(): void
    {
        abort_unless($this->activeChatId && auth()->user()->chats()->whereKey($this->activeChatId)->exists(), 403);

        $this->visibleMessageCount += 30;
        unset($this->messages);
        $this->dispatch('older-messages-loaded');
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
        $this->resetValidation();
    }

    public function selectColleague(int $userId): void
    {
        abort_unless(User::query()->whereKey($userId)->whereKeyNot(auth()->id())->exists(), 404);

        $this->selectedColleagues = [$userId];
    }

    public function createChat(): void
    {
        $this->groupName = trim($this->groupName);

        $rules = [
            'newChatMode' => ['required', 'in:direct,group'],
            'selectedColleagues' => ['required', 'array', $this->newChatMode === 'direct' ? 'size:1' : 'min:1'],
            'selectedColleagues.*' => ['required', 'integer', 'distinct', 'exists:users,id', Rule::notIn([auth()->id()])],
        ];

        if ($this->newChatMode === 'group') {
            $rules['groupName'] = ['required', 'string', 'max:100'];
        }

        $validated = $this->validate($rules);
        $colleagueIds = array_map('intval', $validated['selectedColleagues']);

        $chat = DB::transaction(function () use ($colleagueIds, $validated): Chat {
            if ($this->newChatMode === 'direct') {
                $existingChat = Chat::query()
                    ->where('type', 'direct')
                    ->whereHas('users', fn ($users) => $users->whereKey(auth()->id()))
                    ->whereHas('users', fn ($users) => $users->whereKey($colleagueIds[0]))
                    ->has('users', '=', 2)
                    ->first();

                if ($existingChat) {
                    return $existingChat;
                }
            }

            $chat = Chat::create([
                'type' => $this->newChatMode,
                'name' => $this->newChatMode === 'group' ? trim($validated['groupName']) : null,
            ]);

            $chat->users()->attach(auth()->id(), ['role' => $this->newChatMode === 'group' ? 'admin' : 'member']);
            $chat->users()->attach($colleagueIds);

            return $chat;
        });

        unset($this->chats, $this->activeChat, $this->directCount, $this->groupCount);
        $this->selectChat($chat->id);
        $this->chatFilter = 'all';
        $this->reset('showNewChatModal', 'newChatMode', 'colleagueSearch', 'selectedColleagues', 'groupName');
    }

    public function leaveChat(int $chatId): void
    {
        $chat = auth()->user()->chats()->whereKey($chatId)->where('type', 'group')->firstOrFail();

        $chat->users()->detach(auth()->id());
        $this->chatFilter = 'all';

        if ($this->activeChatId === $chatId) {
            $this->activeChatId = auth()->user()->chats()->orderBy('chats.id')->value('chats.id') ?? 0;
            $this->showChatList = true;
            $this->showDetails = false;
            $this->showMessageSearch = false;
            $this->messageSearch = '';
            $this->messagePage = 1;
            $this->visibleMessageCount = 30;
            $this->reset('messageBody', 'pendingFiles', 'selectedMentionIds', 'showMentionPicker', 'mentionSearch');
        }

        unset($this->chats, $this->activeChat, $this->messages, $this->participants, $this->sharedFiles, $this->sharedLinks, $this->mentionNotifications, $this->groupCount);
    }

    /**
     * Отметить все чаты прочитанными.
     */
    public function markAllAsRead(): void
    {
        foreach (auth()->user()->chats()->withMax('messages', 'id')->get() as $chat) {
            $this->markChatAsRead($chat, $chat->messages_max_id);
        }

        unset($this->chats, $this->unreadTotal);
    }

    public function markOpenChatAsRead(int $messageId): void
    {
        $chat = auth()->user()->chats()->whereKey($this->activeChatId)->first();
        abort_unless($chat, 403);
        abort_unless($chat->messages()->whereKey($messageId)->exists(), 404);

        $this->markChatAsRead($chat, $messageId);
        unset($this->chats, $this->unreadTotal, $this->activeChat);

        $this->dispatch('chat-read-through');
    }

    private function prepareChatOpening(Chat $chat): void
    {
        $readMessageId = DB::table('chat_users')
            ->where('chat_id', $chat->id)
            ->where('user_id', auth()->id())
            ->value('last_read_message_id');

        $this->openedReadMessageId = $readMessageId === null ? null : (int) $readMessageId;
        $this->unreadDividerCount = $chat->messages()
            ->where('user_id', '!=', auth()->id())
            ->where('id', '>', $this->openedReadMessageId ?? 0)
            ->count();

        if ($this->openedReadMessageId !== null) {
            $newerMessageCount = $chat->messages()->where('id', '>', $this->openedReadMessageId)->count();
            $this->visibleMessageCount = max($this->visibleMessageCount, $newerMessageCount + 10);
        }
    }

    private function markChatAsRead(Chat $chat, ?int $latestMessageId = null): void
    {
        $latestMessageId ??= $chat->messages()->max('id');

        if ($latestMessageId === null) {
            return;
        }

        $updated = DB::table('chat_users')
            ->where('chat_id', $chat->id)
            ->where('user_id', auth()->id())
            ->where(function ($query) use ($latestMessageId): void {
                $query->whereNull('last_read_message_id')
                    ->orWhere('last_read_message_id', '<', $latestMessageId);
            })
            ->update(['last_read_message_id' => $latestMessageId]);

        if ($updated === 0) {
            return;
        }

        $recipientIds = $chat->users()->where('users.id', '!=', auth()->id())->pluck('users.id')->all();

        if ($recipientIds !== []) {
            ChatRead::dispatch($chat->id, $recipientIds);
        }
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
        return User::query()
            ->whereKeyNot(auth()->id())
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
        $readPositions = collect();

        if (collect($messages)->contains(fn (array $message): bool => $message['user_id'] === auth()->id())) {
            $readPositions = DB::table('chat_users')
                ->where('chat_id', $this->activeChatId)
                ->where('user_id', '!=', auth()->id())
                ->pluck('last_read_message_id');
        }

        $readThroughMessageId = $readPositions->isNotEmpty() && $readPositions->every(fn ($position): bool => $position !== null)
            ? (int) $readPositions->min()
            : null;

        $previous = null;

        $processedMessages = array_map(function (array $message) use (&$previous, $readThroughMessageId): array {
            // 1. Безопасно вытаскиваем день из created_at (если null — ставим текущую дату)
            $currentDay = $message['created_at']
                ? date('Y-m-d', strtotime($message['created_at']))
                : date('Y-m-d');

            // 2. Вычисляем, принадлежит ли сообщение текущему пользователю
            $isOwn = isset($message['user_id']) && $message['user_id'] === auth()->id();

            // Добавляем флаг own в массив сообщения
            $message['own'] = $isOwn;
            $message['status'] = $isOwn && $readThroughMessageId !== null && $message['id'] <= $readThroughMessageId ? 'read' : 'sent';
            $message['body_segments'] = $this->messageSegments($message);

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
            ->withPivot('role')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.title'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'position' => $user->title ?: __('Сотрудник'),
                'role' => $user->pivot->role,
            ])
            ->all();
    }

    #[Computed]
    public function canRemoveParticipants(): bool
    {
        return ($this->activeChat['type'] ?? null) === 'group'
            && collect($this->participants)->contains(
                fn (array $participant): bool => $participant['id'] === auth()->id() && $participant['role'] === 'admin',
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
            User::query()->where('id', '!=', auth()->id())->get(['id', 'name', 'title'])->toArray(),
            fn (array $colleague): bool => $search === ''
                || str_contains(mb_strtolower($colleague['name']), $search)
                || str_contains(mb_strtolower($colleague['title'] ?? ''), $search),
        ));
    }

    /** @return array<int, array{id: int, name: string, title: string|null}> */
    #[Computed]
    public function invitableColleagues(): array
    {
        if (! $this->showInviteModal || $this->inviteChatId === null) {
            return [];
        }

        $search = trim($this->inviteSearch);

        return User::query()
            ->whereDoesntHave('chats', fn ($chats) => $chats->where('chats.id', $this->inviteChatId))
            ->when($search !== '', fn ($users) => $users->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('title', 'like', '%'.$search.'%');
            }))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'title'])
            ->toArray();
    }

    /**
     * Список чатов (демо-данные).
     *
     * @return array<int, array<string, mixed>>
     */
    private function demoChats(): array
    {
        $chats = auth()->user()->chats()
            ->withPivot('last_read_message_id')
            ->with(['users:id,name', 'latestMessage.user:id,name'])
            ->withCount(['messages as unread_count' => function ($query): void {
                $query->where('messages.user_id', '!=', auth()->id())
                    ->where(function ($unread): void {
                        $unread->whereNull('chat_users.last_read_message_id')
                            ->orWhereColumn('messages.id', '>', 'chat_users.last_read_message_id');
                    });
            }])
            ->get()
            ->sortByDesc(fn (Chat $chat): int => $chat->latestMessage?->id ?? 0)
            ->map(function (Chat $chat): array {
                $interlocutor = $chat->type === 'direct'
                    ? $chat->users->firstWhere('id', '!=', auth()->id())
                    : null;
                $latestMessage = $chat->latestMessage;
                $chatData = $chat->attributesToArray();

                $chatData['name'] = $chat->type === 'direct'
                    ? ($interlocutor?->name ?? __('Пустой чат'))
                    : ($chat->name ?: __('Групповой чат'));
                $chatData['unread'] = $chat->unread_count;
                $chatData['interlocutor_id'] = $interlocutor?->id;
                $chatData['last_message'] = $latestMessage ? [
                    'text' => $latestMessage->body ?: __('Вложение'),
                    'author' => $latestMessage->user_id === auth()->id() ? __('Вы') : $latestMessage->user?->name,
                    'sent_at' => $latestMessage->created_at->toIso8601String(),
                    'time' => $latestMessage->created_at->isToday()
                        ? $latestMessage->created_at->format('H:i')
                        : $latestMessage->created_at->format('d.m'),
                ] : null;

                return $chatData;
            })
            ->values();

        $colleagues = User::query()
            ->whereKeyNot(auth()->id())
            ->whereNotIn('id', $chats->pluck('interlocutor_id')->filter()->all())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $colleague): array => [
                'id' => -$colleague->id,
                'type' => 'direct',
                'name' => $colleague->name,
                'interlocutor_id' => $colleague->id,
                'unread' => 0,
                'last_message' => null,
            ]);

        return $chats->whereNotNull('last_message')
            ->concat($chats->whereNull('last_message')->concat($colleagues)
                ->sortBy(fn (array $chat): string => mb_strtolower($chat['name'])))
            ->values()
            ->all();
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
            ->with(['user:id,name', 'attachments:id,message_id,file_name,file_type,file_size', 'mentions:id,name']);
        $search = trim($this->messageSearch);

        if ($search === '') {
            return [
                'data' => $query->latest('created_at')->orderByDesc('id')
                    ->limit($this->visibleMessageCount)
                    ->get()->reverse()->values()->map->toArray()->all(),
                'total' => $chat->messages()->count(),
            ];
        }

        $matches = [];
        $total = 0;

        foreach ($query->oldest()->orderBy('id')->lazy(200) as $message) {
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
     * Разбить текст на обычные фрагменты и проверенные упоминания.
     *
     * @param  array<string, mixed>  $message
     * @return array<int, array{text: string, user_id: int|null}>
     */
    private function messageSegments(array $message): array
    {
        $body = $message['body'] ?? '';
        $mentionIdsByToken = [];

        foreach ($message['mentions'] ?? [] as $user) {
            $mentionIdsByToken['@'.$user['name']][] = $user['id'];
        }

        if ($body === '' || $mentionIdsByToken === []) {
            return [['text' => $body, 'user_id' => null]];
        }

        $tokens = collect(array_keys($mentionIdsByToken))->sortByDesc(fn (string $token): int => mb_strlen($token))
            ->map(fn (string $token): string => preg_quote($token, '/'))
            ->implode('|');
        $parts = preg_split('/(?<![\p{L}\p{N}_])('.$tokens.')(?![\p{L}\p{N}_])/u', $body, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $segments = [];

        foreach ($parts ?: [$body] as $part) {
            $segments[] = [
                'text' => $part,
                'user_id' => isset($mentionIdsByToken[$part]) ? array_shift($mentionIdsByToken[$part]) : null,
            ];
        }

        return $segments;
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

<div
    class="flex h-dvh w-full overflow-hidden bg-white dark:bg-zinc-800"
    x-bind:style="chatViewportStyle()"
    x-data="{
        viewportHeight: window.visualViewport?.height ?? window.innerHeight,
        viewportOffsetTop: window.visualViewport?.offsetTop ?? 0,
        viewportChangeHandler: null,
        init() {
            this.viewportChangeHandler = () => {
                this.viewportHeight = window.visualViewport?.height ?? window.innerHeight;
                this.viewportOffsetTop = window.visualViewport?.offsetTop ?? 0;
            };
            window.addEventListener('resize', this.viewportChangeHandler);
            window.visualViewport?.addEventListener('resize', this.viewportChangeHandler);
            window.visualViewport?.addEventListener('scroll', this.viewportChangeHandler);
        },
        destroy() {
            window.removeEventListener('resize', this.viewportChangeHandler);
            window.visualViewport?.removeEventListener('resize', this.viewportChangeHandler);
            window.visualViewport?.removeEventListener('scroll', this.viewportChangeHandler);
        },
        chatViewportStyle() {
            return window.innerWidth < 1024
                ? `position: fixed; inset-inline: 0; top: ${this.viewportOffsetTop}px; height: ${this.viewportHeight}px`
                : '';
        },
        dragDepth: 0,
        draggingFiles: false,
        dropError: '',
        dropErrorTimeout: null,
        soundEnabled: localStorage.getItem('chat-sound-enabled') !== 'false',
        soundContext: null,
        incomingNotification: null,
        notificationTimeout: null,
        formatChatTime(instant) {
            return new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(instant));
        },
        formatChatDate(instant) {
            return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(instant));
        },
        sameLocalDay(first, second) {
            if (!first || !second) return false;
            const firstDate = new Date(first);
            const secondDate = new Date(second);
            return firstDate.getFullYear() === secondDate.getFullYear()
                && firstDate.getMonth() === secondDate.getMonth()
                && firstDate.getDate() === secondDate.getDate();
        },
        formatChatListTime(instant) {
            return this.sameLocalDay(instant, new Date())
                ? this.formatChatTime(instant)
                : new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit' }).format(new Date(instant));
        },
        chatSwipeStart: null,
        startChatSwipe(event) {
            this.chatSwipeStart = null;

            if (window.innerWidth >= 1024 || $wire.showChatList || event.touches.length !== 1 || event.target.closest('input, textarea, select, [contenteditable]')) return;

            const touch = event.touches[0];
            this.chatSwipeStart = { identifier: touch.identifier, x: touch.clientX, y: touch.clientY };
        },
        endChatSwipe(event) {
            const start = this.chatSwipeStart;
            this.chatSwipeStart = null;

            if (!start || window.innerWidth >= 1024 || $wire.showChatList) return;

            const touch = Array.from(event.changedTouches).find(touch => touch.identifier === start.identifier);
            if (!touch) return;

            const distanceX = touch.clientX - start.x;
            const distanceY = Math.abs(touch.clientY - start.y);

            if (distanceX >= 90 && distanceX > distanceY * 1.5) $wire.backToList();
        },
        unlockSound() {
            if (!this.soundEnabled || !window.AudioContext) return;
            this.soundContext ??= new AudioContext();
            if (this.soundContext.state === 'suspended') this.soundContext.resume().catch(() => {});
        },
        toggleSound() {
            this.soundEnabled = !this.soundEnabled;
            localStorage.setItem('chat-sound-enabled', String(this.soundEnabled));
            if (this.soundEnabled) this.unlockSound();
        },
        playNotificationSound() {
            if (!this.soundEnabled) return;
            this.unlockSound();
            if (this.soundContext?.state !== 'running') return;

            const start = this.soundContext.currentTime;
            for (const [frequency, delay] of [[660, 0], [880, 0.13]]) {
                const oscillator = this.soundContext.createOscillator();
                const gain = this.soundContext.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.value = frequency;
                gain.gain.setValueAtTime(0.0001, start + delay);
                gain.gain.exponentialRampToValueAtTime(0.12, start + delay + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + delay + 0.18);
                oscillator.connect(gain);
                gain.connect(this.soundContext.destination);
                oscillator.start(start + delay);
                oscillator.stop(start + delay + 0.18);
            }
        },
        notifyIncomingMessage(message) {
            this.incomingNotification = message;
            clearTimeout(this.notificationTimeout);
            this.notificationTimeout = setTimeout(() => this.incomingNotification = null, 6000);
            this.playNotificationSound();
        },
        hasDraggedFiles(event) {
            return Array.from(event.dataTransfer?.types ?? []).includes('Files');
        },
        canAttachFiles() {
            return $wire.activeChatId > 0 && (window.innerWidth >= 1024 || !$wire.showChatList);
        },
        showDropError(message) {
            this.dropError = message;
            clearTimeout(this.dropErrorTimeout);
            this.dropErrorTimeout = setTimeout(() => this.dropError = '', 5000);
        },
        dropFiles(event) {
            if (!this.hasDraggedFiles(event)) return;

            event.preventDefault();
            this.dragDepth = 0;
            this.draggingFiles = false;

            if (!this.canAttachFiles()) {
                this.showDropError(@js(__('Сначала откройте чат, в который хотите отправить файлы.')));
                return;
            }

            const files = Array.from(event.dataTransfer.files);
            if (files.length === 0) return;

            if (($wire.pendingFiles?.length ?? 0) + files.length > 3) {
                this.showDropError(@js(__('Можно прикрепить не больше 3 файлов.')));
                return;
            }

            if (files.some(file => file.size > 2 * 1024 * 1024)) {
                this.showDropError(@js(__('Файл должен быть не больше 2 МБ.')));
                return;
            }

            const input = this.$el.querySelector('[data-test=chat-file-input]');
            if (!input) return;

            this.dropError = '';
            input.files = event.dataTransfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        },
    }"
    x-on:dragenter="if (hasDraggedFiles($event)) { $event.preventDefault(); dragDepth++; draggingFiles = true }"
    x-on:dragover="if (hasDraggedFiles($event)) { $event.preventDefault(); $event.dataTransfer.dropEffect = 'copy' }"
    x-on:dragleave="if (hasDraggedFiles($event)) { dragDepth = Math.max(0, dragDepth - 1); draggingFiles = dragDepth > 0 }"
    x-on:drop="dropFiles($event)"
    x-on:pointerdown.once="unlockSound()"
    x-on:keydown.once="unlockSound()"
    x-on:incoming-chat-message.window="notifyIncomingMessage($event.detail)"
>
    <button
        x-show="incomingNotification"
        x-cloak
        type="button"
        style="display: none"
        aria-live="polite"
        class="fixed inset-x-4 top-4 z-50 mx-auto flex w-auto max-w-sm flex-col gap-1 rounded-xl border border-zinc-200 bg-white px-4 py-3 text-start shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
        x-on:click="$wire.selectChat(incomingNotification.chatId); incomingNotification = null"
        data-test="incoming-message-notification"
    >
        <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="incomingNotification?.chat"></span>
        <span class="truncate text-xs text-zinc-500 dark:text-zinc-400" x-text="incomingNotification?.author + ': ' + incomingNotification?.body"></span>
    </button>
    <div
        x-show="draggingFiles"
        x-cloak
        style="display: none"
        class="pointer-events-none fixed inset-0 z-50 flex items-center justify-center bg-sky-500/15 p-4 backdrop-blur-[2px] dark:bg-sky-400/15"
        data-test="chat-file-drop-overlay"
    >
        <div class="flex w-full max-w-md flex-col items-center gap-3 rounded-2xl border-2 border-dashed border-sky-500 bg-white/95 px-6 py-10 text-center shadow-xl dark:border-sky-400 dark:bg-zinc-900/95">
            <flux:icon.arrow-up-tray class="size-10 text-sky-600 dark:text-sky-400" />
            <p class="text-lg font-semibold text-zinc-900 dark:text-white" x-text="canAttachFiles() ? @js(__('Перетащите файлы сюда')) : @js(__('Сначала откройте чат'))"></p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('До 3 файлов по 2 МБ · после загрузки нажмите «Отправить»') }}</p>
        </div>
    </div>

    <div x-show="dropError" x-cloak style="display: none" role="alert" x-text="dropError" class="fixed inset-x-4 bottom-6 z-50 mx-auto w-fit max-w-md rounded-xl bg-red-600 px-4 py-3 text-center text-sm font-medium text-white shadow-lg" data-test="chat-file-drop-error"></div>

    {{-- Список чатов --}}
    <aside class="{{ $showChatList ? 'flex w-full' : 'hidden' }} shrink-0 flex-col border-e border-zinc-200 bg-white lg:flex lg:w-80 lg:bg-zinc-50 xl:w-[22rem] dark:border-zinc-700 dark:bg-zinc-900">
        <x-chat.sidebar
            :chats="$this->chats"
            :active-chat-id="$activeChatId"
            :chat-filter="$chatFilter"
            :unread-total="$this->unreadTotal"
            :direct-count="$this->directCount"
            :group-count="$this->groupCount"
            :mention-notifications="$this->mentionNotifications"
        />
    </aside>

    {{-- Переписка --}}
    @if ($this->activeChat !== [])
    <main
        class="{{ $showChatList ? 'hidden' : 'flex' }} min-w-0 flex-1 flex-col lg:flex"
        wire:poll.10s="refreshOpenChat"
        x-on:touchstart.passive="startChatSwipe($event)"
        x-on:touchend.passive="endChatSwipe($event)"
        x-on:touchcancel.passive="chatSwipeStart = null"
    >
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

        <div class="relative flex min-h-0 flex-1 flex-col">
            <x-chat.thread
                :chat="$this->activeChat"
                :messages="$this->messages"
                :show-message-search="$showMessageSearch"
                :last-read-message-id="$openedReadMessageId"
                :has-unread="$this->activeChat['unread'] > 0"
                :unread-divider-count="$unreadDividerCount"
            />

            @if (! $showMessageSearch && $this->activeChat['unread'] > 0 && $this->messages['data'] !== [])
                <button
                    type="button"
                    wire:click="markOpenChatAsRead({{ $this->messages['data'][array_key_last($this->messages['data'])]['id'] }})"
                    class="absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 items-center gap-2 whitespace-nowrap rounded-full bg-sky-600 px-4 py-2 text-sm font-medium text-white shadow-lg shadow-sky-950/20 transition hover:bg-sky-700 dark:bg-sky-500 dark:text-zinc-950 dark:hover:bg-sky-400"
                    data-test="unread-messages-button"
                >
                    <span>{{ __('Новые сообщения ниже') }}</span>
                    <flux:icon.arrow-down class="size-4" />
                </button>
            @endif
        </div>

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
            :can-remove-participants="$this->canRemoveParticipants"
            :files="$this->sharedFiles"
            :links="$this->sharedLinks"
        />
    @endif

    <x-chat.new-chat-modal
        :colleagues="$this->colleagues"
        :mode="$newChatMode"
        :selected="$selectedColleagues"
    />

    <x-chat.invite-colleague-modal :colleagues="$this->invitableColleagues" :selected-invitee-id="$selectedInviteeId" :show="$showInviteModal" />

    <flux:modal wire:model="showMessageReadersModal" class="md:w-96" data-test="message-readers-modal">
        @if ($showMessageReadersModal)
            @php($messageReaders = $this->messageReaders)
            <div class="space-y-5">
                <flux:heading size="lg">{{ __('Прочтение сообщения') }}</flux:heading>

                <div class="max-h-72 space-y-5 overflow-y-auto">
                    <section data-test="message-readers-read">
                        <h3 class="mb-2 text-xs font-semibold text-green-700 dark:text-green-400">{{ __('Прочитали') }} · {{ count($messageReaders['read']) }}</h3>
                        @forelse ($messageReaders['read'] as $reader)
                            <div class="flex items-center gap-2 py-1.5" wire:key="reader-read-{{ $reader['id'] }}">
                                <flux:avatar :name="$reader['name']" color="auto" size="xs" />
                                <span class="text-sm text-zinc-900 dark:text-white">{{ $reader['name'] }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Пока никто не прочитал') }}</p>
                        @endforelse
                    </section>

                    <section data-test="message-readers-unread">
                        <h3 class="mb-2 text-xs font-semibold text-zinc-500 dark:text-zinc-400">{{ __('Ещё не прочитали') }} · {{ count($messageReaders['unread']) }}</h3>
                        @foreach ($messageReaders['unread'] as $reader)
                            <div class="flex items-center gap-2 py-1.5" wire:key="reader-unread-{{ $reader['id'] }}">
                                <flux:avatar :name="$reader['name']" color="auto" size="xs" />
                                <span class="text-sm text-zinc-900 dark:text-white">{{ $reader['name'] }}</span>
                            </div>
                        @endforeach
                    </section>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal wire:model="showRemoveParticipantModal" class="md:w-96" data-test="remove-participant-modal">
        @if ($showRemoveParticipantModal)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Удалить участника?') }}</flux:heading>
                    <flux:subheading>{{ __(':name больше не сможет читать сообщения этой группы.', ['name' => $participantToRemoveName]) }}</flux:subheading>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Отмена') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="removeParticipant" data-test="confirm-remove-participant">
                        {{ __('Удалить') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal wire:model="showMemberModal" class="md:w-80" data-test="mention-profile-modal">
        @if ($showMemberModal && $this->profileMember !== [])
            <div class="flex flex-col items-center gap-3 py-3 text-center">
                <flux:avatar :name="$this->profileMember['name']" color="auto" size="lg" />
                <flux:heading size="lg">{{ $this->profileMember['name'] }}</flux:heading>
                @if ($this->profileMember['title'])
                    <flux:text>{{ $this->profileMember['title'] }}</flux:text>
                @endif
                <a href="{{ route('chat.members.show', ['chat' => $activeChatId, 'user' => $this->profileMember['id']]) }}" class="mt-2 text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">{{ __('Открыть профиль') }}</a>
            </div>
        @endif
    </flux:modal>
</div>
