<?php
// CS 2300 Database Project (Phase 3) — Group N715
// Web Accessible Image Database System Backend (PHP + MariaDB)
// Authors: Aiden Buter, Claude Brown, Wesley Oxman
// File: server.php
//
// PURPOSE:
//   Single front-controller API and media server that connects the GUI to MariaDB:
//     1. Role-based authentication & sessions (USER, ADMIN, GENERAL_USER)
//     2. Hierarchy browsing (COLLECTION, CATEGORY, ALBUM, IMAGE, METADATA, TAG)
//     3. Multi-table search, filtering, sorting, and image detail views
//     4. Transactional CRUD, file uploads, EXIF extraction, thumbnails, and cascading deletes
//     5. SQL aggregations (MAX, MIN, AVG, COUNT, SUM) and CSV/JSON import/export
//
// USAGE:
//   php -S localhost:3000 server.php
//   or
//   deploy behind Apache / Nginx + PHP-FPM
//
// DEMO LOGIN CREDENTIALS (via POST /api/login):
//   Admins:        admin_aiden / admin123   |  admin_wesley / admin123
//   General Users: viewer_claude / viewer123 | viewer_guest / viewer123

declare(strict_types=1);

// Paths and config constants
define('UPLOADS_DIR', __DIR__ . '/uploads');
define('THUMBS_DIR',  __DIR__ . '/thumbs');
define('UPLOADS_URL', '/uploads');
define('ALLOW_LOCAL_AUTO_ADMIN', false); // Can set auto-admin for local IPs (DEV Mode)

// Load DB config (getDb()) from config/db.php or db.php
if (file_exists(__DIR__ . '/config/db.php')) {
    require_once __DIR__ . '/config/db.php';
} elseif (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} else {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing config/db.php']);
    exit;
}

// check for upload and thumbnail folders 
if (!is_dir(UPLOADS_DIR)) @mkdir(UPLOADS_DIR, 0755, true);
if (!is_dir(THUMBS_DIR))  @mkdir(THUMBS_DIR, 0755, true);

// Request method and path (query string stripped)
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

// ---------------------------------------------------------------------------
// HELPER FUNCTIONS
// ---------------------------------------------------------------------------

// Send JSON response and stop
function jsonResponse(mixed $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Parse JSON request body into array
function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

// Generate unique ID like "img_<time>_<random>"
function genId(string $prefix): string {
    return $prefix . '_' . time() . '_' . bin2hex(random_bytes(4));
}

// Convert number or date string to UNIX timestamp
function parseTs(mixed $val): ?int {
    if ($val === null || $val === '') return null;
    if (is_numeric($val)) return (int) $val;
    $ts = strtotime((string) $val);
    return $ts !== false ? $ts : null;
}

// Safely resolve a file inside a base dir (blocks path traversal)
function safeFile(string $baseDir, string $name): ?string {
    $base = realpath($baseDir);
    if ($base === false) return null;
    $clean = basename(urldecode($name));
    if ($clean === '' || $clean === '.' || $clean === '..') return null;
    $full = $base . DIRECTORY_SEPARATOR . $clean;
    if (is_file($full) && str_starts_with((string) realpath($full), $base)) return $full;
    return null;
}

// Create a resized JPEG thumbnail with GD
function makeThumb(string $src, string $dest, int $newWidth = 300): bool {
    if (!extension_loaded('gd') || !is_file($src)) return false;
    $info = @getimagesize($src);
    if ($info === false) return false;
    // Load source image by MIME type
    $srcImg = match ($info['mime'] ?? '') {
        'image/jpeg' => @imagecreatefromjpeg($src),
        'image/png'  => @imagecreatefrompng($src),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        default      => false,
    };
    if (!$srcImg) return false;
    $w = imagesx($srcImg);
    $h = imagesy($srcImg);
    if ($w <= 0 || $h <= 0) { imagedestroy($srcImg); return false; }
    // Scale down only, keep aspect ratio
    $tw = min($newWidth, $w);
    $th = max(1, (int) floor($h * ($tw / $w)));
    $dstImg = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $tw, $th, $w, $h);
    $ok = @imagejpeg($dstImg, $dest, 82);
    imagedestroy($srcImg);
    imagedestroy($dstImg);
    return $ok;
}

// Parse numbers or fractions like "1/250" into float
function parseFraction(mixed $v): ?float {
    if (is_numeric($v)) return (float) $v;
    if (is_string($v) && str_contains($v, '/')) {
        [$n, $d] = explode('/', $v, 2);
        if (is_numeric($n) && is_numeric($d) && (float) $d != 0.0) return (float) $n / (float) $d;
    }
    return null;
}

// Read EXIF from file, then apply manual overrides
function extractExif(string $filePath, array $overrides = []): array {
    $meta = [
        'camera' => null, 'lens' => null, 'exposure' => null,
        'aperture' => null, 'focal_length' => null, 'shutter_speed' => null, 'date_taken' => null,
    ];
    if (function_exists('exif_read_data') && is_file($filePath)) {
        $exif = @exif_read_data($filePath, 'EXIF,IFD0', true);
        if (is_array($exif)) {
            $ifd0 = $exif['IFD0'] ?? [];
            $ex   = $exif['EXIF'] ?? [];
            // Camera = make + model
            $make  = trim((string) ($ifd0['Make'] ?? ''));
            $model = trim((string) ($ifd0['Model'] ?? ''));
            if ($model !== '') {
                $meta['camera'] = ($make !== '' && !str_starts_with(strtolower($model), strtolower($make)))
                    ? trim($make . ' ' . $model) : $model;
            }
            // Lens model
            $lens = trim((string) ($ex['UndefinedTag:0xA434'] ?? $ex['LensModel'] ?? ''));
            if ($lens !== '') $meta['lens'] = $lens;
            // Exposure compensation (EV)
            if (isset($ex['ExposureBiasValue'])) {
                $ev = parseFraction($ex['ExposureBiasValue']);
                if ($ev !== null) $meta['exposure'] = ($ev > 0 ? '+' : '') . round($ev, 1) . ' EV';
            }
            // Aperture (f-number)
            if (isset($ex['FNumber'])) {
                $f = parseFraction($ex['FNumber']);
                if ($f !== null && $f > 0) $meta['aperture'] = 'f/' . round($f, 1);
            }
            // Focal length (mm)
            if (isset($ex['FocalLength'])) {
                $fl = parseFraction($ex['FocalLength']);
                if ($fl !== null && $fl > 0) $meta['focal_length'] = (int) round($fl);
            }
            // Shutter speed
            if (!empty($ex['ExposureTime'])) $meta['shutter_speed'] = (string) $ex['ExposureTime'];

            // Date taken -> UNIX timestamp
            $dtRaw = $ex['DateTimeOriginal'] ?? $ifd0['DateTime'] ?? null;
            if (is_string($dtRaw) && $dtRaw !== '') {
                $norm = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $dtRaw);
                $ts = strtotime((string) $norm);
                if ($ts !== false) $meta['date_taken'] = $ts;
            }
        }
    }
    // Manual overrides from upload form
    foreach (['camera', 'lens', 'exposure', 'aperture', 'shutter_speed'] as $k) {
        if (!empty($overrides[$k])) $meta[$k] = trim((string) $overrides[$k]);
    }
    if (!empty($overrides['focal_length'])) $meta['focal_length'] = (int) $overrides['focal_length'];
    if (!empty($overrides['date_taken']))   $meta['date_taken'] = parseTs($overrides['date_taken']);
    return $meta;
}

