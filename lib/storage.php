<?php
/**
 * storage.php — read/write/list/move/delete blurts on disk.
 *
 * NOT web-accessible. This is the ONLY place that turns a (possibly
 * client-supplied) blurt id into a filesystem path. Every id is validated
 * against a strict pattern, basename()'d, and the resolved realpath() is
 * checked to be inside data/blurts/ or data/hidden/ before any read/write/
 * delete. No endpoint may touch the filesystem with a raw id.
 *
 * Blurts are stored one JSON file per blurt, filename {unix_ts}-{6-char-rand}
 * so directory listings sort chronologically.
 */

declare(strict_types=1);

/** Strict id pattern: 9–11 digit timestamp, dash, 6 lowercase hex/alnum. */
const BLURT_ID_PATTERN = '/^\d{9,11}-[a-z0-9]{6}$/';

/** Ensure the data directories exist. Called lazily before any access. */
function storage_init(): void
{
    foreach ([DATA_PATH, BLURTS_DIR, HIDDEN_DIR, RATE_DIR] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
    }
}

/** Validate a blurt id against the strict pattern. */
function blurt_id_valid(string $id): bool
{
    return preg_match(BLURT_ID_PATTERN, $id) === 1;
}

/**
 * Resolve a blurt id to a safe absolute path inside one of the allowed
 * directories. Returns null if the id is invalid or the resolved path would
 * escape the intended directory. When $mustExist is false (creating a new
 * file) we validate the parent directory instead of realpath()'ing the file.
 */
function blurt_path(string $id, string $baseDir, bool $mustExist = true): ?string
{
    if (!blurt_id_valid($id)) {
        return null;
    }
    if ($baseDir !== BLURTS_DIR && $baseDir !== HIDDEN_DIR) {
        return null; // only these two directories are ever addressable by id
    }

    // basename() strips any directory components a crafted id might carry.
    $name = basename($id) . '.json';
    $path = $baseDir . '/' . $name;

    $baseReal = realpath($baseDir);
    if ($baseReal === false) {
        return null;
    }

    if ($mustExist) {
        $real = realpath($path);
        if ($real === false) {
            return null; // file does not exist
        }
        // The resolved file must still live directly inside the base dir.
        if (strpos($real, $baseReal . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $real;
    }

    // Creating: the constructed path's directory must be the base dir.
    if (realpath(dirname($path)) !== $baseReal) {
        return null;
    }
    return $path;
}

/**
 * Locate an existing blurt by id in either directory.
 * @return array{path:string,dir:string}|null 'dir' is BLURTS_DIR or HIDDEN_DIR
 */
function find_blurt(string $id): ?array
{
    foreach ([BLURTS_DIR, HIDDEN_DIR] as $dir) {
        $path = blurt_path($id, $dir, true);
        if ($path !== null) {
            return ['path' => $path, 'dir' => $dir];
        }
    }
    return null;
}

/**
 * Decode a blurt JSON file into an associative array, or null on failure.
 * We never eval/include/unserialize data — only json_decode().
 */
function read_blurt_file(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Atomically write a blurt record to $path. Built entirely with json_encode()
 * so user text can never break the file structure.
 */
function write_blurt_file(string $path, array $record): bool
{
    $json = json_encode(
        $record,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    if ($json === false) {
        return false;
    }
    // Write to a temp file in the same directory, then rename (atomic on POSIX).
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0660);
    return true;
}

/**
 * Persist a brand-new blurt (always into data/blurts/). The record's id is
 * validated and the path resolved safely before writing.
 * @return bool success
 */
function create_blurt(array $record): bool
{
    storage_init();
    $id = $record['id'] ?? '';
    if (!is_string($id) || !blurt_id_valid($id)) {
        return false;
    }
    $path = blurt_path($id, BLURTS_DIR, false);
    if ($path === null || file_exists($path)) {
        return false;
    }
    return write_blurt_file($path, $record);
}

/** Overwrite an existing blurt file (used e.g. when updating report_count). */
function update_blurt(string $path, array $record): bool
{
    return write_blurt_file($path, $record);
}

/**
 * List every visible top-level + reply blurt, newest-file-first by name.
 * Reads all files (needed to thread replies to parents) but callers paginate
 * what they actually render.
 * @return array<int,array> decoded records
 */
function load_all_visible(): array
{
    return load_dir_blurts(BLURTS_DIR);
}

/** List all hidden blurts (admin view). @return array<int,array> */
function load_all_hidden(): array
{
    return load_dir_blurts(HIDDEN_DIR);
}

/**
 * Read and decode every .json blurt in a directory.
 * @return array<int,array>
 */
function load_dir_blurts(string $dir): array
{
    storage_init();
    $out = [];
    $names = @scandir($dir, SCANDIR_SORT_DESCENDING);
    if ($names === false) {
        return $out;
    }
    foreach ($names as $name) {
        if ($name === '.' || $name === '..' || substr($name, -5) !== '.json') {
            continue;
        }
        // Only accept properly-named files; ignore stray temp files, etc.
        $id = substr($name, 0, -5);
        if (!blurt_id_valid($id)) {
            continue;
        }
        $record = read_blurt_file($dir . '/' . $name);
        if ($record !== null) {
            $out[] = $record;
        }
    }
    return $out;
}

/**
 * Move a blurt from one directory to the other (visible <-> hidden).
 * Both source and destination paths are resolved safely by id.
 * @return string|null the new path on success, null on failure
 */
function move_blurt(string $id, string $fromDir, string $toDir): ?string
{
    $src = blurt_path($id, $fromDir, true);
    if ($src === null) {
        return null;
    }
    $dst = blurt_path($id, $toDir, false);
    if ($dst === null || file_exists($dst)) {
        return null;
    }
    if (!@rename($src, $dst)) {
        return null;
    }
    return $dst;
}

/**
 * Permanently delete a blurt (from whichever directory it lives in).
 * @return bool success
 */
function delete_blurt(string $id): bool
{
    $found = find_blurt($id);
    if ($found === null) {
        return false;
    }
    return @unlink($found['path']);
}
