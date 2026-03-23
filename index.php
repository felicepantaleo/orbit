<?php
/**
 * Orbit Browser
 * A comprehensive web-based file browser for any web-exposed folder
 *
 * Features: search, filters, sorting, image previews, PDF viewer,
 * EXIF metadata, file type recognition, dark mode, keyboard navigation
 */

define('BASE_DIR', __DIR__);
define('VERSION', '1.1.0');

// ─── CONSTANTS (must be defined before any function calls) ────────────────────

define('IMAGE_EXTS', ['jpg','jpeg','png','gif','webp','svg','bmp','tiff','tif','ico','avif']);

define('TYPE_MAP', [
    'image'    => ['jpg','jpeg','png','gif','webp','svg','bmp','tiff','tif','ico','avif'],
    'pdf'      => ['pdf'],
    'video'    => ['mp4','avi','mov','mkv','webm','wmv','m4v','ogv'],
    'audio'    => ['mp3','wav','ogg','flac','m4a','aac','opus'],
    'code'     => ['py','cpp','cxx','cc','c','h','hpp','java','js','ts','jsx','tsx','html',
                   'css','php','sh','bash','rb','go','rs','swift','kt','r','m','f90','f','jl'],
    'data'     => ['csv','tsv','json','xml','yaml','yml','hdf5','h5','root','parquet',
                   'feather','npy','npz','fits','dat','bin','hepmc'],
    'document' => ['txt','md','rst','tex','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp'],
    'archive'  => ['zip','tar','gz','bz2','xz','7z','rar','tgz','tar.gz','tar.bz2'],
    'notebook' => ['ipynb'],
]);

// ─── PHP BACKEND API ───────────────────────────────────────────────────────────

if (isset($_GET['action'])) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');

    try {
        switch ($_GET['action']) {
            case 'list':
                header('Content-Type: application/json; charset=utf-8');
                $path  = get_safe_path($_GET['path'] ?? '/');
                echo json_encode(list_directory($path));
                break;
            case 'meta':
                header('Content-Type: application/json; charset=utf-8');
                $path  = get_safe_path($_GET['path'] ?? '/');
                echo json_encode(get_file_metadata($path));
                break;
            case 'search':
                header('Content-Type: application/json; charset=utf-8');
                $base  = get_safe_path($_GET['path'] ?? '/');
                $query = trim($_GET['query'] ?? '');
                echo json_encode(search_files($base, $query));
                break;
            case 'file':
                $path = get_safe_path($_GET['path'] ?? '/');
                $download = isset($_GET['download']) && $_GET['download'] === '1';
                stream_file($path, $download);
                break;
            default:
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['error' => 'Unknown action']);
        }
    } catch (Exception $e) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─── PATH SECURITY ────────────────────────────────────────────────────────────

function get_safe_path(string $rel): string
{
    // Normalize separators and strip leading slashes
    $rel  = str_replace('\\', '/', $rel);
    $rel  = '/' . ltrim($rel, '/');
    // Collapse duplicate slashes
    $rel  = preg_replace('#/+#', '/', $rel);
    $full = realpath(BASE_DIR . $rel);
    if ($full === false || strpos($full, BASE_DIR) !== 0) {
        throw new Exception('Invalid or inaccessible path');
    }
    return $full;
}


function stream_file(string $full_path, bool $download = false): void
{
    if (!file_exists($full_path) || !is_file($full_path)) {
        throw new Exception('File not found');
    }

    // Deny streaming of this script, dotfiles, and sensitive script/config files
    $name = basename($full_path);
    // Block the current script (e.g., index.php)
    if ($name === basename(__FILE__)) {
        throw new Exception('Access denied');
    }
    // Block dotfiles (e.g., .env, .gitignore)
    if ($name !== '' && $name[0] === '.') {
        throw new Exception('Access denied');
    }
    // Block common script and configuration extensions
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $blockedExts = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8',
        'phar',
        'ini', 'env', 'conf', 'config', 'cnf',
        'htaccess', 'htpasswd'
    ];
    if ($ext !== '' && in_array($ext, $blockedExts, true)) {
        throw new Exception('Access denied');
    }

    $mime = function_exists('mime_content_type') ? mime_content_type($full_path) : 'application/octet-stream';
    $size = filesize($full_path);
    if ($size === false) {
        throw new Exception('Cannot determine file size');
    }

    $fp = fopen($full_path, 'rb');
    if ($fp === false) {
        throw new Exception('Cannot open file');
    }

    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)$size);
    header(sprintf(
        "Content-Disposition: %s; filename=\"%s\"; filename*=UTF-8''%s",
        $download ? 'attachment' : 'inline',
        rawurlencode($name),
        rawurlencode($name)
    ));
    header('Accept-Ranges: bytes');
    fpassthru($fp);
    fclose($fp);
}

// ─── DIRECTORY LISTING ───────────────────────────────────────────────────────

function list_directory(string $full_path): array
{
    if (!is_dir($full_path)) {
        throw new Exception('Not a directory');
    }

    $entries = @scandir($full_path);
    if ($entries === false) {
        throw new Exception('Cannot read directory');
    }

    $items = [];
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') continue;
        // Skip hidden files and this script itself
        if ($name[0] === '.') continue;
        if ($name === basename(__FILE__)) continue;

        $fp   = $full_path . DIRECTORY_SEPARATOR . $name;
        $stat = @stat($fp);
        if ($stat === false) continue;

        $is_dir = is_dir($fp);
        $ext    = $is_dir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $type   = $is_dir ? 'directory' : get_file_type($ext);

        $item = [
            'name'      => $name,
            'path'      => str_replace(BASE_DIR, '', $fp),
            'is_dir'    => $is_dir,
            'size'      => $is_dir ? null : (int)$stat['size'],
            'modified'  => (int)$stat['mtime'],
            'type'      => $type,
            'extension' => $ext,
            'is_image'  => in_array($ext, IMAGE_EXTS, true),
        ];

        $items[] = $item;
    }

    // Sort: directories first, then by name
    usort($items, static function (array $a, array $b): int {
        if ($a['is_dir'] !== $b['is_dir']) {
            return $a['is_dir'] ? -1 : 1;
        }
        return strnatcasecmp($a['name'], $b['name']);
    });

    $rel = str_replace(BASE_DIR, '', $full_path);
    if ($rel === '') $rel = '/';

    return [
        'success' => true,
        'path'    => $rel,
        'parent'  => ($rel !== '/' && $rel !== '') ? dirname($rel) : null,
        'items'   => $items,
    ];
}

// ─── FILE TYPE DETECTION ─────────────────────────────────────────────────────

function get_file_type(string $ext): string
{
    foreach (TYPE_MAP as $type => $exts) {
        if (in_array($ext, $exts, true)) return $type;
    }
    return 'other';
}

// ─── FILE METADATA & EXIF ───────────────────────────────────────────────────

function get_file_metadata(string $full_path): array
{
    if (!file_exists($full_path)) {
        throw new Exception('File not found');
    }
    if (is_dir($full_path)) {
        throw new Exception('Path is a directory, not a file');
    }

    $name = basename($full_path);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $stat = stat($full_path);
    $mime = function_exists('mime_content_type') ? mime_content_type($full_path) : 'application/octet-stream';

    $meta = [
        'name'      => $name,
        'path'      => str_replace(BASE_DIR, '', $full_path),
        'size'      => (int)$stat['size'],
        'modified'  => (int)$stat['mtime'],
        'type'      => get_file_type($ext),
        'extension' => $ext,
        'mime_type' => $mime,
    ];

    // Image-specific metadata
    if (in_array($ext, ['jpg','jpeg','tiff','tif'], true) && function_exists('exif_read_data')) {
        $exif = @exif_read_data($full_path, 'ALL', true);
        if ($exif) {
            $meta['exif'] = parse_exif($exif);
        }
    }

    if (in_array($ext, IMAGE_EXTS, true) && function_exists('getimagesize')) {
        $sz = @getimagesize($full_path);
        if ($sz) {
            $meta['image'] = [
                'width'    => $sz[0],
                'height'   => $sz[1],
                'bits'     => $sz['bits'] ?? null,
                'channels' => $sz['channels'] ?? null,
            ];
        }
    }

    // CSV row/column preview
    if ($ext === 'csv' && $stat['size'] < 2 * 1024 * 1024) {
        $meta['csv_preview'] = read_csv_preview($full_path);
    }

    return ['success' => true, 'meta' => $meta];
}

