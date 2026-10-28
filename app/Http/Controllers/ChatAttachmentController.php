<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\EncryptedAttachments;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAttachmentController extends Controller
{
    public function download(Attachment $attachment, EncryptedAttachments $files): StreamedResponse
    {
        $contents = $this->contents($attachment, $files);

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $attachment->file_name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(Attachment $attachment, EncryptedAttachments $files): Response
    {
        $contents = $this->contents($attachment, $files);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        abort_unless(in_array($mime, Attachment::PREVIEW_TYPES, true), 415);

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $attachment->file_name, 'attachment'),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    private function contents(Attachment $attachment, EncryptedAttachments $files): string
    {
        abort_unless($attachment->message()
            ->whereHas('chat.users', fn ($users) => $users->whereKey(auth()->id()))
            ->exists(), 403);
        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        return $files->contents($attachment);
    }
}
