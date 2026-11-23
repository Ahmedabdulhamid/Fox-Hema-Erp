<?php

namespace App\Http\Controllers;

use App\Modules\BackupRestore\Services\BackupDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadBackupArchiveController extends Controller
{
    public function __invoke(Request $request, string $uuid, BackupDownload $download): StreamedResponse
    {
        return $download->response($request->user(), $uuid);
    }
}
