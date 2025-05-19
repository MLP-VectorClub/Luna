<?php


namespace App\Interfaces;


interface HasStoredFiles
{

    /**
     * Return the output path relative to `storage/app` where related files
     * must be stored. Only use this method for private files.
     */
    public function getRelativeOutputPath(): string;
}
