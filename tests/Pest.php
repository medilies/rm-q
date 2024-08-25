<?php

use Medilies\RmQ\Facades\RmqException;
use Tests\OrchestraTestCase;

uses(OrchestraTestCase::class)->in(__DIR__.'/laravel');

function populateFiles(int $count = 1): array
{
    $files = [];

    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rm_q';

    if (! file_exists($tempDir)) {
        mkdir($tempDir) ?: throw new RmqException('Could not create dir: '.$tempDir);
    }

    for ($i = 0; $i < $count; $i++) {
        $file = tempnam($tempDir, 'rm_q_') ?: throw new RmqException('Failed on tempnam');
        file_put_contents($file, 'foo') ?: throw new RmqException('Could not create file: '.$file);
        $files[] = $file;
    }

    return $files;
}

function depopulateFiles(array $files): void
{
    foreach ($files as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
}
