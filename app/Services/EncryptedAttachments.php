<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class EncryptedAttachments
{
    public function store(UploadedFile $file, int $chatId): string
    {
        return $this->storeContents($file->getContent(), 'chat-attachments/'.$chatId);
    }

    public function storeContents(string $contents, string $directory): string
    {
        $path = $directory.'/'.Str::uuid().'.enc';

        if (! Storage::disk('local')->put($path, Crypt::encryptString($contents))) {
            throw new RuntimeException('Не удалось сохранить зашифрованный файл.');
        }

        return $path;
    }

    public function contents(Attachment $attachment): string
    {
        $contents = Storage::disk('local')->get($attachment->file_path);

        if ($contents === null) {
            throw new RuntimeException('Не удалось прочитать файл.');
        }

        return $attachment->is_encrypted ? Crypt::decryptString($contents) : $contents;
    }
}
