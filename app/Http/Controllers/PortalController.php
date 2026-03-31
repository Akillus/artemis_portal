<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PortalController extends Controller
{
    public function spa(): BinaryFileResponse
    {
        $indexPath = public_path('index.html');

        abort_unless(is_file($indexPath), 503, 'Portal frontend build is not published yet.');

        return response()->file($indexPath, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
