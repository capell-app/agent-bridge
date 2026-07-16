<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

it('invokes Laravel Actions through their run entry point', function (): void {
    $sourceRoot = dirname(__DIR__, 2) . '/src';
    $violations = [];

    foreach ((new Finder)->files()->in($sourceRoot)->name('*.php') as $file) {
        if (str_contains($file->getContents(), '->handle(')) {
            $violations[] = $file->getRelativePathname();
        }
    }

    expect($violations)->toBe([], 'Use Action::run(...) rather than calling an Action handle(...) method directly.');
});
