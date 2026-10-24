<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadAttachmentController extends Controller
{
    /**
     * Download a task attachment the user is allowed to see.
     */
    public function __invoke(Request $request, Attachment $attachment): StreamedResponse
    {
        $task = $attachment->attachable;

        abort_unless($task instanceof Task && Gate::allows('view', $task), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }
}
