<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    protected $fillable = [
        'name',
        'type'
    ];


    public function users(): BelongsToMany
    {
        // 2-й аргумент: имя промежуточной таблицы (обычно chat_user по конвенции, либо chat_users)
        // 3-й аргумент: внешний ключ текущей модели в промежуточной таблице
        // 4-й аргумент: внешний ключ связываемой модели в промежуточной таблице
        return $this->belongsToMany(User::class, 'chat_users', 'chat_id', 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'chat_id');
    }

}
