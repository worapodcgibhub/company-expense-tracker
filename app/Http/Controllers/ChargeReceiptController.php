<?php

namespace App\Http\Controllers;

use App\Models\ChargeReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to open a document file. Files are on the private disk and
 * have no public URL; this checks ChargeReceiptPolicy::view first (the
 * route checks it too), then streams the file with the content type that
 * was detected at upload, so the browser never guesses it.
 */
class ChargeReceiptController extends Controller
{
    public function show(Request $request, ChargeReceipt $receipt): StreamedResponse
    {
        Gate::authorize('view', $receipt);

        $disk = Storage::disk($receipt->disk);
        abort_unless($disk->exists($receipt->path), 404);

        // PDFs and images may be shown in the browser; ?download=1 saves it.
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return $disk->response($receipt->path, $receipt->original_name, [
            'Content-Type' => $receipt->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], $disposition);
    }
}
