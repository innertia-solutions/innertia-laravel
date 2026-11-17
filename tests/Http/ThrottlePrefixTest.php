<?php

/**
 * Laravel keys the `throttle` middleware of guest requests by domain + IP only, so two
 * routes without a prefix share one counter: a few logins would lock password recovery.
 * Every throttle declared by the package must carry its own, unique prefix.
 */
it('gives every throttled route its own counter', function () {
    $prefixes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src'));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (file($file->getPathname()) as $number => $line) {
            if (str_contains($line, '*') || ! preg_match_all("/'throttle:([^']*)'/", $line, $matches)) {
                continue; // docblock examples are not route definitions
            }
            foreach ($matches[1] as $definition) {
                $parts = explode(',', $definition);
                $where = $file->getFilename().':'.($number + 1);

                expect($parts)->toHaveCount(3, "throttle without prefix at {$where}");
                expect($prefixes)->not->toHaveKey($parts[2], "duplicated throttle prefix at {$where}");
                $prefixes[$parts[2]] = $where;
            }
        }
    }

    expect($prefixes)->not->toBeEmpty();
});