// Find a tag by name/value, or create it
function getOrCreateTag(PDO $pdo, string $name, string $val): string {
    $name = trim($name);
    $val  = trim($val);
    $st = $pdo->prepare('SELECT tag_id FROM TAG WHERE tag_name = ? AND tag_value = ?');
    $st->execute([$name, $val]);
    $found = $st->fetchColumn();
    if ($found !== false) return (string) $found;
    $id = genId('tag');
    $pdo->prepare('INSERT INTO TAG (tag_id, tag_name, tag_value) VALUES (?, ?, ?)')->execute([$id, $name, $val]);
    return $id;
}

// Add a 'tags' array to each image row (one batched query)
function attachImageTags(PDO $pdo, array $images): array {
    if (!$images) return $images;
    $ids = array_values(array_unique(array_column($images, 'image_id')));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT it.image_id, t.tag_id, t.tag_name, t.tag_value FROM Image_Tag it JOIN TAG t ON it.tag_id = t.tag_id WHERE it.image_id IN ($ph)");
    $st->execute($ids);
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $map[$r['image_id']][] = ['tag_id' => $r['tag_id'], 'tag_name' => $r['tag_name'], 'tag_value' => $r['tag_value']];
    }
    foreach ($images as &$img) $img['tags'] = $map[$img['image_id']] ?? [];
    return $images;
}

// Add a 'tags' array to each album row (one batched query)
function attachAlbumTags(PDO $pdo, array $albums): array {
    if (!$albums) return $albums;
    $ids = array_values(array_unique(array_column($albums, 'album_id')));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT atg.album_id, t.tag_id, t.tag_name, t.tag_value FROM Album_Tag atg JOIN TAG t ON atg.tag_id = t.tag_id WHERE atg.album_id IN ($ph)");
    $st->execute($ids);
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $map[$r['album_id']][] = ['tag_id' => $r['tag_id'], 'tag_name' => $r['tag_name'], 'tag_value' => $r['tag_value']];
    }
    foreach ($albums as &$alb) $alb['tags'] = $map[$alb['album_id']] ?? [];
    return $albums;
}

// ---------------------------------------------------------------------------
// STATIC MEDIA ROUTES
// ---------------------------------------------------------------------------

// Serve original uploaded images
if (str_starts_with($uri, '/uploads/')) {
    $f = safeFile(UPLOADS_DIR, substr($uri, 9));
    if ($f) { header('Content-Type: ' . (mime_content_type($f) ?: 'application/octet-stream')); readfile($f); exit; }
    http_response_code(404); exit('Not found');
}
// Serve thumbnails (generate on demand if missing)
if (str_starts_with($uri, '/thumbs/')) {
    $rel = substr($uri, 8);
    $f = safeFile(THUMBS_DIR, $rel);
    if ($f) { header('Content-Type: image/jpeg'); readfile($f); exit; }
    // No cached thumb: build one from the original
    $orig = safeFile(UPLOADS_DIR, $rel);
    if ($orig) {
        $dst = THUMBS_DIR . DIRECTORY_SEPARATOR . basename($orig);
        if (makeThumb($orig, $dst, 300) && is_file($dst)) { header('Content-Type: image/jpeg'); readfile($dst); exit; }
        // Fall back to the original if thumbnailing fails
        header('Content-Type: ' . (mime_content_type($orig) ?: 'application/octet-stream')); readfile($orig); exit;
    }
    http_response_code(404); exit('Not found');
}
// Non-API paths fall through to the PHP built-in server (static files)
if (!str_starts_with($uri, '/api/')) return false;

// ---------------------------------------------------------------------------
// CORS & SESSION SETUP
// ---------------------------------------------------------------------------

// Allow cross-origin requests with cookies
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
// Answer CORS preflight requests
if ($method === 'OPTIONS') { http_response_code(204); exit; }
// Start session with safe cookie settings
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}

// ---------------------------------------------------------------------------
// AUTH HELPERS
// ---------------------------------------------------------------------------

