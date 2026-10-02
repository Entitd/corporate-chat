<?php 

namespace App\Livewire\Chat;

use App\Models\Chat;
use App\Models\User;
use Livewire\Component;

class NewChatModal extends Component
{
    public bool $showNewChatModal = false;
    public string $mode = 'direct'; // 'direct' или 'group'
    public string $colleagueSearch = '';
    public array $selectedColleagues = []; // Сюда падают ID или имена выбранных коллег
    public string $groupName = ''; // Если захотите добавить поле ввода названия группы

    // Метод переключения режимов (вызывается по кнопкам Личный чат / Группа)
    public function setNewChatMode(string $mode): void
    {
        $this->mode = $mode;
        $this->selectedColleagues = []; // Сбрасываем выбор при переключении
    }

    // Метод создания чата (привязан к кнопке "Написать сообщение" / "Создать группу")
    public function createChat()
    {
        $currentUserId = auth()->id();

        if ($this->mode === 'direct') {
            // Для личного чата у нас выбран ровно 1 коллега (его ID)
            $targetUserId = reset($this->selectedColleagues);
            
            if (!$targetUserId) {
                return;
            }

            // Ищем существующий приватный чат между этими двумя пользователями, 
            // чтобы не плодить дубликаты
            $chat = Chat::where('type', 'private')
                ->whereHas('users', fn($q) => $q->where('user_id', $currentUserId))
                ->whereHas('users', fn($q) => $q->where('user_id', $targetUserId))
                ->first();

            // Если чата еще нет — создаем новый
            if (!$chat) {
                $chat = Chat::create(['type' => 'private']);
                
                // Привязываем обоих пользователей через таблицу chat_user
                $chat->users()->attach([$currentUserId, $targetUserId]);
            }

        } else {
            // Создание группового чата
            if (empty($this->selectedColleagues)) {
                return;
            }

            // Создаем групповой чат
            $chat = Chat::create([
                'type' => 'group',
                'name' => $this->groupName ?: 'Групповой чат', // Название группы
            ]);

            // Собираем всех выбранных участников + текущего пользователя (он создатель/админ)
            $participantIds = array_merge($this->selectedColleagues, [$currentUserId]);
            
            // Привязываем пользователей. Создателя можно сделать 'admin'
            $usersData = [];
            foreach ($participantIds as $id) {
                $usersData[$id] = ['role' => ($id === $currentUserId) ? 'admin' : 'member'];
            }
            
            $chat->users()->attach($usersData);
        }

        // Закрываем модалку
        $this->showNewChatModal = false;
        
        // Сбрасываем состояние
        $this->reset(['selectedColleagues', 'groupName', 'colleagueSearch']);

        // Перенаправляем пользователя в созданный чат
        return redirect()->route('chat.show', $chat);
    }

    public function render()
    {
        // Динамический поиск коллег с учетом фильтра ввода
        $colleagues = User::query()
            ->where('id', '!=', auth()->id()) // Исключаем себя из списка
            ->when($this->colleagueSearch, function ($query) {
                $query->where('name', 'like', '%' . $this->colleagueSearch . '%')
                      ->orWhere('position', 'like', '%' . $this->colleagueSearch . '%');
            })
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id, // Важно: передаем ID, а не имя!
                    'name' => $user->name,
                    // 'position' => $user->position ?? 'Сотрудник',
                    // 'department' => $user->department ?? 'Общий',
                    // 'online' => $user->isOnline(), // Ваша логика определения онлайн-статуса
                ];
            });

        // return view('livewire.chat.new-chat-modal', [
        //     'colleagues' => $colleagues,
        // ]);

        return $colleagues;

    }
}