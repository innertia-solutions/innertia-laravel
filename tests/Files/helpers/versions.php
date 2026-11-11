<?php

use Innertia\Files\Models\File;
use Innertia\Files\UseCases\UploadFile;

/** Archivo local temporal con el contenido dado (se borra al terminar el proceso). */
function versionsTmpFile(string $contents, string $ext = 'txt'): string
{
    $path = sys_get_temp_dir() . '/ver-' . bin2hex(random_bytes(8)) . '.' . $ext;
    file_put_contents($path, $contents);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

/** Sube un archivo real (pasa por UploadFile, igual que producción). */
function versionsUpload(string $contents = 'v1', string $name = 'doc.txt'): File
{
    $tmp = versionsTmpFile($contents);
    $uploaded = new \Illuminate\Http\UploadedFile($tmp, $name, 'text/plain', null, true);

    return (new UploadFile(uploaded: $uploaded))->execute();
}
