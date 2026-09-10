<?php
declare(strict_types=1);

function kvaOpenAiUploadName(string $name, string $mime): string
{
    $name = basename(trim($name));
    if ($name === '') $name = 'KVA';

    $extension = pathinfo($name, PATHINFO_EXTENSION);
    if ($extension !== '') {
        return substr($name, 0, -strlen($extension)).strtolower($extension);
    }

    $mimeExtensions = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $extension = $mimeExtensions[strtolower(trim($mime))] ?? '';

    return $extension !== '' ? $name.'.'.$extension : $name;
}
