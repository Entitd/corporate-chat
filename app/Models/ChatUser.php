<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatUser extends Model
{
    protected $filable = [
        'role',
        'last_read_at   '
    ];
}
