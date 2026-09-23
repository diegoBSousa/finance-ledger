<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait TemporaryUploads
{
    private string $uploadRoot;

    private function createUploadRoot(): void
    {
        $this->uploadRoot = sys_get_temp_dir().'/ledger-upload-'.bin2hex(random_bytes(12));
        mkdir($this->uploadRoot, 0700, true);
    }

    private function removeUploadRoot(): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->uploadRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->uploadRoot);
    }

    private function sourceFile(string $content = "date,description,amount,type\n2026-08-16,Limpeza #682,494618,Despesa\n"): string
    {
        $path = $this->uploadRoot.'/source-'.bin2hex(random_bytes(8));
        file_put_contents($path, $content);

        return $path;
    }
}
