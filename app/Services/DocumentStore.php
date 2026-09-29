<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityDocument;
use App\Models\SubmissionLink;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The only code that writes or reads activity files. Contents are encrypted
 * with the application key before they touch the disk (FRD CF-08), stored on
 * the private disk under a random name, and streamed back only through
 * DocumentController to signed-in users.
 */
class DocumentStore
{
    public const DISK = 'local';

    public function store(Activity $activity, UploadedFile $file, string $kind, ?User $user = null, ?SubmissionLink $link = null): ActivityDocument
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $path = "activities/{$activity->id}/".Str::uuid()->toString().'.'.$extension.'.enc';

        Storage::disk(self::DISK)->put($path, Crypt::encryptString($file->getContent()));

        return ActivityDocument::create([
            'activity_id' => $activity->id,
            'kind' => $kind,
            'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
            'disk' => self::DISK,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
            'uploaded_by' => $user?->id,
            'submission_link_id' => $link?->id,
        ]);
    }

    public function contents(ActivityDocument $document): string
    {
        return Crypt::decryptString(Storage::disk($document->disk)->get($document->path));
    }
}
