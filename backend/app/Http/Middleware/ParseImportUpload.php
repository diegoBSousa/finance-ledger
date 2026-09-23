<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Imports\ImportLimits;
use App\Application\Imports\ImportUnavailable;
use App\Application\Imports\InvalidImportFile;
use App\Application\Imports\UploadTooLarge;
use Closure;
use Illuminate\Http\Request;
use RequestParseBodyException;
use Symfony\Component\HttpFoundation\Response;

final class ParseImportUpload
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! str_starts_with(strtolower((string) $request->header('Content-Type')), 'multipart/form-data')) {
            throw new InvalidImportFile('multipart_required');
        }
        // Native HTTP parsing is deferred by php.ini, so duplicate multipart file fields cannot be silently discarded.
        // The CLI PHPUnit transport supplies a populated FileBag instead of an HTTP body.
        if (PHP_SAPI !== 'cli') {
            if ((bool) ini_get('enable_post_data_reading')) {
                throw new ImportUnavailable;
            }
            try {
                [$post,$files] = request_parse_body([
                    'post_max_size' => ImportLimits::MAX_BODY_BYTES,
                    'upload_max_filesize' => ImportLimits::MAX_BYTES,
                    'max_file_uploads' => 1, 'max_multipart_body_parts' => 1,
                ]);
                $request->request->replace($post);
                $request->files->replace($files);
            } catch (RequestParseBodyException $error) {
                if (str_contains($error->getMessage(), 'Content-Length') || str_contains($error->getMessage(), 'post_max_size')) {
                    throw new UploadTooLarge;
                }
                throw new InvalidImportFile('invalid_multipart');
            }
        }

        return $next($request);
    }
}