// Get logged-in user from session (or local auto-admin if enabled)
function currentUser(): ?array {
    if (!empty($_SESSION['user_id'])) {
        return [
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'is_admin' => !empty($_SESSION['is_admin']),
            'role' => !empty($_SESSION['is_admin']) ? 'ADMIN' : 'GENERAL_USER',
        ];
    }
    // DEV shortcut for local/private IPs
    if (ALLOW_LOCAL_AUTO_ADMIN) {
        $ip = str_replace('::ffff:', '', $_SERVER['REMOTE_ADDR'] ?? '');
        if (in_array($ip, ['::1', '127.0.0.1'], true) || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return ['user_id' => 'usr_admin_1', 'username' => 'local_admin', 'is_admin' => true, 'role' => 'ADMIN'];
        }
    }
    return null;
}
// Require any logged-in user (401 otherwise)
function requireLogin(): array {
    $u = currentUser();
    if (!$u) jsonResponse(['error' => 'Login required'], 401);
    return $u;
}
// Require an admin (403 otherwise)
function requireAdmin(): array {
    $u = requireLogin();
    if (!$u['is_admin']) jsonResponse(['error' => 'Admin required'], 403);
    return $u;
}
// Check password; upgrades legacy SHA-256 hashes to bcrypt on success
function verifyPw(PDO $pdo, string $uid, string $plain, string $stored): bool {
    if (str_starts_with($stored, 'sha256$')) {
        [$algo, $salt, $hex] = explode('$', $stored, 3);
        if (hash_equals($hex, hash('sha256', $salt . $plain))) {
            // Re-hash with PHP's password_hash and save
            $pdo->prepare('UPDATE `USER` SET password = ? WHERE user_id = ?')->execute([password_hash($plain, PASSWORD_DEFAULT), $uid]);
            return true;
        }
        return false;
    }
    return password_verify($plain, $stored);
}

// ---------------------------------------------------------------------------
// API ROUTER
// ---------------------------------------------------------------------------

