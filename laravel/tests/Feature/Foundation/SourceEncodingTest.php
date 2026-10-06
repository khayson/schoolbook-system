<?php

/*
 * Source files are UTF-8 without a byte order mark. Mojibake (UTF-8 read as Windows-1252
 * and written back, e.g. "−" becoming "âˆ’") once reached two docblocks through a
 * PowerShell 5.1 read/write round trip; this catches it anywhere in the project's text.
 */

test('project source files are valid UTF-8 without BOM and free of double-encoding', function () {
    $root = dirname(__DIR__, 3);
    $dirs = ['app', 'config', 'database', 'resources/views', 'routes', 'tests', '../docs', '../flutter/lib', '../flutter/test'];
    $extensions = ['php', 'md', 'dart', 'json', 'yaml', 'yml'];
    // Windows-1252 renderings of UTF-8 lead bytes: Ã + Â/ƒ, â + €/ˆ.
    $mojibake = '/\x{00C3}[\x{0080}-\x{00BF}\x{0192}\x{201A}-\x{2122}]|\x{00E2}[\x{20AC}\x{02C6}\x{0080}-\x{009F}]/u';

    $problems = [];
    foreach ($dirs as $dir) {
        $path = realpath($root.'/'.$dir);
        if ($path === false) {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! in_array($file->getExtension(), $extensions, true) || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $bytes = file_get_contents($file->getPathname());
            $name = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());
            if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
                $problems[] = "{$name}: byte order mark";
            }
            if (! mb_check_encoding($bytes, 'UTF-8')) {
                $problems[] = "{$name}: not valid UTF-8";
            } elseif (preg_match($mojibake, $bytes, $m) && $file->getFilename() !== 'SourceEncodingTest.php') {
                $problems[] = "{$name}: double-encoded text near '{$m[0]}'";
            }
        }
    }

    expect($problems)->toBe([]);
});
