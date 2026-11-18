<?php

/**
 * UL.GG shared file cache.
 *
 * Behaviour:
 * - Fresh cache: return immediately.
 * - Stale cache: one request rebuilds it.
 * - Concurrent requests: serve stale instead of waiting.
 * - Rebuild failure: serve stale data when available.
 * - Writes: atomic temp-file + rename.
 */
function ulggCacheRemember(string $key, int $ttl, callable $callback)
{
    $override = getenv('ULGG_CACHE_DIR');

    $cacheDir =
        is_string($override) && trim($override) !== ''
        ? rtrim($override, DIRECTORY_SEPARATOR)
        : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'ulgg-cache';

    if (
        !is_dir($cacheDir)
        && !@mkdir($cacheDir, 0775, true)
        && !is_dir($cacheDir)
    ) {
        return $callback();
    }

    $safeKey = preg_replace(
        '/[^a-zA-Z0-9_-]/',
        '_',
        $key
    );

    if (!is_string($safeKey) || $safeKey === '') {
        $safeKey = sha1($key);
    }

    $cacheFile = $cacheDir
        . DIRECTORY_SEPARATOR
        . $safeKey
        . '.json';

    $lockFile = $cacheFile . '.lock';

    $readCache = static function (string $file): array {
        if (!is_file($file)) {
            return [false, null, 0];
        }

        $mtime = @filemtime($file);
        $json = @file_get_contents($file);

        if ($mtime === false || !is_string($json)) {
            return [false, null, 0];
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [false, null, 0];
        }

        return [true, $data, (int)$mtime];
    };

    [$hasCache, $cachedValue, $mtime] =
        $readCache($cacheFile);

    if (
        $hasCache
        && (time() - $mtime) < $ttl
    ) {
        return $cachedValue;
    }

    $lockHandle = @fopen($lockFile, 'c');

    if ($lockHandle === false) {
        if ($hasCache) {
            return $cachedValue;
        }

        return $callback();
    }

    /*
     * If stale data exists, never make another request wait
     * for a rebuild. Only one process becomes the rebuilder.
     */
    if ($hasCache) {
        if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return $cachedValue;
        }
    } else {
        if (!@flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            return $callback();
        }
    }

    try {
        /*
         * Another process may have refreshed the file before
         * this process acquired the lock.
         */
        [$currentHasCache, $currentValue, $currentMtime] =
            $readCache($cacheFile);

        if (
            $currentHasCache
            && (time() - $currentMtime) < $ttl
        ) {
            return $currentValue;
        }

        if ($currentHasCache) {
            $hasCache = true;
            $cachedValue = $currentValue;
        }

        try {
            $data = $callback();
        } catch (Throwable $e) {
            if ($hasCache) {
                return $cachedValue;
            }

            throw $e;
        }

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        if (is_string($json)) {
            $tmp = @tempnam(
                $cacheDir,
                $safeKey . '.tmp.'
            );

            if (is_string($tmp)) {
                $written = @file_put_contents(
                    $tmp,
                    $json,
                    LOCK_EX
                );

                if ($written !== false) {
                    @chmod($tmp, 0664);

                    if (!@rename($tmp, $cacheFile)) {
                        @unlink($tmp);
                    }
                } else {
                    @unlink($tmp);
                }
            }
        }

        return $data;
    } finally {
        @flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
