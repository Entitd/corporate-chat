<?php

namespace App\Console\Commands;

use App\Services\EncryptedAttachments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class EncryptChatAttachments extends Command
{
    protected $signature = 'chat:encrypt-attachments';

    protected $description = 'Encrypt existing chat files, including shared forwarded attachments';

    public function handle(EncryptedAttachments $files): int
    {
        $disk = Storage::disk('local');
        $count = 0;
        $this->cleanupPlaintext();

        while ($attachment = DB::table('attachments')->where('is_encrypted', false)->first()) {
            $contents = $disk->get($attachment->file_path);

            if ($contents === null) {
                $this->error('Cannot read attachment #'.$attachment->id.'. No reference was changed; restore the file and retry.');

                return self::FAILURE;
            }

            // A new path leaves the original intact until all references are updated.
            $path = $files->storeContents($contents, dirname($attachment->file_path));

            try {
                $changed = DB::transaction(function () use ($attachment, $path): int {
                    $changed = DB::table('attachments')
                        ->where('file_path', $attachment->file_path)
                        ->where('is_encrypted', false)
                        ->update(['file_path' => $path, 'is_encrypted' => true]);

                    if ($changed > 0) {
                        DB::table('chat_encryption_cleanup')->insertOrIgnore(['file_path' => $attachment->file_path]);
                    }

                    return $changed;
                });
            } catch (\Throwable $exception) {
                $disk->delete($path);

                throw $exception;
            }

            if ($changed === 0) {
                $disk->delete($path);

                throw new RuntimeException('Attachment references changed during encryption. Run with chat writers stopped.');
            }

            $this->cleanupPlaintext();

            $count += $changed;
        }

        $this->info('Encrypted attachment records: '.$count);

        return self::SUCCESS;
    }

    private function cleanupPlaintext(): void
    {
        foreach (DB::table('chat_encryption_cleanup')->orderBy('file_path')->cursor() as $pending) {
            if (DB::table('attachments')->where('file_path', $pending->file_path)->exists()) {
                throw new RuntimeException('A plaintext file is still referenced: '.$pending->file_path);
            }

            $disk = Storage::disk('local');

            if ($disk->exists($pending->file_path) && ! $disk->delete($pending->file_path)) {
                throw new RuntimeException('Could not remove plaintext file: '.$pending->file_path.'. Retry the command.');
            }

            DB::table('chat_encryption_cleanup')->where('file_path', $pending->file_path)->delete();
        }
    }
}