function parse_exif(array $exif): array
{
    $out = [];

    if (isset($exif['IFD0'])) {
        $ifd = $exif['IFD0'];
        $out['camera'] = array_filter([
            'make'      => $ifd['Make']     ?? null,
            'model'     => $ifd['Model']    ?? null,
            'software'  => $ifd['Software'] ?? null,
            'date_time' => $ifd['DateTime'] ?? null,
        ]);
    }

    if (isset($exif['EXIF'])) {
        $e = $exif['EXIF'];
        $out['settings'] = array_filter([
            'exposure_time'   => isset($e['ExposureTime']) ? fraction_string($e['ExposureTime']) : null,
            'f_number'        => isset($e['FNumber'])      ? 'f/' . fraction_float($e['FNumber']) : null,
            'iso'             => $e['ISOSpeedRatings'] ?? null,
            'focal_length'    => isset($e['FocalLength']) ? fraction_float($e['FocalLength']) . ' mm' : null,
            'flash'           => $e['Flash'] ?? null,
            'white_balance'   => $e['WhiteBalance'] ?? null,
            'date_original'   => $e['DateTimeOriginal']  ?? null,
            'date_digitized'  => $e['DateTimeDigitized'] ?? null,
            'width'           => $e['ExifImageWidth']  ?? null,
            'height'          => $e['ExifImageLength'] ?? null,
            'color_space'     => $e['ColorSpace']      ?? null,
            'exposure_mode'   => $e['ExposureMode']    ?? null,
            'metering_mode'   => $e['MeteringMode']    ?? null,
        ]);
    }

    if (isset($exif['GPS'])) {
        $gps = $exif['GPS'];
        $lat = isset($gps['GPSLatitude'])  ? gps_to_decimal($gps['GPSLatitude'],  $gps['GPSLatitudeRef']  ?? 'N') : null;
        $lon = isset($gps['GPSLongitude']) ? gps_to_decimal($gps['GPSLongitude'], $gps['GPSLongitudeRef'] ?? 'E') : null;
        if ($lat !== null && $lon !== null) {
            $out['gps'] = [
                'latitude'  => $lat,
                'longitude' => $lon,
                'map_url'   => "https://www.openstreetmap.org/?mlat={$lat}&mlon={$lon}&zoom=15",
            ];
            if (isset($gps['GPSAltitude'])) {
                $out['gps']['altitude_m'] = fraction_float($gps['GPSAltitude']);
            }
        }
    }

    return $out;
}

function fraction_float(string $frac): float
{
    if (str_contains($frac, '/')) {
        [$n, $d] = explode('/', $frac, 2);
        $df = (float)$d;
        return $df !== 0.0 ? round((float)$n / $df, 4) : 0.0;
    }
    return (float)$frac;
}

function fraction_string(string $frac): string
{
    if (str_contains($frac, '/')) {
        [$n, $d] = explode('/', $frac, 2);
        $df  = (float)$d;
        $val = $df !== 0.0 ? (float)$n / $df : 0;
        if ($val < 1 && $val > 0) {
            return '1/' . round(1 / $val) . 's';
        }
        return round($val, 4) . 's';
    }
    return $frac . 's';
}

function gps_to_decimal(array $dms, string $ref): float
{
    $deg = fraction_float((string)$dms[0]);
    $min = fraction_float((string)$dms[1]);
    $sec = fraction_float((string)$dms[2]);
    $val = $deg + $min / 60.0 + $sec / 3600.0;
    return ($ref === 'S' || $ref === 'W') ? -round($val, 6) : round($val, 6);
}

function read_csv_preview(string $path, int $max_rows = 5): array
{
    $rows = [];
    if (($fh = @fopen($path, 'r')) === false) return [];
    $count = 0;
    while ($count <= $max_rows && ($row = fgetcsv($fh)) !== false) {
        $rows[] = $row;
        $count++;
    }
    fclose($fh);
    // Count total data rows by re-reading without loading entire file into memory
    $total = 0;
    if (($fh2 = @fopen($path, 'r')) !== false) {
        while (fgetcsv($fh2) !== false) $total++;
        fclose($fh2);
    }
    $total = max(0, $total - 1); // subtract header row
    return ['headers' => $rows[0] ?? [], 'sample' => array_slice($rows, 1), 'total_rows' => $total];
}

// ─── RECURSIVE SEARCH ────────────────────────────────────────────────────────

