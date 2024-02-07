<?php

namespace App\Helpers;

use enshrined\svgSanitize\Sanitizer;
use Illuminate\Support\Facades\Storage;

class StorageHelper
{
    public static function downloader($filename, $disk = 'default')
    {
        if ($disk == 'default') {
            $disk = config('filesystems.default');
        }
        switch (config("filesystems.disks.$disk.driver")) {
            case 'local':
                return response()->download(Storage::disk($disk)->path($filename)); //works for PRIVATE or public?!

            case 's3':
                return redirect()->away(Storage::disk($disk)->temporaryUrl($filename, now()->addMinutes(5))); //works for private or public, I guess?

            default:
                return Storage::disk($disk)->download($filename);
        }
    }

    //TODO - or possibly FIXME - we *assume* $dirname ends in a slash - maybe we shouldn't?
    // Maybe we should add our own? I dunno.
    public static function sanitized_storer(string $dirname, string $name_prefix, $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $file_name = $name_prefix.'-'.str_random(8).'-'.str_slug(basename($file->getClientOriginalName(), '.'.$extension)).'.'.$extension;


        \Log::debug("HEY DINGUS - your filetype IS: ".$file->getMimeType());
        // Check for SVG and sanitize it
        if ($file->getMimeType() === 'image/svg+xml') {
            \Log::debug('This is an SVG');
            \Log::debug($file_name);

            $sanitizer = new Sanitizer();
            $dirtySVG = file_get_contents($file->getRealPath());
            $cleanSVG = $sanitizer->sanitize($dirtySVG);

            try {
                Storage::put($dirname.$file_name, $cleanSVG);
            } catch (\Exception $e) {
                \Log::debug('Upload no workie :( ');
                \Log::debug($e);
            }

        } else {
            $put_results = Storage::put($dirname.$file_name, file_get_contents($file));
            \Log::debug("Here are the '$put_results' (should be 0 or 1 or true or false or something?)");
        }
        return $file_name;
    }
}
