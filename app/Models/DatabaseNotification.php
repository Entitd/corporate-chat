<?php

namespace App\Models;

class DatabaseNotification extends \Illuminate\Notifications\DatabaseNotification
{
    /** @var array<string, string> */
    protected $casts = [
        'data' => 'encrypted:array',
        'read_at' => 'datetime',
    ];
}