function search_files(string $base_path, string $query, int $max = 200): array
{
    if (strlen($query) < 2) {
        return ['success' => true, 'items' => [], 'query' => $query];
    }

    $results = [];
    $stack   = [$base_path];

    while (!empty($stack) && count($results) < $max) {
        $dir     = array_pop($stack);
        $entries = @scandir($dir);
        if (!$entries) continue;

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') continue;
            if ($name[0] === '.') continue;
            if ($name === basename(__FILE__)) continue;

            $fp = $dir . DIRECTORY_SEPARATOR . $name;

            if (stripos($name, $query) !== false) {
                $is_dir = is_dir($fp);
                $stat   = @stat($fp);
                if (!$stat) continue;
                $ext  = $is_dir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $results[] = [
                    'name'      => $name,
                    'path'      => str_replace(BASE_DIR, '', $fp),
                    'is_dir'    => $is_dir,
                    'size'      => $is_dir ? null : (int)$stat['size'],
                    'modified'  => (int)$stat['mtime'],
                    'type'      => $is_dir ? 'directory' : get_file_type($ext),
                    'extension' => $ext,
                    'is_image'  => in_array($ext, IMAGE_EXTS, true),
                ];
            }
            if (is_dir($fp) && count($results) < $max) {
                $stack[] = $fp;
            }
        }
    }

    return ['success' => true, 'items' => $results, 'query' => $query, 'truncated' => count($results) >= $max];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Orbit · Universal Folder Browser</title>
  <meta name="description" content="Interactive browser for any web-exposed folder or file collection" />
  <style>
    /* ── Reset & Base ─────────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 15px; scroll-behavior: smooth; }

    /* ── Theme tokens ─────────────────────────────────────────────── */
    :root {
      --c-primary:    #0053a1;
      --c-primary-h:  #003f7a;
      --c-accent:     #e8650a;
      --c-bg:         #f4f6f9;
      --c-surface:    #ffffff;
      --c-surface-2:  #f0f3f7;
      --c-border:     #dde3ec;
      --c-text:       #1a202c;
      --c-text-2:     #5a6476;
      --c-text-3:     #9aa0ad;
      --c-success:    #1a7f37;
      --c-danger:     #c0392b;
      --c-shadow:     rgba(0,0,0,.08);
      --c-shadow-lg:  rgba(0,0,0,.18);
      --radius:       8px;
      --radius-lg:    14px;
      --header-h:     58px;
      --toolbar-h:    52px;
      --transition:   .18s ease;
    }
    [data-theme="dark"] {
      --c-bg:        #0f1117;
      --c-surface:   #1a1e28;
      --c-surface-2: #232736;
      --c-border:    #2e3547;
      --c-text:      #e2e8f0;
      --c-text-2:    #9aaabb;
      --c-text-3:    #586070;
      --c-shadow:    rgba(0,0,0,.35);
      --c-shadow-lg: rgba(0,0,0,.55);
    }

    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: var(--c-bg);
      color: var(--c-text);
      line-height: 1.55;
      min-height: 100vh;
    }

    /* ── Header ───────────────────────────────────────────────────── */
    #header {
      position: sticky; top: 0; z-index: 200;
      height: var(--header-h);
      background: var(--c-primary);
      display: flex; align-items: center; gap: 12px;
      padding: 0 20px;
      box-shadow: 0 2px 8px var(--c-shadow-lg);
    }
    #header .logo {
      display: flex; align-items: center; gap: 10px;
      color: #fff; text-decoration: none; flex-shrink: 0;
    }
    #header .logo svg { width: 32px; height: 32px; }
    #header .logo-text { font-size: 1.1rem; font-weight: 700; letter-spacing: .02em; }
    #header .logo-sub  { font-size: .72rem; opacity: .75; letter-spacing: .04em; }

    /* search in header */
    #header-search-wrap {
      flex: 1; max-width: 520px; margin-left: auto;
      position: relative;
    }
    #header-search {
      width: 100%; padding: 7px 16px 7px 36px;
      border: none; border-radius: 20px;
      background: rgba(255,255,255,.18); color: #fff;
      font-size: .9rem; outline: none;
      transition: background var(--transition);
    }
    #header-search::placeholder { color: rgba(255,255,255,.6); }
    #header-search:focus { background: rgba(255,255,255,.28); }
    #search-icon {
      position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
      color: rgba(255,255,255,.7); pointer-events: none;
    }
    #search-clear {
      position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
      background: none; border: none; color: rgba(255,255,255,.7);
      cursor: pointer; display: none; font-size: 1rem; line-height: 1;
      padding: 2px 4px;
    }
    #search-clear.visible { display: block; }

    #btn-dark { margin-left: 10px; }
    .icon-btn {
      background: none; border: none; cursor: pointer;
      color: rgba(255,255,255,.85); padding: 6px;
      border-radius: var(--radius); transition: background var(--transition);
      display: flex; align-items: center;
    }
    .icon-btn:hover { background: rgba(255,255,255,.15); }

    /* ── Breadcrumb ───────────────────────────────────────────────── */
    #breadcrumb-bar {
      background: var(--c-surface); border-bottom: 1px solid var(--c-border);
      padding: 0 20px; height: 40px;
      display: flex; align-items: center; gap: 4px;
      font-size: .85rem; overflow-x: auto; white-space: nowrap;
      scrollbar-width: none;
    }
    #breadcrumb-bar::-webkit-scrollbar { display: none; }
    .bc-item { color: var(--c-text-2); display: flex; align-items: center; gap: 4px; }
    .bc-item a {
      color: var(--c-primary); text-decoration: none; padding: 2px 6px;
      border-radius: 4px; transition: background var(--transition);
    }
    .bc-item a:hover { background: var(--c-surface-2); }
    .bc-sep { color: var(--c-text-3); font-size: .8rem; }
    .bc-current { color: var(--c-text); font-weight: 600; padding: 2px 6px; }

    /* ── Toolbar ──────────────────────────────────────────────────── */
    #toolbar {
      background: var(--c-surface); border-bottom: 1px solid var(--c-border);
      padding: 0 20px; height: var(--toolbar-h);
      display: flex; align-items: center; gap: 10px;
      flex-wrap: wrap;
    }
    .toolbar-group { display: flex; align-items: center; gap: 6px; }
    .toolbar-sep { width: 1px; height: 24px; background: var(--c-border); margin: 0 4px; }

    /* Sort select */
    .tb-select {
      padding: 5px 28px 5px 10px;
      border: 1px solid var(--c-border); border-radius: var(--radius);
      background: var(--c-surface); color: var(--c-text);
      font-size: .84rem; cursor: pointer; outline: none;
      appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath fill='%239aa0ad' d='M0 0l5 6 5-6z'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 8px center;
    }
    .tb-select:focus { border-color: var(--c-primary); }

    /* view toggle */
    .view-btn {
      background: none; border: 1px solid var(--c-border);
      border-radius: var(--radius); padding: 5px 8px;
      cursor: pointer; color: var(--c-text-2);
      transition: all var(--transition);
    }
    .view-btn.active, .view-btn:hover {
      background: var(--c-primary); color: #fff; border-color: var(--c-primary);
    }

    /* filter chips */
    .filter-chip {
      padding: 4px 12px; border-radius: 20px;
      border: 1px solid var(--c-border);
      background: var(--c-surface); color: var(--c-text-2);
      font-size: .8rem; cursor: pointer;
      transition: all var(--transition); white-space: nowrap;
    }
    .filter-chip:hover { border-color: var(--c-primary); color: var(--c-primary); }
    .filter-chip.active { background: var(--c-primary); color: #fff; border-color: var(--c-primary); }

    #filter-scroll {
      display: flex; gap: 6px; overflow-x: auto;
      scrollbar-width: none; flex: 1;
    }
    #filter-scroll::-webkit-scrollbar { display: none; }

    /* stats */
    #stats { margin-left: auto; font-size: .8rem; color: var(--c-text-3); white-space: nowrap; }

    /* ── Content layout ───────────────────────────────────────────── */
    #content-wrap { display: flex; min-height: calc(100vh - var(--header-h) - 40px - var(--toolbar-h)); }
    #main { flex: 1; padding: 20px; min-width: 0; }

    /* ── Loading / Empty / Error states ──────────────────────────── */
    .state-box {
      display: flex; flex-direction: column; align-items: center;
      justify-content: center; gap: 12px;
      padding: 60px 20px; color: var(--c-text-2); text-align: center;
    }
    .state-box svg { opacity: .35; }
    .state-box p { font-size: .95rem; }

    /* ── Spinner ──────────────────────────────────────────────────── */
    .spinner {
      width: 40px; height: 40px; border-radius: 50%;
      border: 3px solid var(--c-border);
      border-top-color: var(--c-primary);
      animation: spin .7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Grid view ────────────────────────────────────────────────── */
    #file-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
      gap: 14px;
    }
    .file-card {
      background: var(--c-surface); border: 1px solid var(--c-border);
      border-radius: var(--radius-lg); overflow: hidden;
      cursor: pointer; transition: all var(--transition);
      display: flex; flex-direction: column;
      position: relative;
    }
    .file-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px var(--c-shadow-lg);
      border-color: var(--c-primary);
    }
    .file-card.selected { border-color: var(--c-primary); box-shadow: 0 0 0 2px rgba(0,83,161,.3); }

    .card-thumb {
      width: 100%; aspect-ratio: 4/3;
      background: var(--c-surface-2);
      display: flex; align-items: center; justify-content: center;
      overflow: hidden; position: relative;
    }
    .card-thumb img {
      width: 100%; height: 100%; object-fit: cover;
      transition: transform .3s ease;
    }
    .file-card:hover .card-thumb img { transform: scale(1.05); }
    .card-thumb .type-icon { font-size: 2.4rem; }

    .card-info { padding: 10px 12px; flex: 1; }
    .card-name {
      font-size: .82rem; font-weight: 600; color: var(--c-text);
      word-break: break-word; line-height: 1.3;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .card-meta { font-size: .72rem; color: var(--c-text-3); margin-top: 4px; }

    /* ── List view ────────────────────────────────────────────────── */
    #file-list { display: flex; flex-direction: column; gap: 2px; }
    .list-item {
      background: var(--c-surface); border: 1px solid var(--c-border);
      border-radius: var(--radius); padding: 10px 16px;
      display: grid;
      grid-template-columns: 32px 1fr 100px 140px 90px;
      align-items: center; gap: 12px;
      cursor: pointer; transition: all var(--transition);
    }
    .list-item:hover { background: var(--c-surface-2); border-color: var(--c-primary); }
    .list-item.selected { background: rgba(0,83,161,.06); border-color: var(--c-primary); }

    .list-icon { text-align: center; font-size: 1.2rem; flex-shrink: 0; }
    .list-name {
      font-size: .88rem; font-weight: 500; color: var(--c-text);
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .list-size, .list-date, .list-type {
      font-size: .78rem; color: var(--c-text-2); text-align: right;
    }
    .list-type { text-align: left; }

    .list-header {
      padding: 6px 16px; display: grid;
      grid-template-columns: 32px 1fr 100px 140px 90px;
      gap: 12px; font-size: .75rem; font-weight: 600;
      color: var(--c-text-3); text-transform: uppercase; letter-spacing: .05em;
    }
    .list-header span { cursor: pointer; }
    .list-header span:hover { color: var(--c-primary); }

    /* image thumb in list */
    .list-img-thumb {
      width: 28px; height: 28px; border-radius: 4px;
      object-fit: cover;
    }

    /* ── Badge / type tag ─────────────────────────────────────────── */
    .type-badge {
      display: inline-block; padding: 1px 7px;
      border-radius: 20px; font-size: .68rem; font-weight: 600;
      text-transform: uppercase; letter-spacing: .04em;
    }
    .badge-image    { background: #e0f2fe; color: #0369a1; }
    .badge-pdf      { background: #fee2e2; color: #b91c1c; }
    .badge-video    { background: #fef3c7; color: #b45309; }
    .badge-audio    { background: #f0fdf4; color: #166534; }
    .badge-code     { background: #f3e8ff; color: #7e22ce; }
    .badge-data     { background: #fdf2f8; color: #9d174d; }
    .badge-document { background: #eff6ff; color: #1d4ed8; }
    .badge-archive  { background: #fff7ed; color: #c2410c; }
    .badge-notebook { background: #fefce8; color: #854d0e; }
    .badge-directory{ background: #e0f2fe; color: #0369a1; }
    .badge-other    { background: var(--c-surface-2); color: var(--c-text-2); }
    [data-theme="dark"] .badge-image    { background: #0c4a6e; color: #7dd3fc; }
    [data-theme="dark"] .badge-pdf      { background: #4c0519; color: #fca5a5; }
    [data-theme="dark"] .badge-code     { background: #3b0764; color: #d8b4fe; }
    [data-theme="dark"] .badge-data     { background: #4a044e; color: #f0abfc; }
    [data-theme="dark"] .badge-document { background: #1e3a8a; color: #93c5fd; }
    [data-theme="dark"] .badge-notebook { background: #422006; color: #fde68a; }
    [data-theme="dark"] .badge-archive  { background: #431407; color: #fdba74; }
    [data-theme="dark"] .badge-directory{ background: #0c4a6e; color: #7dd3fc; }
    [data-theme="dark"] .badge-other    { background: var(--c-surface-2); color: var(--c-text-2); }

    /* ── Preview Modal ────────────────────────────────────────────── */
    #preview-overlay {
      position: fixed; inset: 0; z-index: 500;
      background: rgba(0,0,0,.82); backdrop-filter: blur(4px);
      display: flex; flex-direction: column;
      opacity: 0; pointer-events: none;
      transition: opacity .22s ease;
    }
    #preview-overlay.open { opacity: 1; pointer-events: all; }

    #preview-header {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 20px; background: rgba(0,0,0,.5);
      flex-shrink: 0;
    }
    #preview-title { color: #fff; font-size: .95rem; font-weight: 600; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    #preview-actions { display: flex; gap: 8px; }
    .prev-btn {
      background: rgba(255,255,255,.12); border: none;
      color: #fff; cursor: pointer; border-radius: var(--radius);
      padding: 6px 14px; font-size: .84rem;
      display: flex; align-items: center; gap: 6px;
      transition: background var(--transition);
    }
    .prev-btn:hover { background: rgba(255,255,255,.24); }
    #preview-close {
      background: rgba(220,0,0,.5); border: none; color: #fff;
      border-radius: var(--radius); width: 32px; height: 32px;
      cursor: pointer; font-size: 1.1rem; display: flex;
      align-items: center; justify-content: center;
      transition: background var(--transition);
    }
    #preview-close:hover { background: rgba(220,0,0,.8); }

    #preview-body {
      flex: 1; display: flex; align-items: center; justify-content: center;
      overflow: auto; padding: 16px;
    }

    /* image preview */
    #preview-img {
      max-width: 100%; max-height: calc(100vh - 160px);
      border-radius: var(--radius);
      box-shadow: 0 8px 40px rgba(0,0,0,.6);
      cursor: zoom-in; user-select: none;
      transition: transform .2s ease;
    }
    #preview-img.zoomed { cursor: zoom-out; transform: scale(1.8); }

    /* PDF preview */
    #preview-iframe {
      width: min(900px, 95vw); height: calc(100vh - 130px);
      border: none; border-radius: var(--radius);
      background: #fff;
    }

    /* text/code preview */
    #preview-text {
      width: min(900px, 95vw); max-height: calc(100vh - 130px);
      overflow: auto; background: #1e1e2e; color: #cdd6f4;
      border-radius: var(--radius); padding: 20px;
      font-family: "SF Mono", "Fira Code", "Cascadia Code", monospace;
      font-size: .85rem; line-height: 1.6; white-space: pre;
      tab-size: 4;
    }

    /* video/audio */
    #preview-video {
      max-width: min(860px, 95vw); max-height: calc(100vh - 130px);
      border-radius: var(--radius);
    }
    #preview-audio { width: min(400px, 90vw); }

    /* CSV table */
    #preview-csv-wrap {
      width: min(900px, 95vw); max-height: calc(100vh - 130px);
      overflow: auto; background: var(--c-surface);
      border-radius: var(--radius);
    }
    #preview-csv table { width: 100%; border-collapse: collapse; font-size: .82rem; }
    #preview-csv th { background: var(--c-primary); color: #fff; padding: 8px 12px; text-align: left; position: sticky; top: 0; }
    #preview-csv td { padding: 6px 12px; border-bottom: 1px solid var(--c-border); }
    #preview-csv tr:hover td { background: var(--c-surface-2); }

    /* nav arrows */
    .nav-arrow {
      position: fixed; top: 50%; z-index: 510;
      background: rgba(255,255,255,.15); border: none;
      color: #fff; width: 44px; height: 44px;
      border-radius: 50%; cursor: pointer; font-size: 1.4rem;
      display: flex; align-items: center; justify-content: center;
      transform: translateY(-50%);
      transition: background var(--transition);
    }
    .nav-arrow:hover { background: rgba(255,255,255,.3); }
    #nav-prev { left: 12px; }
    #nav-next { right: 12px; }
    .nav-arrow.hidden { display: none; }

    /* ── Metadata panel ───────────────────────────────────────────── */
    #meta-panel {
      width: 320px; flex-shrink: 0;
      background: var(--c-surface); border-left: 1px solid var(--c-border);
      overflow-y: auto; padding: 0;
      transform: translateX(100%);
      transition: transform .25s ease;
      position: fixed; right: 0; top: var(--header-h); bottom: 0;
      z-index: 150;
    }
    #meta-panel.open { transform: translateX(0); }

    #meta-panel-header {
      padding: 14px 16px; background: var(--c-surface-2);
      border-bottom: 1px solid var(--c-border);
      display: flex; align-items: center; gap: 10px; position: sticky; top: 0;
    }
    #meta-panel-header h3 { flex: 1; font-size: .9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    #meta-panel-close { background: none; border: none; cursor: pointer; color: var(--c-text-2); padding: 4px; border-radius: var(--radius); }
    #meta-panel-close:hover { background: var(--c-surface); }

    .meta-section { padding: 12px 16px; border-bottom: 1px solid var(--c-border); }
    .meta-section h4 { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; color: var(--c-text-3); margin-bottom: 8px; }
    .meta-row { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 5px; font-size: .82rem; }
    .meta-key { color: var(--c-text-2); flex-shrink: 0; }
    .meta-val { color: var(--c-text); text-align: right; word-break: break-all; }

    .meta-img-thumb {
      width: 100%; border-radius: var(--radius); margin-bottom: 12px;
      cursor: pointer;
    }

    /* ── Btn styles ───────────────────────────────────────────────── */
    .btn-primary {
      background: var(--c-primary); color: #fff; border: none;
      padding: 7px 18px; border-radius: var(--radius);
      cursor: pointer; font-size: .88rem; font-weight: 600;
      transition: background var(--transition);
    }
    .btn-primary:hover { background: var(--c-primary-h); }

    /* ── Toast ────────────────────────────────────────────────────── */
    #toast-container {
      position: fixed; bottom: 24px; right: 24px; z-index: 900;
      display: flex; flex-direction: column; gap: 8px; pointer-events: none;
    }
    .toast {
      background: #1e1e2e; color: #cdd6f4;
      padding: 10px 18px; border-radius: var(--radius);
      font-size: .85rem; box-shadow: 0 4px 16px rgba(0,0,0,.4);
      animation: slide-in .2s ease, fade-out .3s ease 2.5s forwards;
      pointer-events: all;
    }
    @keyframes slide-in   { from { transform: translateX(30px); opacity: 0; } }
    @keyframes fade-out   { to   { opacity: 0; transform: translateX(10px); } }

    /* ── Responsive ───────────────────────────────────────────────── */
    @media (max-width: 900px) {
      #file-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }
      .list-item { grid-template-columns: 32px 1fr 90px; }
      .list-size, .list-type { display: none; }
    }
    @media (max-width: 600px) {
      #header { padding: 0 12px; }
      #main   { padding: 12px; }
      #toolbar { padding: 0 12px; height: auto; padding-top: 8px; padding-bottom: 8px; }
      #breadcrumb-bar { padding: 0 12px; }
      .logo-sub { display: none; }
      .list-item { grid-template-columns: 32px 1fr; }
      .list-date  { display: none; }
      #file-grid { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; }
    }
    @media (max-width: 420px) {
      #header-search-wrap { display: none; }
    }
  </style>
</head>
<body>

<!-- ── Header ─────────────────────────────────────────────────────────── -->
<header id="header">
  <a class="logo" href="?path=/" id="logo-link">
    <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="16" cy="16" r="15" stroke="white" stroke-width="2"/>
      <path d="M8 16a8 8 0 0 1 16 0" stroke="white" stroke-width="1.5"/>
      <ellipse cx="16" cy="16" rx="5" ry="8" stroke="white" stroke-width="1.5"/>
      <line x1="8" y1="16" x2="24" y2="16" stroke="white" stroke-width="1.5"/>
    </svg>
    <div>
      <div class="logo-text">Orbit</div>
      <div class="logo-sub">Universal folder browser</div>
    </div>
  </a>

  <div id="header-search-wrap">
    <svg id="search-icon" width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
      <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/>
    </svg>
    <input type="search" id="header-search" placeholder="Search files and folders…" autocomplete="off" />
    <button id="search-clear" aria-label="Clear search">✕</button>
  </div>

  <button class="icon-btn" id="btn-dark" title="Toggle dark mode" aria-label="Toggle dark mode">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </svg>
  </button>
</header>

<!-- ── Breadcrumb ─────────────────────────────────────────────────────── -->
<nav id="breadcrumb-bar" aria-label="Breadcrumb"></nav>

<!-- ── Toolbar ────────────────────────────────────────────────────────── -->
<div id="toolbar" role="toolbar" aria-label="File browser controls">
  <div class="toolbar-group" id="filter-scroll">
    <!-- filter chips injected by JS -->
  </div>
  <div class="toolbar-sep"></div>
  <div class="toolbar-group">
    <select id="sort-select" class="tb-select" aria-label="Sort by">
      <option value="name-asc">Name A→Z</option>
      <option value="name-desc">Name Z→A</option>
      <option value="date-desc">Newest first</option>
      <option value="date-asc">Oldest first</option>
      <option value="size-desc">Largest first</option>
      <option value="size-asc">Smallest first</option>
      <option value="type-asc">Type</option>
    </select>
  </div>
  <div class="toolbar-group">
    <button class="view-btn active" id="btn-grid" title="Grid view" aria-label="Grid view">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
        <rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/>
        <rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/>
      </svg>
    </button>
    <button class="view-btn" id="btn-list" title="List view" aria-label="List view">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
        <rect x="1" y="2" width="14" height="2" rx="1"/><rect x="1" y="7" width="14" height="2" rx="1"/>
        <rect x="1" y="12" width="14" height="2" rx="1"/>
      </svg>
    </button>
  </div>
  <span id="stats"></span>
</div>

<!-- ── Main content ───────────────────────────────────────────────────── -->
<div id="content-wrap">
  <main id="main">
    <div id="file-container">
      <div class="state-box" id="loading-state">
        <div class="spinner"></div>
        <p>Loading…</p>
      </div>
    </div>
  </main>
</div>

<!-- ── Metadata panel ─────────────────────────────────────────────────── -->
<aside id="meta-panel" role="complementary" aria-label="File metadata">
  <div id="meta-panel-header">
    <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" style="color:var(--c-text-2)">
      <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
    </svg>
    <h3 id="meta-panel-title">Details</h3>
    <button id="meta-panel-close" aria-label="Close metadata panel">✕</button>
  </div>
  <div id="meta-panel-body"></div>
</aside>

<!-- ── Preview overlay ────────────────────────────────────────────────── -->
<div id="preview-overlay" role="dialog" aria-modal="true" aria-label="File preview">
  <div id="preview-header">
    <span id="preview-title"></span>
    <div id="preview-actions">
      <button class="prev-btn" id="btn-preview-meta" title="Show metadata">
        <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
        </svg>
        Info
      </button>
      <button class="prev-btn" id="btn-copy-link" title="Copy link">
        <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
          <path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/><path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z"/>
        </svg>
        Copy link
      </button>
      <a class="prev-btn" id="btn-download" href="#" download title="Download">
        <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/>
        </svg>
        Download
      </a>
      <button id="preview-close" aria-label="Close preview">✕</button>
    </div>
  </div>
  <div id="preview-body"></div>
  <button class="nav-arrow hidden" id="nav-prev" aria-label="Previous file">‹</button>
  <button class="nav-arrow hidden" id="nav-next" aria-label="Next file">›</button>
</div>

<!-- ── Toast container ────────────────────────────────────────────────── -->
<div id="toast-container" aria-live="polite"></div>

<!-- ── Application ────────────────────────────────────────────────────── -->
<script>
'use strict';

// ═══════════════════════════════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════════════════════════════
const CONFIG = {
  defaultView:  'grid',          // 'grid' | 'list'
  previewText:  ['txt','md','rst','json','yaml','yml','xml','csv','py','js',
                 'ts','html','css','sh','r','cpp','c','h','java','go','rs','tex'],
  maxTextSize:  512 * 1024,      // 512 KB
  searchDelay:  300,             // ms debounce
  imgLazyThreshold: 200,         // px below viewport
};

const FILTERS = [
  { id: 'all',       label: '🗂 All',        types: null },
  { id: 'directory', label: '📁 Folders',    types: ['directory'] },
  { id: 'image',     label: '🖼 Images',     types: ['image'] },
  { id: 'pdf',       label: '📄 PDF',        types: ['pdf'] },
  { id: 'video',     label: '🎬 Video',      types: ['video'] },
  { id: 'audio',     label: '🎵 Audio',      types: ['audio'] },
  { id: 'data',      label: '📊 Data',       types: ['data'] },
  { id: 'code',      label: '💻 Code',       types: ['code'] },
  { id: 'notebook',  label: '📓 Notebooks',  types: ['notebook'] },
  { id: 'document',  label: '📝 Documents',  types: ['document'] },
  { id: 'archive',   label: '📦 Archives',   types: ['archive'] },
];

const FILE_ICONS = {
  directory: '📁', image: '🖼', pdf: '📄', video: '🎬', audio: '🎵',
  code: '💻', data: '📊', document: '📝', archive: '📦', notebook: '📓',
  other: '📄',
};

// ═══════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════
const state = {
  path:           '/',
  items:          [],            // raw items from API
  filtered:       [],            // after filter + search + sort
  view:           CONFIG.defaultView,
  activeFilter:   'all',
  sort:           'name-asc',
  searchQuery:    '',
  isSearching:    false,
  previewIndex:   -1,            // index into filtered array
  metaPanelOpen:  false,
  darkMode:       false,
};

// ═══════════════════════════════════════════════════════════════
// API
// ═══════════════════════════════════════════════════════════════
async function apiFetch(params) {
  const url = '?' + new URLSearchParams(params).toString();
  const res  = await fetch(url);
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = await res.json();
  if (!data.success && data.error) throw new Error(data.error);
  return data;
}

async function loadDirectory(path) {
  const data = await apiFetch({ action: 'list', path });
  return data;
}

async function loadMeta(path) {
  return apiFetch({ action: 'meta', path });
}

async function doSearch(path, query) {
  return apiFetch({ action: 'search', path, query });
}

// ═══════════════════════════════════════════════════════════════
// NAVIGATION
// ═══════════════════════════════════════════════════════════════
function getCurrentPathFromURL() {
  const params = new URLSearchParams(window.location.search);
  return params.get('path') || '/';
}

function pushState(path) {
  const url = path === '/' ? '?' : `?path=${encodeURIComponent(path)}`;
  history.pushState({ path }, '', url);
}

async function navigateTo(path, pushHistory = true) {
  showLoading();
  closePreview();
  try {
    const data = await loadDirectory(path);
    state.path  = data.path;
    state.items = data.items;
    state.searchQuery  = '';
    state.activeFilter = 'all';
    state.isSearching  = false;
    document.getElementById('header-search').value = '';
    document.getElementById('search-clear').classList.remove('visible');

    if (pushHistory) pushState(path);
    applyFiltersAndSort();
    renderBreadcrumb(data.path, data.parent);
    renderFilterChips();
    updateStats();
  } catch (err) {
    showError(err.message);
  }
}

// ═══════════════════════════════════════════════════════════════
// FILTER / SORT
// ═══════════════════════════════════════════════════════════════
function applyFiltersAndSort() {
  let items = [...state.items];

  // 1. active filter
  const f = FILTERS.find(f => f.id === state.activeFilter);
  if (f && f.types) {
    items = items.filter(i => f.types.includes(i.type));
  }

  // 2. search query (client-side, on current directory items)
  if (state.searchQuery) {
    const q = state.searchQuery.toLowerCase();
    items = items.filter(i => i.name.toLowerCase().includes(q));
  }

  // 3. sort
  const [field, dir] = state.sort.split('-');
  items.sort((a, b) => {
    // always directories first
    if (a.is_dir !== b.is_dir) return a.is_dir ? -1 : 1;

    let va, vb;
    if (field === 'name')  { va = a.name.toLowerCase(); vb = b.name.toLowerCase(); }
    else if (field === 'date') { va = a.modified; vb = b.modified; }
    else if (field === 'size') { va = a.size ?? -1; vb = b.size ?? -1; }
    else if (field === 'type') { va = a.type; vb = b.type; }

    if (va < vb) return dir === 'asc' ? -1 : 1;
    if (va > vb) return dir === 'asc' ?  1 : -1;
    return 0;
  });

  state.filtered = items;
  renderFiles();
  updateStats();
}

// ═══════════════════════════════════════════════════════════════
// RENDER — BREADCRUMB
// ═══════════════════════════════════════════════════════════════
function renderBreadcrumb(path, parent) {
  const bar  = document.getElementById('breadcrumb-bar');
  const parts = path === '/' ? [] : path.split('/').filter(Boolean);
  let html = `<span class="bc-item"><a href="javascript:void(0)" data-nav="/">🏠 Home</a></span>`;

  let accumulated = '';
  parts.forEach((part, i) => {
    accumulated += '/' + part;
    const isLast = i === parts.length - 1;
    html += `<span class="bc-sep">›</span>`;
    if (isLast) {
      html += `<span class="bc-current">${esc(part)}</span>`;
    } else {
      const p = accumulated;
      html += `<span class="bc-item"><a href="javascript:void(0)" data-nav="${esc(p)}">${esc(part)}</a></span>`;
    }
  });

  bar.innerHTML = html;
  bar.querySelectorAll('[data-nav]').forEach(a => {
    a.addEventListener('click', () => navigateTo(a.dataset.nav));
  });
}

// ═══════════════════════════════════════════════════════════════
// RENDER — FILTER CHIPS
// ═══════════════════════════════════════════════════════════════
function renderFilterChips() {
  const wrap = document.getElementById('filter-scroll');
  const counts = {};
  state.items.forEach(i => { counts[i.type] = (counts[i.type] || 0) + 1; });

  wrap.innerHTML = FILTERS.map(f => {
    let count = 0;
    if (!f.types) { count = state.items.length; }
    else { f.types.forEach(t => { count += (counts[t] || 0); }); }
    if (f.id !== 'all' && count === 0) return '';
    const active = state.activeFilter === f.id ? 'active' : '';
    return `<button class="filter-chip ${active}" data-filter="${f.id}">${f.label} <span style="opacity:.65">${count}</span></button>`;
  }).join('');

  wrap.querySelectorAll('.filter-chip').forEach(btn => {
    btn.addEventListener('click', () => {
      state.activeFilter = btn.dataset.filter;
      wrap.querySelectorAll('.filter-chip').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      applyFiltersAndSort();
    });
  });
}

// ═══════════════════════════════════════════════════════════════
// RENDER — FILES (grid + list)
// ═══════════════════════════════════════════════════════════════
function renderFiles() {
  const container = document.getElementById('file-container');

  if (state.filtered.length === 0) {
    container.innerHTML = `
      <div class="state-box">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
          <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
        </svg>
        <p>${state.searchQuery ? `No results for "<strong>${esc(state.searchQuery)}</strong>"` : 'This folder is empty'}</p>
      </div>`;
    return;
  }

  if (state.view === 'grid') {
    container.innerHTML = `<div id="file-grid">${state.filtered.map((item, idx) => renderGridCard(item, idx)).join('')}</div>`;
  } else {
    container.innerHTML = `
      <div class="list-header">
        <span></span>
        <span data-sort-field="name">Name</span>
        <span data-sort-field="size" style="text-align:right">Size</span>
        <span data-sort-field="date" style="text-align:right">Modified</span>
        <span data-sort-field="type">Type</span>
      </div>
      <div id="file-list">${state.filtered.map((item, idx) => renderListItem(item, idx)).join('')}</div>`;
    // header sort clicks
    container.querySelectorAll('[data-sort-field]').forEach(el => {
      el.addEventListener('click', () => {
        const field = el.dataset.sortField;
        const [cur, dir] = state.sort.split('-');
        state.sort = (cur === field && dir === 'asc') ? `${field}-desc` : `${field}-asc`;
        document.getElementById('sort-select').value = state.sort;
        applyFiltersAndSort();
      });
    });
  }

  // click handlers
  container.querySelectorAll('[data-item-idx]').forEach(el => {
    el.addEventListener('click', (e) => {
      const idx  = parseInt(el.dataset.itemIdx, 10);
      const item = state.filtered[idx];
      if (!item) return;
      if (item.is_dir) {
        navigateTo(item.path);
      } else {
        openPreview(idx);
      }
    });
    el.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      const idx  = parseInt(el.dataset.itemIdx, 10);
      const item = state.filtered[idx];
      if (item && !item.is_dir) showMetaPanel(item);
    });
  });

  // lazy-load images
  lazyLoadImages();
}

function renderGridCard(item, idx) {
  const icon  = FILE_ICONS[item.type] || '📄';
  const thumb = item.is_image ? `<img data-src="${buildFileUrl(item.path)}" alt="${esc(item.name)}" loading="lazy">` : `<span class="type-icon">${icon}</span>`;
  const size  = item.size !== null ? formatSize(item.size) : '—';
  const badge = `<span class="type-badge badge-${item.type}">${item.type}</span>`;

  return `
    <div class="file-card" data-item-idx="${idx}" role="button" tabindex="0"
         title="${esc(item.name)}" aria-label="${esc(item.name)}">
      <div class="card-thumb">${thumb}</div>
      <div class="card-info">
        <div class="card-name">${esc(item.name)}</div>
        <div class="card-meta">${size} · ${formatDate(item.modified)}</div>
      </div>
    </div>`;
}

function renderListItem(item, idx) {
  const icon = item.is_image
    ? `<img class="list-img-thumb" data-src="${buildFileUrl(item.path)}" alt="">`
    : FILE_ICONS[item.type] || '📄';
  const size  = item.size !== null ? formatSize(item.size) : '—';
  const badge = `<span class="type-badge badge-${item.type}">${item.type}</span>`;
  return `
    <div class="list-item" data-item-idx="${idx}" role="button" tabindex="0"
         title="${esc(item.name)}" aria-label="${esc(item.name)}">
      <span class="list-icon">${icon}</span>
      <span class="list-name">${esc(item.name)}</span>
      <span class="list-size">${size}</span>
      <span class="list-date">${formatDate(item.modified)}</span>
      <span class="list-type">${badge}</span>
    </div>`;
}

// ═══════════════════════════════════════════════════════════════
// LAZY LOADING
// ═══════════════════════════════════════════════════════════════
function lazyLoadImages() {
  const imgs = document.querySelectorAll('[data-src]');
  if (!imgs.length) return;

  const observer = new IntersectionObserver((entries, obs) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const img = entry.target;
        img.src = img.dataset.src;
        img.removeAttribute('data-src');
        obs.unobserve(img);
      }
    });
  }, { rootMargin: `${CONFIG.imgLazyThreshold}px` });

  imgs.forEach(img => observer.observe(img));
}

// ═══════════════════════════════════════════════════════════════
// PREVIEW
// ═══════════════════════════════════════════════════════════════
function openPreview(idx) {
  const item = state.filtered[idx];
  if (!item || item.is_dir) return;
  state.previewIndex = idx;

  const overlay = document.getElementById('preview-overlay');
  document.getElementById('preview-title').textContent = item.name;
  const dl = document.getElementById('btn-download');
  dl.href = buildFileUrl(item.path, true);
  dl.download = item.name;

  const body = document.getElementById('preview-body');
  body.innerHTML = '<div class="state-box"><div class="spinner"></div><p>Loading preview…</p></div>';
  overlay.classList.add('open');
  document.body.style.overflow = 'hidden';

  updateNavArrows();
  renderPreviewContent(item, body);
}

async function renderPreviewContent(item, body) {
  const path = buildFileUrl(item.path);

  if (item.is_image) {
    body.innerHTML = `<img id="preview-img" src="${path}" alt="${esc(item.name)}" />`;
    const img = body.querySelector('#preview-img');
    img.addEventListener('click', () => img.classList.toggle('zoomed'));
    img.addEventListener('error', () => { body.innerHTML = errorBox('Could not load image'); });
    return;
  }

  if (item.type === 'pdf') {
    body.innerHTML = `<iframe id="preview-iframe" src="${path}" title="${esc(item.name)}"></iframe>`;
    return;
  }

  if (item.type === 'video') {
    body.innerHTML = `<video id="preview-video" src="${path}" controls autoplay muted></video>`;
    return;
  }

  if (item.type === 'audio') {
    body.innerHTML = `<audio id="preview-audio" src="${path}" controls autoplay></audio>`;
    return;
  }

  if (item.extension === 'ipynb') {
    await previewNotebook(item, body, path);
    return;
  }

  if (item.extension === 'csv') {
    await previewCSV(item, body, path);
    return;
  }

  if (CONFIG.previewText.includes(item.extension)) {
    await previewText(item, body, path);
    return;
  }

  body.innerHTML = `
    <div class="state-box" style="color:#aaa">
      <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
        <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/>
      </svg>
      <p>No preview available for <strong>.${item.extension}</strong></p>
      <a class="btn-primary" href="${buildFileUrl(item.path, true)}" download="${esc(item.name)}">⬇ Download</a>
    </div>`;
}

async function previewText(item, body, path) {
  try {
    const res = await fetch(path);
    if (!res.ok) throw new Error('Could not fetch file');
    const text = await res.text();
    const pre  = document.createElement('pre');
    pre.id = 'preview-text';
    pre.textContent = text;
    body.innerHTML = '';
    body.appendChild(pre);
  } catch (e) {
    body.innerHTML = errorBox(e.message);
  }
}

async function previewCSV(item, body, path) {
  try {
    const res  = await fetch(path);
    if (!res.ok) throw new Error('Could not fetch CSV');
    const text = await res.text();
    const rows = parseCSVText(text, 100);
    if (!rows.length) { body.innerHTML = errorBox('Empty CSV'); return; }
    const headers = rows[0];
    const data    = rows.slice(1);
    let html = `<div id="preview-csv-wrap"><div id="preview-csv"><table>
      <thead><tr>${headers.map(h => `<th>${esc(h)}</th>`).join('')}</tr></thead>
      <tbody>${data.map(r => `<tr>${r.map(c => `<td>${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody>
    </table></div></div>`;
    body.innerHTML = html;
  } catch (e) {
    body.innerHTML = errorBox(e.message);
  }
}

async function previewNotebook(item, body, path) {
  try {
    const res  = await fetch(path);
    if (!res.ok) throw new Error('Could not fetch notebook');
    const nb   = await res.json();
    const cells = nb.cells || [];
    let html = `<div style="width:min(860px,95vw);max-height:calc(100vh - 130px);overflow:auto;background:var(--c-surface);border-radius:var(--radius);padding:20px;font-size:.87rem">`;
    html += `<h2 style="margin-bottom:16px;color:var(--c-text)">${esc(item.name)}</h2>`;
    cells.forEach(cell => {
      const src = Array.isArray(cell.source) ? cell.source.join('') : (cell.source || '');
      if (cell.cell_type === 'markdown') {
        html += `<div style="padding:8px 0;color:var(--c-text);line-height:1.7">${renderMarkdownBasic(src)}</div>`;
      } else if (cell.cell_type === 'code') {
        html += `<pre style="background:#1e1e2e;color:#cdd6f4;padding:12px;border-radius:6px;margin:8px 0;font-family:monospace;font-size:.82rem;overflow-x:auto;white-space:pre">${esc(src)}</pre>`;
        const outputs = cell.outputs || [];
        outputs.forEach(out => {
          const txt = Array.isArray(out.text) ? out.text.join('') : (out.text || '');
          if (txt) html += `<pre style="background:var(--c-surface-2);color:var(--c-text-2);padding:8px;border-radius:4px;font-size:.78rem;overflow-x:auto;white-space:pre">${esc(txt)}</pre>`;
          if (out.data && out.data['image/png']) {
            html += `<img src="data:image/png;base64,${out.data['image/png']}" style="max-width:100%;border-radius:4px;margin:4px 0" alt="cell output">`;
          }
        });
      }
    });
    html += '</div>';
    body.innerHTML = html;
  } catch (e) {
    body.innerHTML = errorBox(e.message);
  }
}

function closePreview() {
  const overlay = document.getElementById('preview-overlay');
  overlay.classList.remove('open');
  document.body.style.overflow = '';
  state.previewIndex = -1;
  // clear media to stop playback
  const video = document.getElementById('preview-video');
  const audio = document.getElementById('preview-audio');
  if (video) { video.pause(); video.src = ''; }
  if (audio) { audio.pause(); audio.src = ''; }
}

function navigatePreview(delta) {
  const newIdx = state.previewIndex + delta;
  if (newIdx < 0 || newIdx >= state.filtered.length) return;
  const item = state.filtered[newIdx];
  if (item.is_dir) return;
  openPreview(newIdx);
}

function updateNavArrows() {
  const prev = document.getElementById('nav-prev');
  const next = document.getElementById('nav-next');
  const idx  = state.previewIndex;
  // look for previous / next non-directory
  const hasPrev = state.filtered.slice(0, idx).some(i => !i.is_dir);
  const hasNext = state.filtered.slice(idx + 1).some(i => !i.is_dir);
  prev.classList.toggle('hidden', !hasPrev);
  next.classList.toggle('hidden', !hasNext);
}

// ═══════════════════════════════════════════════════════════════
// METADATA PANEL
// ═══════════════════════════════════════════════════════════════
async function showMetaPanel(item) {
  const panel = document.getElementById('meta-panel');
  const body  = document.getElementById('meta-panel-body');
  const title = document.getElementById('meta-panel-title');

  title.textContent = item.name;
  body.innerHTML = '<div class="state-box"><div class="spinner"></div></div>';
  panel.classList.add('open');
  state.metaPanelOpen = true;

  try {
    const data = await loadMeta(item.path);
    const m    = data.meta;
    let html   = '';

    if (item.is_image) {
      const imgIdx = state.filtered.indexOf(item);
      const clickHandler = imgIdx >= 0 ? `openPreview(${imgIdx})` : '';
      html += `<div class="meta-section"><img class="meta-img-thumb" src="${buildFileUrl(item.path)}" alt="${esc(item.name)}"${clickHandler ? ` onclick="${clickHandler}"` : ''}></div>`;
    }

    html += `<div class="meta-section"><h4>General</h4>
      ${metaRow('Name', m.name)}
      ${metaRow('Size', formatSize(m.size))}
      ${metaRow('Modified', formatDateLong(m.modified))}
      ${metaRow('Type', m.type)}
      ${metaRow('MIME', m.mime_type)}
      ${m.extension ? metaRow('Extension', '.' + m.extension) : ''}
      <div class="meta-row" style="margin-top:8px">
        <a class="btn-primary" href="${buildFileUrl(item.path, true)}" download="${esc(item.name)}" style="font-size:.78rem;padding:5px 12px">⬇ Download</a>
        <button class="btn-primary" onclick="copyLink('${buildFileUrl(item.path)}')" style="font-size:.78rem;padding:5px 12px;background:var(--c-surface-2);color:var(--c-text)">🔗 Copy link</button>
      </div>
    </div>`;

    if (m.image) {
      html += `<div class="meta-section"><h4>Image</h4>
        ${metaRow('Dimensions', `${m.image.width} × ${m.image.height} px`)}
        ${m.image.bits ? metaRow('Bit depth', m.image.bits) : ''}
        ${m.image.channels ? metaRow('Channels', m.image.channels) : ''}
      </div>`;
    }

    if (m.exif) {
      if (m.exif.camera && Object.keys(m.exif.camera).length) {
        html += `<div class="meta-section"><h4>Camera</h4>
          ${m.exif.camera.make  ? metaRow('Make',  m.exif.camera.make) : ''}
          ${m.exif.camera.model ? metaRow('Model', m.exif.camera.model) : ''}
          ${m.exif.camera.software  ? metaRow('Software', m.exif.camera.software) : ''}
          ${m.exif.camera.date_time ? metaRow('Date', m.exif.camera.date_time) : ''}
        </div>`;
      }
      if (m.exif.settings && Object.keys(m.exif.settings).length) {
        const s = m.exif.settings;
        html += `<div class="meta-section"><h4>Exposure</h4>
          ${s.date_original   ? metaRow('Taken',    s.date_original) : ''}
          ${s.exposure_time   ? metaRow('Exposure', s.exposure_time) : ''}
          ${s.f_number        ? metaRow('Aperture', s.f_number) : ''}
          ${s.iso             ? metaRow('ISO', s.iso) : ''}
          ${s.focal_length    ? metaRow('Focal length', s.focal_length) : ''}
          ${s.width && s.height ? metaRow('Dimensions', `${s.width} × ${s.height}`) : ''}
        </div>`;
      }
      if (m.exif.gps) {
        const g = m.exif.gps;
        html += `<div class="meta-section"><h4>GPS Location</h4>
          ${metaRow('Latitude',  g.latitude)}
          ${metaRow('Longitude', g.longitude)}
          ${g.altitude_m ? metaRow('Altitude', g.altitude_m + ' m') : ''}
          <div class="meta-row" style="margin-top:6px">
            <a href="${g.map_url}" target="_blank" rel="noopener" class="btn-primary" style="font-size:.78rem;padding:5px 12px">🗺 Open in map</a>
          </div>
        </div>`;
      }
    }

    if (m.csv_preview) {
      const cv = m.csv_preview;
      html += `<div class="meta-section"><h4>CSV Preview (${cv.total_rows} rows × ${cv.headers.length} cols)</h4>
        <div style="overflow-x:auto;font-size:.75rem">
          <table style="border-collapse:collapse;width:100%">
            <thead><tr>${cv.headers.map(h => `<th style="background:var(--c-primary);color:#fff;padding:4px 8px;text-align:left">${esc(h)}</th>`).join('')}</tr></thead>
            <tbody>${cv.sample.map(r => `<tr>${r.map(c => `<td style="padding:3px 8px;border-bottom:1px solid var(--c-border)">${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody>
          </table>
        </div>
      </div>`;
    }

    body.innerHTML = html;
  } catch (e) {
    body.innerHTML = `<div class="meta-section"><p style="color:var(--c-danger)">${esc(e.message)}</p></div>`;
  }
}

// ═══════════════════════════════════════════════════════════════
// SEARCH
// ═══════════════════════════════════════════════════════════════
let searchTimer = null;

function handleSearch(query) {
  clearTimeout(searchTimer);
  state.searchQuery = query;
  document.getElementById('search-clear').classList.toggle('visible', query.length > 0);

  if (!query) {
    state.isSearching = false;
    applyFiltersAndSort();
    return;
  }

  state.isSearching = true;
  searchTimer = setTimeout(async () => {
    showLoading();
    try {
      const data = await doSearch(state.path, query);
      state.filtered = data.items;
      if (data.truncated) showToast(`Showing first 200 results for "${esc(query)}"`);
      renderFiles();
      updateStats();
    } catch (e) {
      showError(e.message);
    }
  }, CONFIG.searchDelay);
}

// ═══════════════════════════════════════════════════════════════
// UTILITIES
// ═══════════════════════════════════════════════════════════════
function esc(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function buildFileUrl(path, download = false) {
  const params = new URLSearchParams({ action: 'file', path });
  if (download) params.set('download', '1');
  return `?${params.toString()}`;
}

function formatSize(bytes) {
  if (bytes === null || bytes === undefined) return '—';
  if (bytes < 1024)       return bytes + ' B';
  if (bytes < 1048576)    return (bytes / 1024).toFixed(1) + ' KB';
  if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
  return (bytes / 1073741824).toFixed(2) + ' GB';
}

function formatDate(ts) {
  return new Date(ts * 1000).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateLong(ts) {
  return new Date(ts * 1000).toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function metaRow(key, val) {
  if (val === null || val === undefined || val === '') return '';
  return `<div class="meta-row"><span class="meta-key">${esc(key)}</span><span class="meta-val">${esc(String(val))}</span></div>`;
}

function errorBox(msg) {
  return `<div class="state-box" style="color:#aaa"><p style="color:#f87171">${esc(msg)}</p></div>`;
}

function showLoading() {
  document.getElementById('file-container').innerHTML = `
    <div class="state-box"><div class="spinner"></div><p>Loading…</p></div>`;
}

function showError(msg) {
  document.getElementById('file-container').innerHTML = `
    <div class="state-box">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#f87171" stroke-width="1.5">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <p style="color:#f87171">${esc(msg)}</p>
    </div>`;
}

function updateStats() {
  const total = state.filtered.length;
  const files = state.filtered.filter(i => !i.is_dir);
  const dirs  = state.filtered.filter(i => i.is_dir);
  const size  = files.reduce((s, i) => s + (i.size || 0), 0);
  document.getElementById('stats').textContent =
    `${total} item${total !== 1 ? 's' : ''} · ${dirs.length} folder${dirs.length !== 1 ? 's' : ''} · ${formatSize(size)}`;
}

function showToast(msg) {
  const div = document.createElement('div');
  div.className = 'toast';
  div.textContent = msg;
  document.getElementById('toast-container').appendChild(div);
  setTimeout(() => div.remove(), 3000);
}

function copyLink(url) {
  const abs = new URL(url, window.location.origin).href;
  navigator.clipboard.writeText(abs).then(() => showToast('Link copied!')).catch(() => showToast('Could not copy link'));
}

function parseCSVText(text, maxRows) {
  const rows = [];
  let inQuote = false, field = '', row = [];
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (c === '"') {
      if (inQuote && text[i+1] === '"') { field += '"'; i++; }
      else inQuote = !inQuote;
    } else if (c === ',' && !inQuote) {
      row.push(field); field = '';
    } else if ((c === '\n' || c === '\r') && !inQuote) {
      if (c === '\r' && text[i+1] === '\n') i++;
      row.push(field); field = '';
      if (row.some(f => f !== '')) rows.push(row);
      row = [];
      if (rows.length >= maxRows) break;
    } else {
      field += c;
    }
  }
  if (field || row.length) { row.push(field); if (row.some(f => f !== '')) rows.push(row); }
  return rows;
}

function renderMarkdownBasic(src) {
  // Very basic markdown → HTML for notebook cells
  return src
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/^### (.+)$/gm, '<h3>$1</h3>')
    .replace(/^## (.+)$/gm, '<h2>$1</h2>')
    .replace(/^# (.+)$/gm, '<h1>$1</h1>')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/`(.+?)`/g, '<code style="background:var(--c-surface-2);padding:1px 4px;border-radius:3px">$1</code>')
    .replace(/\n/g, '<br>');
}

// ═══════════════════════════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ═══════════════════════════════════════════════════════════════
document.addEventListener('keydown', (e) => {
  const overlay = document.getElementById('preview-overlay');
  const isOpen  = overlay.classList.contains('open');

  if (isOpen) {
    if (e.key === 'Escape') { closePreview(); return; }
    if (e.key === 'ArrowLeft')  { navigatePreview(-1); return; }
    if (e.key === 'ArrowRight') { navigatePreview(1); return; }
  }

  if (e.key === '/' && !['INPUT','TEXTAREA'].includes(document.activeElement.tagName)) {
    e.preventDefault();
    document.getElementById('header-search').focus();
  }
  if (e.key === 'Escape' && state.metaPanelOpen) {
    document.getElementById('meta-panel').classList.remove('open');
    state.metaPanelOpen = false;
  }
});

// ═══════════════════════════════════════════════════════════════
// DARK MODE
// ═══════════════════════════════════════════════════════════════
function initDarkMode() {
  const stored = localStorage.getItem('fb-theme');
  const dark   = stored ? stored === 'dark' : true;
  setDark(dark);
}

function setDark(on) {
  state.darkMode = on;
  document.documentElement.dataset.theme = on ? 'dark' : 'light';
  localStorage.setItem('fb-theme', on ? 'dark' : 'light');
}

// ═══════════════════════════════════════════════════════════════
// EVENT WIRING
// ═══════════════════════════════════════════════════════════════
document.getElementById('btn-dark').addEventListener('click', () => setDark(!state.darkMode));
document.getElementById('preview-close').addEventListener('click', closePreview);
document.getElementById('preview-overlay').addEventListener('click', (e) => {
  if (e.target === document.getElementById('preview-overlay') ||
      e.target === document.getElementById('preview-body')) closePreview();
});
document.getElementById('nav-prev').addEventListener('click', () => navigatePreview(-1));
document.getElementById('nav-next').addEventListener('click', () => navigatePreview(1));

document.getElementById('btn-grid').addEventListener('click', () => {
  state.view = 'grid';
  document.getElementById('btn-grid').classList.add('active');
  document.getElementById('btn-list').classList.remove('active');
  renderFiles();
});
document.getElementById('btn-list').addEventListener('click', () => {
  state.view = 'list';
  document.getElementById('btn-list').classList.add('active');
  document.getElementById('btn-grid').classList.remove('active');
  renderFiles();
});

document.getElementById('sort-select').addEventListener('change', (e) => {
  state.sort = e.target.value;
  applyFiltersAndSort();
});

document.getElementById('header-search').addEventListener('input', (e) => handleSearch(e.target.value.trim()));
document.getElementById('search-clear').addEventListener('click', () => {
  document.getElementById('header-search').value = '';
  handleSearch('');
});

document.getElementById('meta-panel-close').addEventListener('click', () => {
  document.getElementById('meta-panel').classList.remove('open');
  state.metaPanelOpen = false;
});

document.getElementById('btn-preview-meta').addEventListener('click', () => {
  const item = state.filtered[state.previewIndex];
  if (item) { closePreview(); showMetaPanel(item); }
});

document.getElementById('btn-copy-link').addEventListener('click', () => {
  const item = state.filtered[state.previewIndex];
  if (item) copyLink(buildFileUrl(item.path));
});

document.getElementById('logo-link').addEventListener('click', (e) => {
  e.preventDefault();
  navigateTo('/');
});

window.addEventListener('popstate', (e) => {
  const path = e.state?.path || getCurrentPathFromURL();
  navigateTo(path, false);
});

// expose for inline handlers
window.openPreview   = openPreview;
window.copyLink      = copyLink;

// ═══════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════
initDarkMode();
renderFilterChips();

const initPath = getCurrentPathFromURL();
navigateTo(initPath, false);
</script>
</body>
</html>
