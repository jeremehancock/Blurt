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

/** Overwrite an existing blurt file (used by admin hide/restore). */
function update_blurt(string $path, array $record): bool
{
    return write_blurt_file($path, $record);
}

/**
 * Read-modify-write a blurt under an exclusive lock, so concurrent updates
 * (e.g. two visitors reacting in the same instant) can't clobber each other.
 * $fn receives the decoded record and returns the modified record, or null to
 * abort without writing. Returns the modified record, or null on failure.
 */
function modify_blurt(string $path, callable $fn): ?array
{
    // 'r+' (not 'c+') so a just-purged blurt isn't recreated as an empty file.
    $fh = @fopen($path, 'r+');
    if ($fh === false) {
        return null;
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return null;
        }
        $raw = stream_get_contents($fh);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record)) {
            return null;
        }
        $updated = $fn($record);
        if (!is_array($updated)) {
            return null;
        }
        $json = json_encode(
            $updated,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        if ($json === false) {
            return null;
        }
        rewind($fh);
        if (fwrite($fh, $json) === false) {
            return null;
        }
        ftruncate($fh, strlen($json));
        fflush($fh);
        return $updated;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
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

// ---------------------------------------------------------------------------
// Ephemerality: blurts live for POST_TTL seconds, then vanish. Cleanup is
// lazy (no cron): a throttled sweep runs on ordinary requests. Display code
// also filters expired blurts directly, so nothing stale is ever shown even
// in the window between sweeps.
// ---------------------------------------------------------------------------

/** Unix timestamp at which a blurt expires (created_at + POST_TTL). */
function blurt_expires_at(array $record): int
{
    return (int) ($record['created_at'] ?? 0) + POST_TTL;
}

/** True if the blurt is past its lifetime and should no longer be shown. */
function blurt_is_expired(array $record, ?int $now = null): bool
{
    $now = $now ?? time();
    return blurt_expires_at($record) <= $now;
}

/**
 * Run the expiry sweep at most once per PURGE_INTERVAL seconds, tracked via a
 * marker file. Safe to call at the top of any entrypoint; concurrent callers
 * are harmless because deletion is idempotent.
 */
function maybe_purge_expired(): void
{
    storage_init();
    $marker = DATA_PATH . '/.last_purge';
    $now = time();
    if (is_file($marker)) {
        $last = (int) @file_get_contents($marker);
        if ($now - $last < PURGE_INTERVAL) {
            return;
        }
    }
    // Claim the interval up-front so parallel requests don't all sweep.
    @file_put_contents($marker, (string) $now, LOCK_EX);
    purge_expired($now);
}

/**
 * Delete every expired blurt from data/blurts/ and data/hidden/, plus any
 * stale per-client rate files. Returns the number of blurts removed.
 */
function purge_expired(?int $now = null): int
{
    storage_init();
    $now = $now ?? time();
    $removed = 0;

    foreach ([BLURTS_DIR, HIDDEN_DIR] as $dir) {
        $names = @scandir($dir);
        if ($names === false) {
            continue;
        }
        foreach ($names as $name) {
            if (substr($name, -5) !== '.json') {
                continue;
            }
            $id = substr($name, 0, -5);
            if (!blurt_id_valid($id)) {
                continue;
            }
            $path = blurt_path($id, $dir, true);
            if ($path === null) {
                continue;
            }
            $record = read_blurt_file($path);
            // Delete expired blurts, and also drop any unreadable/corrupt files.
            if ($record === null || blurt_is_expired($record, $now)) {
                if (@unlink($path)) {
                    $removed++;
                }
            }
        }
    }

    purge_stale_rate_files($now);
    return $removed;
}

/**
 * Remove per-client rate files that can no longer hold any live timestamps
 * (untouched for longer than RATE_WINDOW). Keeps data/rate/ from growing
 * without bound. Purely housekeeping.
 */
function purge_stale_rate_files(int $now): void
{
    $names = @scandir(RATE_DIR);
    if ($names === false) {
        return;
    }
    foreach ($names as $name) {
        if (substr($name, -5) !== '.json') {
            continue;
        }
        $path = RATE_DIR . '/' . $name;
        $mtime = @filemtime($path);
        if ($mtime !== false && ($now - $mtime) > RATE_WINDOW) {
            @unlink($path);
        }
    }
}
