<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * The typeface of every generated document: the memo and annexes, report PDFs
 * and print views, and Excel exports use Gill Sans MT.
 *
 * Gill Sans MT is licensed with Microsoft Office, so its files are not kept in
 * the repository: copy them into config('activities.document_fonts') (by
 * default storage/app/fonts) on each server, under their Windows names
 * (GIL_____.TTF, GILB____.TTF, GILI____.TTF, GILBI___.TTF) or their Office for
 * Mac names (Gill Sans MT.ttf, Gill Sans MT Bold.ttf, ...). The PDFs embed them
 * (the font permits embedding). Without the files, PDFs fall back to DejaVu Sans.
 */
final class DocumentFont
{
    public const NAME = 'Gill Sans MT';

    /** CSS font stack: browsers use the installed font; dompdf the faces registered below. */
    public const STACK = "'Gill Sans MT', 'Gill Sans', 'DejaVu Sans', sans-serif";

    /** @var list<array{0: string, 1: string, 2: list<string>}> weight, style, accepted file names */
    private const FACES = [
        ['normal', 'normal', ['GIL_____.TTF', 'Gill Sans MT.ttf']],
        ['bold', 'normal', ['GILB____.TTF', 'Gill Sans MT Bold.ttf']],
        ['normal', 'italic', ['GILI____.TTF', 'Gill Sans MT Italic.ttf']],
        ['bold', 'italic', ['GILBI___.TTF', 'Gill Sans MT Bold Italic.ttf']],
    ];

    /** @font-face rules for the font files that are present, for dompdf. Empty when there are none. */
    public static function faces(): string
    {
        $css = '';
        foreach (self::FACES as [$weight, $style, $names]) {
            $path = self::find($names);
            if ($path !== null) {
                $css .= sprintf("@font-face { font-family: '%s'; font-weight: %s; font-style: %s; src: url('%s') format('truetype'); }\n", self::NAME, $weight, $style, $path);
            }
        }

        if ($css !== '') {
            // dompdf writes the font metrics it builds into its font cache.
            File::ensureDirectoryExists(config('dompdf.options.font_cache', storage_path('fonts')));
        }

        return $css;
    }

    public static function installed(): bool
    {
        return self::find(self::FACES[0][2]) !== null;
    }

    /** @param  list<string>  $names */
    private static function find(array $names): ?string
    {
        $dir = rtrim((string) config('activities.document_fonts'), '/');

        foreach ($names as $name) {
            foreach ([$name, strtolower($name)] as $candidate) {
                if (is_file("{$dir}/{$candidate}")) {
                    return "{$dir}/{$candidate}";
                }
            }
        }

        return null;
    }
}
