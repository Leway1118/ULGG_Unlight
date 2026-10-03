<?php

declare(strict_types=1);

/*
 * ADMIN_PRIVATE_VERIFY_IMAGE_V1
 *
 * Serve ULID verification screenshots from private storage.
 * Access is restricted by the existing admin gate.
 */

require_once __DIR__ . '/../_admin_gate.php';
require_once __DIR__ . '/../../../config.php';

function verifyImageFail(int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    echo match ($status) {
        400 => 'Bad Request',
        404 => 'Not Found',
        default => 'Service Unavailable',
    };
    exit;
}

$userId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($userId === false || $userId === null) {
    verifyImageFail(400);
}

try {
    $stmt = $db->prepare("
        SELECT verify_image
        FROM game_user
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $userId,
    ]);

    $fileKey = trim((string)$stmt->fetchColumn());
} catch (Throwable $e) {
    error_log(
        '[ADMIN VERIFY IMAGE] DB lookup failed: '
        . get_class($e)
        . ' message=' . $e->getMessage()
    );

    verifyImageFail(503);
}

if (
    $fileKey === ''
    || preg_match(
        '/\A[a-f0-9]{64}\.(jpg|png|webp)\z/D',
        $fileKey,
        $matches
    ) !== 1
) {
    verifyImageFail(404);
}

$configuredDirectory = appEnv('ULID_VERIFY_UPLOAD_DIR');

$storageDirectory = $configuredDirectory
    ?? dirname(APP_ROOT)
        . DIRECTORY_SEPARATOR . 'unlight-private'
        . DIRECTORY_SEPARATOR . 'ulid-verify';

$realDirectory = realpath($storageDirectory);

if (
    $realDirectory === false
    || !is_dir($realDirectory)
    || !is_readable($realDirectory)
) {
    error_log(
        '[ADMIN VERIFY IMAGE] Private storage is unavailable: '
        . $storageDirectory
    );

    verifyImageFail(503);
}

$requestedPath =
    $realDirectory
    . DIRECTORY_SEPARATOR
    . $fileKey;

$realFile = realpath($requestedPath);

if (
    $realFile === false
    || !is_file($realFile)
    || !is_readable($realFile)
    || dirname($realFile) !== $realDirectory
) {
    verifyImageFail(404);
}

$extension = strtolower((string)$matches[1]);

$expectedMime = match ($extension) {
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    default => null,
};

if ($expectedMime === null) {
    verifyImageFail(404);
}

try {
    if (!class_exists(finfo::class)) {
        throw new RuntimeException('PHP fileinfo extension unavailable.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $actualMime = $finfo->file($realFile);
} catch (Throwable $e) {
    error_log(
        '[ADMIN VERIFY IMAGE] MIME inspection failed: '
        . get_class($e)
        . ' message=' . $e->getMessage()
    );

    verifyImageFail(503);
}

if ($actualMime !== $expectedMime) {
    error_log(
        '[ADMIN VERIFY IMAGE] MIME mismatch for user_id='
        . $userId
    );

    verifyImageFail(404);
}

$fileSize = filesize($realFile);

if ($fileSize === false) {
    verifyImageFail(503);
}

header('Content-Type: ' . $expectedMime);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: inline; filename="verification.' . $extension . '"');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

readfile($realFile);
exit;
