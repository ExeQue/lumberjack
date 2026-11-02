<?php

declare(strict_types=1);

namespace Tests\Support\Arch;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

trait ReadsTestFiles
{
    /**
     * Every file in the suite, keyed by its path from the project root.
     *
     * @return array<string, SplFileInfo>
     */
    public function suiteFiles(): array
    {
        $files = [];

        foreach (['tests/Feature', 'tests/Unit', 'tests/Arch'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                $files[str_replace(base_path() . '/', '', $file->getPathname())] = $file;
            }
        }

        return $files;
    }
}
