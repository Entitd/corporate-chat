<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = [
        'file_path',
        'file_name',
        'file_type',
        'file_size',
        'duration',
        'is_encrypted',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean'];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
