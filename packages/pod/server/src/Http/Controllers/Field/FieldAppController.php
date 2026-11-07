<?php

namespace Fleetbase\Pod\Http\Controllers\Field;

use Illuminate\Routing\Controller;

/**
 * Serves the offline field app (packages/pod/field-app) at /pod-field/, from
 * the same address as the API so the app needs no CORS set-up. The files are
 * public; every data request the app makes is authenticated.
 */
class FieldAppController extends Controller
{
    private const TYPES = [
        'html'        => 'text/html; charset=utf-8',
        'js'          => 'text/javascript; charset=utf-8',
        'css'         => 'text/css; charset=utf-8',
        'json'        => 'application/json',
        'webmanifest' => 'application/manifest+json',
        'png'         => 'image/png',
        'svg'         => 'image/svg+xml',
    ];

    public function serve(?string $path = null)
    {
        $root = realpath(dirname(__DIR__, 5) . '/field-app');
        $path = $path === null || $path === '' ? 'index.html' : $path;
        $file = $root ? realpath($root . '/' . $path) : false;

        // Only files inside the app folder, with a known type.
        if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            abort(404);
        }
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) {
            abort(404);
        }

        $headers = [
            'Content-Type'           => self::TYPES[$extension],
            'X-Content-Type-Options' => 'nosniff',
            // The service worker keeps its own copy for offline use; always check for updates.
            'Cache-Control'          => 'no-cache',
        ];
        if (basename($file) === 'sw.js') {
            $headers['Service-Worker-Allowed'] = '/pod-field/';
        }
        if ($extension === 'html') {
            $headers['Content-Security-Policy'] = "default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; style-src 'self'; script-src 'self'; worker-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'";
        }

        return response()->file($file, $headers);
    }
}
