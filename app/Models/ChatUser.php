<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatUser extends Model
{
    protected $filable = [
        'role',
        'last_read_at   ',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'user_id');
    }
}