try {
    $pdo = getDb();

    // POST /api/login — authenticate and start session
    if ($uri === '/api/login' && $method === 'POST') {
        $b = getJsonBody();
        // Look up user and which role table they belong to
        $st = $pdo->prepare('SELECT u.user_id, u.username, u.password, a.user_id AS admin_id, g.user_id AS gen_id FROM `USER` u LEFT JOIN ADMIN a ON u.user_id = a.user_id LEFT JOIN GENERAL_USER g ON u.user_id = g.user_id WHERE u.username = ?');
        $st->execute([trim((string) ($b['username'] ?? ''))]);
        $r = $st->fetch();
        if (!$r || !verifyPw($pdo, $r['user_id'], (string) ($b['password'] ?? ''), $r['password'])) {
            jsonResponse(['error' => 'Invalid credentials'], 401);
        }
        $isAdmin = $r['admin_id'] !== null;
        session_regenerate_id(true); // prevent session hangs
        $_SESSION['user_id'] = $r['user_id'];
        $_SESSION['username'] = $r['username'];
        $_SESSION['is_admin'] = $isAdmin;
        jsonResponse(['ok' => true, 'logged_in' => true, 'is_admin' => $isAdmin, 'admin' => $isAdmin, 'role' => $isAdmin ? 'ADMIN' : 'GENERAL_USER', 'user' => ['user_id' => $r['user_id'], 'username' => $r['username'], 'is_admin' => $isAdmin]]);
    }

    // POST /api/logout — end session
    if ($uri === '/api/logout' && $method === 'POST') {
        $_SESSION = [];
        session_destroy();
        jsonResponse(['ok' => true, 'logged_in' => false, 'is_admin' => false]);
    }

    // POST /api/register — create a new GENERAL_USER
    if ($uri === '/api/register' && $method === 'POST') {
        $b = getJsonBody();
        $u = trim((string) ($b['username'] ?? ''));
        $p = (string) ($b['password'] ?? '');
        if (strlen($u) < 3 || strlen($p) < 6) jsonResponse(['error' => 'Username >= 3 chars and password >= 6 chars'], 400);
        $uid = genId('usr');
        // Insert into USER + GENERAL_USER atomically!!!
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO `USER` (user_id, username, password) VALUES (?, ?, ?)')->execute([$uid, $u, password_hash($p, PASSWORD_DEFAULT)]);
            $pdo->prepare('INSERT INTO GENERAL_USER (user_id) VALUES (?)')->execute([$uid]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonResponse(['ok' => true, 'user_id' => $uid, 'username' => $u, 'role' => 'GENERAL_USER'], 201);
    }

    // GET /api/me (or /api/is-admin) — current session info
    if (($uri === '/api/me' || $uri === '/api/is-admin') && $method === 'GET') {
        $u = currentUser();
        jsonResponse(['logged_in' => $u !== null, 'admin' => (bool) ($u['is_admin'] ?? false), 'is_admin' => (bool) ($u['is_admin'] ?? false), 'user' => $u]);
    }

    // GET /api/collections — collections with category/album/image counts
    if ($uri === '/api/collections' && $method === 'GET') {
        $rows = $pdo->query('SELECT col.*, COUNT(DISTINCT cat.category_id) AS category_count, COUNT(DISTINCT alb.album_id) AS album_count, COUNT(DISTINCT img.image_id) AS image_count FROM COLLECTION col LEFT JOIN CATEGORY cat ON cat.collection_id = col.collection_id LEFT JOIN ALBUM alb ON alb.category_id = cat.category_id LEFT JOIN IMAGE img ON img.album_id = alb.album_id GROUP BY col.collection_id')->fetchAll();
        jsonResponse(['collections' => $rows]);
    }

    // GET /api/categories — categories with counts 
    if ($uri === '/api/categories' && $method === 'GET') {
        $sql = 'SELECT cat.*, col.name AS collection_name, COUNT(DISTINCT alb.album_id) AS album_count, COUNT(DISTINCT img.image_id) AS image_count FROM CATEGORY cat JOIN COLLECTION col ON col.collection_id = cat.collection_id LEFT JOIN ALBUM alb ON alb.category_id = cat.category_id LEFT JOIN IMAGE img ON img.album_id = alb.album_id';
        $params = [];
        if (!empty($_GET['collection_id'])) { $sql .= ' WHERE cat.collection_id = ?'; $params[] = $_GET['collection_id']; }
        $sql .= ' GROUP BY cat.category_id';
        $st = $pdo->prepare($sql); $st->execute($params);
        jsonResponse(['categories' => $st->fetchAll()]);
    }

    // GET /api/albums — albums with tags (filter by category/location)
    if ($uri === '/api/albums' && $method === 'GET') {
        $sql = 'SELECT alb.*, cat.name AS category_name, col.collection_id, col.name AS collection_name, COUNT(DISTINCT img.image_id) AS image_count FROM ALBUM alb JOIN CATEGORY cat ON cat.category_id = alb.category_id JOIN COLLECTION col ON col.collection_id = cat.collection_id LEFT JOIN IMAGE img ON img.album_id = alb.album_id';
        $w = []; $params = [];
        if (!empty($_GET['category_id'])) { $w[] = 'alb.category_id = ?'; $params[] = $_GET['category_id']; }
        if (!empty($_GET['location']))    { $w[] = 'alb.location LIKE ?'; $params[] = '%' . $_GET['location'] . '%'; }
        if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
        $sql .= ' GROUP BY alb.album_id ORDER BY alb.event_date DESC';
        $st = $pdo->prepare($sql); $st->execute($params);
        jsonResponse(['albums' => attachAlbumTags($pdo, $st->fetchAll())]);
    }

    // GET /api/db — full nested dump for the GUI (albums > photos > metadata/tags) 
    if ($uri === '/api/db' && $method === 'GET') {
        $collections = $pdo->query('SELECT * FROM COLLECTION')->fetchAll();
        $categories  = $pdo->query('SELECT * FROM CATEGORY')->fetchAll();
        $albumsRaw   = attachAlbumTags($pdo, $pdo->query('SELECT * FROM ALBUM ORDER BY event_date DESC')->fetchAll());
        $imagesRaw   = attachImageTags($pdo, $pdo->query('SELECT i.*, m.meta_id, m.camera, m.lens, m.exposure, m.aperture, m.focal_length, m.shutter_speed, m.date_taken FROM IMAGE i LEFT JOIN METADATA m ON m.image_id = i.image_id ORDER BY i.upload_date ASC')->fetchAll());
        // Group images by album for the front end
        $photosByAlb = [];
        foreach ($imagesRaw as $img) {
            $photosByAlb[$img['album_id']][] = [
                'id' => $img['image_id'], 'image_id' => $img['image_id'], 'url' => $img['file_path'],
                'file_name' => $img['file_name'], 'file_path' => $img['file_path'], 'file_type' => $img['file_type'],
                'file_size' => (int) $img['file_size'], 'upload_date' => (int) $img['upload_date'],
                'like_count' => (int) $img['like_count'], 'dl_count' => (int) $img['dl_count'],
                'tags' => $img['tags'],
                'metadata' => ['meta_id' => $img['meta_id'], 'camera' => $img['camera'], 'lens' => $img['lens'], 'exposure' => $img['exposure'], 'aperture' => $img['aperture'], 'focal_length' => $img['focal_length'] !== null ? (int) $img['focal_length'] : null, 'shutter_speed' => $img['shutter_speed'], 'date_taken' => $img['date_taken'] !== null ? (int) $img['date_taken'] : null],
            ];
        }
        // Build album objects with sport, date, cover, and photos
        $albums = [];
        foreach ($albumsRaw as $alb) {
            $aid = $alb['album_id'];
            $ps  = $photosByAlb[$aid] ?? [];
            $sport = null; $tagNames = [];
            foreach ($alb['tags'] as $t) { $tagNames[] = $t['tag_value']; if (strtolower($t['tag_name']) === 'sport') $sport = $t['tag_value']; }
            $albums[] = ['id' => $aid, 'album_id' => $aid, 'name' => $alb['title'], 'title' => $alb['title'], 'category' => $alb['category_id'], 'category_id' => $alb['category_id'], 'location' => $alb['location'], 'sport' => $sport, 'date' => $alb['event_date'] ? gmdate('Y-m-d', (int) $alb['event_date']) : null, 'event_date' => $alb['event_date'] !== null ? (int) $alb['event_date'] : null, 'tags' => $tagNames, 'cover' => $ps[0]['url'] ?? null, 'photos' => $ps];
        }
        jsonResponse(['collections' => $collections, 'categories' => $categories, 'albums' => $albums]);
    }

    // GET /api/search — multi-table image search with filters and sorting
    if ($uri === '/api/search' && $method === 'GET') {
        $w = []; $params = [];
        // Keyword search across file, album, camera, lens, and tag text
        if (!empty($_GET['q'])) { $kw = '%' . trim((string) $_GET['q']) . '%'; $w[] = '(i.file_name LIKE ? OR alb.title LIKE ? OR alb.location LIKE ? OR m.camera LIKE ? OR m.lens LIKE ? OR t.tag_value LIKE ?)'; array_push($params, $kw, $kw, $kw, $kw, $kw, $kw); }
        // Individual filters
        if (!empty($_GET['category_id'])) { $w[] = 'cat.category_id = ?'; $params[] = $_GET['category_id']; }
        if (!empty($_GET['album_id']))    { $w[] = 'alb.album_id = ?';    $params[] = $_GET['album_id']; }
        if (!empty($_GET['location']))    { $w[] = 'alb.location LIKE ?'; $params[] = '%' . $_GET['location'] . '%'; }
        if (!empty($_GET['camera']))      { $w[] = 'm.camera LIKE ?';     $params[] = '%' . $_GET['camera'] . '%'; }
        if (!empty($_GET['lens']))        { $w[] = 'm.lens LIKE ?';       $params[] = '%' . $_GET['lens'] . '%'; }
        if (!empty($_GET['tag_value']))   { $w[] = '(t.tag_value LIKE ? OR at_t.tag_value LIKE ?)'; $params[] = '%' . $_GET['tag_value'] . '%'; $params[] = '%' . $_GET['tag_value'] . '%'; }
        // Focal length range
        if (isset($_GET['focal_length_min']) && $_GET['focal_length_min'] !== '') { $w[] = 'm.focal_length >= ?'; $params[] = (int) $_GET['focal_length_min']; }
        if (isset($_GET['focal_length_max']) && $_GET['focal_length_max'] !== '') { $w[] = 'm.focal_length <= ?'; $params[] = (int) $_GET['focal_length_max']; }
        // Date-taken range
        if (!empty($_GET['date_taken_from'])) { $ts = parseTs($_GET['date_taken_from']); if ($ts !== null) { $w[] = 'm.date_taken >= ?'; $params[] = $ts; } }
        if (!empty($_GET['date_taken_to']))   { $ts = parseTs($_GET['date_taken_to']);   if ($ts !== null) { $w[] = 'm.date_taken <= ?'; $params[] = $ts; } }
        // Whitelisted sort column + direction
        // Fixes SQL injection!
        $dir = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $orderExpr = match ($_GET['sort'] ?? 'date_taken') {
            'dl_count' => 'i.dl_count', 'like_count' => 'i.like_count', 'upload_date' => 'i.upload_date',
            'file_size' => 'i.file_size', 'event_date' => 'alb.event_date',
            'tag_value' => ($dir === 'ASC' ? 'MIN(t.tag_value)' : 'MAX(t.tag_value)'),
            default => 'm.date_taken',
        };
        $sql = 'SELECT i.*, alb.title AS album_title, alb.location, alb.event_date, cat.category_id, cat.name AS category_name, col.collection_id, col.name AS collection_name, m.meta_id, m.camera, m.lens, m.exposure, m.aperture, m.focal_length, m.shutter_speed, m.date_taken FROM IMAGE i JOIN ALBUM alb ON alb.album_id = i.album_id JOIN CATEGORY cat ON cat.category_id = alb.category_id JOIN COLLECTION col ON col.collection_id = cat.collection_id LEFT JOIN METADATA m ON m.image_id = i.image_id LEFT JOIN Image_Tag it ON it.image_id = i.image_id LEFT JOIN TAG t ON t.tag_id = it.tag_id LEFT JOIN Album_Tag atg ON atg.album_id = alb.album_id LEFT JOIN TAG at_t ON at_t.tag_id = atg.tag_id';
        if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
        $sql .= " GROUP BY i.image_id ORDER BY $orderExpr $dir";
        $st = $pdo->prepare($sql); $st->execute($params);
        $res = attachImageTags($pdo, $st->fetchAll());
        jsonResponse(['count' => count($res), 'results' => $res]);
    }

    // GET /api/image?id= — single image detail view
    if ($uri === '/api/image' && $method === 'GET') {
        $id = trim((string) ($_GET['id'] ?? ''));
        if ($id === '') jsonResponse(['error' => 'id required'], 400);
        $st = $pdo->prepare('SELECT i.*, u.username AS uploaded_by, alb.title AS album_title, alb.location, alb.event_date, cat.category_id, cat.name AS category_name, col.collection_id, col.name AS collection_name, m.meta_id, m.camera, m.lens, m.exposure, m.aperture, m.focal_length, m.shutter_speed, m.date_taken FROM IMAGE i JOIN ALBUM alb ON alb.album_id = i.album_id JOIN CATEGORY cat ON cat.category_id = alb.category_id JOIN COLLECTION col ON col.collection_id = cat.collection_id LEFT JOIN METADATA m ON m.image_id = i.image_id LEFT JOIN `USER` u ON u.user_id = i.user_id WHERE i.image_id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) jsonResponse(['error' => 'Image not found'], 404);
        jsonResponse(['image' => attachImageTags($pdo, [$r])[0]]);
    }

    // POST /api/upload — admin uploads photos (saves files, thumbs, IMAGE + METADATA rows)
    if ($uri === '/api/upload' && $method === 'POST') {
        $admin = requireAdmin();
        $field = $_FILES['photos'] ?? $_FILES['file'] ?? null;
        if (empty($field)) jsonResponse(['error' => 'No files received'], 400);
        // Normalize single/multiple uploads into one list
        $files = [];
        if (is_array($field['name'])) {
            for ($i = 0; $i < count($field['name']); $i++) {
                $files[] = ['name' => $field['name'][$i], 'tmp_name' => $field['tmp_name'][$i], 'error' => $field['error'][$i], 'size' => $field['size'][$i]];
            }
        } else { $files[] = $field; }
        $albumId = trim((string) ($_POST['album_id'] ?? ''));
        $urls = []; $created = []; $saved = []; // $saved tracks files for cleanup
        $pdo->beginTransaction();
        try {
            $insImg  = $pdo->prepare('INSERT INTO IMAGE (image_id, file_name, file_path, file_type, file_size, upload_date, like_count, dl_count, album_id, user_id) VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?)');
            $insMeta = $pdo->prepare('INSERT INTO METADATA (image_id, meta_id, camera, lens, exposure, aperture, focal_length, shutter_speed, date_taken) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($files as $f) {
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload error');
                // Clean up filename and prefix with timestamp to avoid it being ugly
                $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string) $f['name']));
                $fn   = round(microtime(true) * 1000) . '-' . $safe;
                $dest = UPLOADS_DIR . DIRECTORY_SEPARATOR . $fn;
                $thmb = THUMBS_DIR  . DIRECTORY_SEPARATOR . $fn;
                if (!move_uploaded_file($f['tmp_name'], $dest)) throw new RuntimeException('Failed to move file');
                $saved[] = $dest;
                makeThumb($dest, $thmb, 300);
                if (is_file($thmb)) $saved[] = $thmb;
                $url = UPLOADS_URL . '/' . $fn;
                $urls[] = $url;
                // Only create DB rows if an album was given
                if ($albumId !== '') {
                    $imgId = genId('img'); $metaId = genId('meta'); $now = time();
                    $sz = max(1, (int) filesize($dest));
                    $mime = mime_content_type($dest) ?: 'image/jpeg';
                    $meta = extractExif($dest, $_POST);
                    $insImg->execute([$imgId, $safe, $url, $mime, $sz, $now, $albumId, $admin['user_id']]);
                    $insMeta->execute([$imgId, $metaId, $meta['camera'], $meta['lens'], $meta['exposure'], $meta['aperture'], $meta['focal_length'], $meta['shutter_speed'], $meta['date_taken'] ?? $now]);
                    $created[] = ['image_id' => $imgId, 'meta_id' => $metaId, 'file_path' => $url, 'metadata' => $meta];
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            // Roll back DB and delete any files already written
            $pdo->rollBack();
            foreach ($saved as $p) @unlink($p);
            jsonResponse(['error' => $e->getMessage()], 400);
        }
        jsonResponse(['ok' => true, 'urls' => $urls, 'images' => $created], 201);
    }

    // POST /api/albums — admin creates an album (including tags if wanted)
    if ($uri === '/api/albums' && $method === 'POST') {
        requireAdmin();
        $b = getJsonBody();
        $title = trim((string) ($b['title'] ?? $b['name'] ?? ''));
        $catId = trim((string) ($b['category_id'] ?? $b['category'] ?? ''));
        if ($title === '' || $catId === '') jsonResponse(['error' => 'title and category_id required'], 400);
        $aid = trim((string) ($b['album_id'] ?? '')) ?: genId('album');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO ALBUM (album_id, title, location, event_date, category_id) VALUES (?, ?, ?, ?, ?)')->execute([$aid, $title, $b['location'] ?? null, parseTs($b['event_date'] ?? $b['date'] ?? null), $catId]);
            $link = $pdo->prepare('INSERT IGNORE INTO Album_Tag (album_id, tag_id) VALUES (?, ?)');
            // Shortcut: sport -> "sport" tag
            if (!empty($b['sport'])) $link->execute([$aid, getOrCreateTag($pdo, 'sport', (string) $b['sport'])]);
            // Tags as "name:value" strings (or plain label)
            if (!empty($b['tags']) && is_array($b['tags'])) {
                foreach ($b['tags'] as $t) {
                    if (is_string($t) && trim($t) !== '') {
                        [$tn, $tv] = str_contains($t, ':') ? array_map('trim', explode(':', $t, 2)) : ['label', trim($t)];
                        $link->execute([$aid, getOrCreateTag($pdo, $tn, $tv)]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonResponse(['ok' => true, 'album_id' => $aid], 201);
    }

    // PUT/POST /api/edit — admin edits whitelisted fields on album/image/metadata/tag
    if ($uri === '/api/edit' && ($method === 'PUT' || $method === 'POST')) {
        requireAdmin();
        $b = getJsonBody();
        $ent = strtolower(trim((string) ($b['entity'] ?? '')));
        $id  = trim((string) ($b['id'] ?? ''));
        $flds = is_array($b['fields'] ?? null) ? $b['fields'] : [];
        // entity => [table, primary key, editable columns]
        $map = [
            'album'    => ['ALBUM', 'album_id', ['title', 'location', 'event_date', 'category_id']],
            'image'    => ['IMAGE', 'image_id', ['file_name', 'file_path', 'file_type', 'file_size', 'album_id']],
            'metadata' => ['METADATA', 'image_id', ['camera', 'lens', 'exposure', 'aperture', 'focal_length', 'shutter_speed', 'date_taken']],
            'tag'      => ['TAG', 'tag_id', ['tag_name', 'tag_value']],
        ];
        if (!isset($map[$ent]) || $id === '' || !$flds) jsonResponse(['error' => 'Invalid edit payload'], 400);
        [$tbl, $pk, $allowed] = $map[$ent];
        // Build SET clause from allowed fields only
        $sets = []; $params = [];
        foreach ($flds as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            if (in_array($k, ['event_date', 'date_taken'], true)) $v = parseTs($v);
            elseif (in_array($k, ['file_size', 'focal_length'], true) && $v !== null && $v !== '') $v = (int) $v;
            $sets[] = "$k = ?"; $params[] = $v;
        }
        if (!$sets) jsonResponse(['error' => 'No editable fields'], 400);
        $params[] = $id;
        $st = $pdo->prepare("UPDATE $tbl SET " . implode(', ', $sets) . " WHERE $pk = ?");
        $st->execute($params);
        jsonResponse(['ok' => true, 'rows_affected' => $st->rowCount()]);
    }

    // DELETE/POST /api/delete — admin deletes image/album/tag (DB cascades + file cleanup)
    if ($uri === '/api/delete' && ($method === 'DELETE' || $method === 'POST')) {
        requireAdmin();
        $b = getJsonBody();
        $ent = strtolower(trim((string) ($b['entity'] ?? $_GET['entity'] ?? '')));
        $id  = trim((string) ($b['id'] ?? $_GET['id'] ?? ''));
        if ($ent === '' || $id === '') jsonResponse(['error' => 'entity and id required'], 400);
        $files = []; // collect file paths to remove from disk after commit
        $pdo->beginTransaction();
        try {
            if ($ent === 'image') {
                $s = $pdo->prepare('SELECT file_path FROM IMAGE WHERE image_id = ?'); $s->execute([$id]); $files = $s->fetchAll(PDO::FETCH_COLUMN);
                $del = $pdo->prepare('DELETE FROM IMAGE WHERE image_id = ?');
            } elseif ($ent === 'album') {
                $s = $pdo->prepare('SELECT file_path FROM IMAGE WHERE album_id = ?'); $s->execute([$id]); $files = $s->fetchAll(PDO::FETCH_COLUMN);
                $del = $pdo->prepare('DELETE FROM ALBUM WHERE album_id = ?');
            } elseif ($ent === 'tag') {
                $del = $pdo->prepare('DELETE FROM TAG WHERE tag_id = ?');
            } else { $pdo->rollBack(); jsonResponse(['error' => 'Unsupported entity'], 400); }
            $del->execute([$id]);
            $cnt = $del->rowCount();
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        // Remove files and thumbnails from disk
        foreach ($files as $fp) {
            if (str_starts_with((string) $fp, '/uploads/')) {
                $bn = basename(substr((string) $fp, 9));
                @unlink(UPLOADS_DIR . DIRECTORY_SEPARATOR . $bn);
                @unlink(THUMBS_DIR  . DIRECTORY_SEPARATOR . $bn);
            }
        }
        jsonResponse(['ok' => true, 'rows_deleted' => $cnt]);
    }

    // GET /api/tags — list all tags
    if ($uri === '/api/tags' && $method === 'GET') {
        jsonResponse(['tags' => $pdo->query('SELECT * FROM TAG ORDER BY tag_name, tag_value')->fetchAll()]);
    }

    // POST /api/tags — logged-in user tags an image and/or album
    if ($uri === '/api/tags' && $method === 'POST') {
        requireLogin();
        $b = getJsonBody();
        $tn = trim((string) ($b['tag_name'] ?? ''));
        $tv = trim((string) ($b['tag_value'] ?? ''));
        $iid = trim((string) ($b['image_id'] ?? ''));
        $aid = trim((string) ($b['album_id'] ?? ''));
        if ($tn === '' || $tv === '' || ($iid === '' && $aid === '')) jsonResponse(['error' => 'Missing tag fields'], 400);
        $pdo->beginTransaction();
        try {
            $tid = getOrCreateTag($pdo, $tn, $tv);
            if ($iid !== '') $pdo->prepare('INSERT IGNORE INTO Image_Tag (image_id, tag_id) VALUES (?, ?)')->execute([$iid, $tid]);
            if ($aid !== '') $pdo->prepare('INSERT IGNORE INTO Album_Tag (album_id, tag_id) VALUES (?, ?)')->execute([$aid, $tid]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonResponse(['ok' => true, 'tag_id' => $tid], 201);
    }

    // POST /api/interact — increment an image's like or download counter
    if ($uri === '/api/interact' && $method === 'POST') {
        $b = getJsonBody();
        $id = trim((string) ($b['image_id'] ?? ''));
        $act = strtolower(trim((string) ($b['action'] ?? '')));
        if ($id === '' || !in_array($act, ['like', 'download'], true)) jsonResponse(['error' => 'Invalid action'], 400);
        $col = $act === 'like' ? 'like_count' : 'dl_count';
        $st = $pdo->prepare("UPDATE IMAGE SET $col = $col + 1 WHERE image_id = ?");
        $st->execute([$id]);
        if ($st->rowCount() === 0) jsonResponse(['error' => 'Image not found'], 404);
        $r = $pdo->prepare('SELECT image_id, like_count, dl_count FROM IMAGE WHERE image_id = ?');
        $r->execute([$id]);
        jsonResponse(['ok' => true] + $r->fetch());
    }

    // GET /api/download-album?id= — zip an album's images and add to download counts
    if ($uri === '/api/download-album' && $method === 'GET') {
        $aid = trim((string) ($_GET['id'] ?? ''));
        if ($aid === '') jsonResponse(['error' => 'id required'], 400);
        $alb = $pdo->prepare('SELECT album_id, title FROM ALBUM WHERE album_id = ?'); $alb->execute([$aid]); $a = $alb->fetch();
        if (!$a) jsonResponse(['error' => 'Album not found'], 404);
        $imgs = $pdo->prepare('SELECT file_name, file_path FROM IMAGE WHERE album_id = ?'); $imgs->execute([$aid]); $photos = $imgs->fetchAll();
        if (!$photos) jsonResponse(['error' => 'Empty album'], 404);
        $pdo->prepare('UPDATE IMAGE SET dl_count = dl_count + 1 WHERE album_id = ?')->execute([$aid]);
        // Build temp zip of the album's local files
        $zipPath = tempnam(sys_get_temp_dir(), 'alb_');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $added = 0;
        foreach ($photos as $p) {
            if (str_starts_with($p['file_path'], '/uploads/')) {
                $f = safeFile(UPLOADS_DIR, substr($p['file_path'], 9));
                if ($f) { $zip->addFile($f, $p['file_name']); $added++; }
            }
        }
        $zip->close();
        // No real files on disk, just report the counter bump
        if ($added === 0) { @unlink($zipPath); jsonResponse(['ok' => true, 'dl_incremented' => true, 'files_in_zip' => 0]); }
        // Stream zip to client, then clean up
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $a['title']) . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    // GET /api/stats — aggregate stats (overall, per album, per category)
    if ($uri === '/api/stats' && $method === 'GET') {
        // Overall; COUNT, SUM, AVG, MAX, MIN across all images
        $overall = $pdo->query('SELECT COUNT(i.image_id) AS total_images, COALESCE(SUM(i.file_size),0) AS total_file_size, ROUND(AVG(i.file_size),2) AS avg_file_size, COALESCE(SUM(i.dl_count),0) AS total_downloads, ROUND(AVG(i.like_count),2) AS avg_likes, MAX(i.like_count) AS max_likes, MIN(i.like_count) AS min_likes, MAX(i.dl_count) AS max_downloads, MIN(m.date_taken) AS earliest_date_taken, MAX(m.date_taken) AS latest_date_taken FROM IMAGE i LEFT JOIN METADATA m ON m.image_id = i.image_id')->fetch();
        // Grouped by album
        $byAlbum = $pdo->query('SELECT a.album_id, a.title, COUNT(i.image_id) AS image_count, COALESCE(SUM(i.file_size),0) AS total_file_size, ROUND(AVG(i.file_size),2) AS avg_file_size, COALESCE(SUM(i.dl_count),0) AS total_downloads, ROUND(AVG(i.like_count),2) AS avg_likes, MAX(i.like_count) AS max_likes, MIN(i.like_count) AS min_likes, MIN(m.date_taken) AS earliest_date_taken, MAX(m.date_taken) AS latest_date_taken FROM ALBUM a LEFT JOIN IMAGE i ON i.album_id = a.album_id LEFT JOIN METADATA m ON m.image_id = i.image_id GROUP BY a.album_id')->fetchAll();
        // Grouped by category
        $byCategory = $pdo->query('SELECT c.category_id, c.name AS category_name, COUNT(i.image_id) AS image_count, COALESCE(SUM(i.file_size),0) AS total_file_size, ROUND(AVG(i.file_size),2) AS avg_file_size, COALESCE(SUM(i.dl_count),0) AS total_downloads, ROUND(AVG(i.like_count),2) AS avg_likes, MAX(i.like_count) AS max_likes, MIN(i.like_count) AS min_likes FROM CATEGORY c LEFT JOIN ALBUM a ON a.category_id = c.category_id LEFT JOIN IMAGE i ON i.album_id = a.album_id GROUP BY c.category_id')->fetchAll();
        jsonResponse(['overall' => $overall, 'by_album' => $byAlbum, 'by_category' => $byCategory]);
    }

    // GET /api/export — dump image records as CSV or JSON (optional filter)
    if ($uri === '/api/export' && $method === 'GET') {
        $fmt = strtolower((string) ($_GET['format'] ?? 'json'));
        $sql = 'SELECT col.collection_id, col.name AS collection_name, cat.category_id, cat.name AS category_name, alb.album_id, alb.title AS album_title, alb.location, alb.event_date, i.image_id, i.file_name, i.file_path, i.file_type, i.file_size, i.upload_date, i.like_count, i.dl_count, m.meta_id, m.camera, m.lens, m.exposure, m.aperture, m.focal_length, m.shutter_speed, m.date_taken, GROUP_CONCAT(DISTINCT CONCAT(t.tag_name, ":", t.tag_value) SEPARATOR "; ") AS tags FROM IMAGE i JOIN ALBUM alb ON alb.album_id = i.album_id JOIN CATEGORY cat ON cat.category_id = alb.category_id JOIN COLLECTION col ON col.collection_id = cat.collection_id LEFT JOIN METADATA m ON m.image_id = i.image_id LEFT JOIN Image_Tag it ON it.image_id = i.image_id LEFT JOIN TAG t ON t.tag_id = it.tag_id';
        $params = [];
        if (!empty($_GET['album_id'])) { $sql .= ' WHERE alb.album_id = ?'; $params[] = $_GET['album_id']; }
        $sql .= ' GROUP BY i.image_id';
        $st = $pdo->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll();
        // CSV output
        if ($fmt === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="n715_export.csv"');
            $out = fopen('php://output', 'w');
            if ($rows) { fputcsv($out, array_keys($rows[0])); foreach ($rows as $r) fputcsv($out, $r); }
            fclose($out); exit;
        }
        // JSON output (default)
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="n715_export.json"');
        echo json_encode(['records' => $rows], JSON_PRETTY_PRINT); exit;
    }

    // POST /api/import — admin bulk-imports records from CSV/JSON file or JSON body
    if ($uri === '/api/import' && $method === 'POST') {
        $admin = requireAdmin();
        $records = [];
        // Read records from uploaded file (JSON or CSV)
        if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['file']['tmp_name'];
            $ext = strtolower(pathinfo((string) $_FILES['file']['name'], PATHINFO_EXTENSION));
            if ($ext === 'json') {
                $d = json_decode((string) file_get_contents($tmp), true);
                $records = is_array($d['records'] ?? null) ? $d['records'] : (is_array($d) ? $d : []);
            } elseif ($ext === 'csv' && ($fp = fopen($tmp, 'r')) !== false) {
                $hdr = fgetcsv($fp);
                if (is_array($hdr)) {
                    while (($row = fgetcsv($fp)) !== false) {
                        if (count($row) === count($hdr)) $records[] = array_combine($hdr, $row);
                    }
                }
                fclose($fp);
            }
        } else {
            // or read records from a raw JSON body
            $b = getJsonBody();
            $records = is_array($b['records'] ?? null) ? $b['records'] : $b;
        }
        if (!$records || !is_array($records)) jsonResponse(['error' => 'No import records'], 400);
        $cnt = 0; $now = time();
        // import
        $pdo->beginTransaction();
        try {
            // Upserts so re-importing the same IDs updates instead of dying
            $upAlb  = $pdo->prepare('INSERT INTO ALBUM (album_id, title, location, event_date, category_id) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), location = VALUES(location)');
            $upImg  = $pdo->prepare('INSERT INTO IMAGE (image_id, file_name, file_path, file_type, file_size, upload_date, like_count, dl_count, album_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), file_path = VALUES(file_path)');
            $upMeta = $pdo->prepare('INSERT INTO METADATA (image_id, meta_id, camera, lens, exposure, aperture, focal_length, shutter_speed, date_taken) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE camera = VALUES(camera), lens = VALUES(lens)');
            $linkT  = $pdo->prepare('INSERT IGNORE INTO Image_Tag (image_id, tag_id) VALUES (?, ?)');
            foreach ($records as $rec) {
                // Required fields
                $aid = trim((string) ($rec['album_id'] ?? ''));
                $fn  = trim((string) ($rec['file_name'] ?? ''));
                $fp  = trim((string) ($rec['file_path'] ?? ''));
                if ($aid === '' || $fn === '' || $fp === '') throw new RuntimeException('Missing album_id, file_name, or file_path');
                $iid = trim((string) ($rec['image_id'] ?? '')) ?: genId('img');
                $mid = trim((string) ($rec['meta_id'] ?? ''))  ?: genId('meta');
                // Upsert album, image, then metadata
                $upAlb->execute([$aid, $rec['album_title'] ?? 'Imported Album', $rec['location'] ?? null, parseTs($rec['event_date'] ?? null), $rec['category_id'] ?? 'events']);
                $upImg->execute([$iid, $fn, $fp, $rec['file_type'] ?? 'image/jpeg', max(1, (int) ($rec['file_size'] ?? 100000)), parseTs($rec['upload_date'] ?? $now) ?? $now, (int) ($rec['like_count'] ?? 0), (int) ($rec['dl_count'] ?? 0), $aid, $admin['user_id']]);
                $upMeta->execute([$iid, $mid, $rec['camera'] ?? null, $rec['lens'] ?? null, $rec['exposure'] ?? null, $rec['aperture'] ?? null, ($rec['focal_length'] ?? '') !== '' ? (int) $rec['focal_length'] : null, $rec['shutter_speed'] ?? null, parseTs($rec['date_taken'] ?? null)]);
                // Tags arrive as "name:value; name:value"
                if (!empty($rec['tags'])) {
                    foreach (array_filter(array_map('trim', explode(';', (string) $rec['tags']))) as $pair) {
                        [$tn, $tv] = str_contains($pair, ':') ? array_map('trim', explode(':', $pair, 2)) : ['label', $pair];
                        if ($tn !== '' && $tv !== '') $linkT->execute([$iid, getOrCreateTag($pdo, $tn, $tv)]);
                    }
                }
                $cnt++;
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); jsonResponse(['error' => $e->getMessage()], 400); }
        jsonResponse(['ok' => true, 'imported_count' => $cnt], 201);
    }

    // No route matched
    jsonResponse(['error' => 'Not found'], 404);
} catch (PDOException $e) {
    // DB errors: 23000 = constraint violation -> 409, otherwise 500
    jsonResponse(['error' => $e->getCode() === '23000' ? 'Constraint violation' : 'Database error', 'details' => $e->getMessage()], $e->getCode() === '23000' ? 409 : 500);
} catch (Throwable $e) {
    // Catch-all for other errors
    jsonResponse(['error' => 'Server error', 'details' => $e->getMessage()], 500);
}
