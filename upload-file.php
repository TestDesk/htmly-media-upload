<?php
/**
 * HTMLy Media Upload Handler
 *
 * Adds native file upload support for PDF, audio and video files to HTMLy.
 * Uploaded files are stored in content/files/ and are included in HTMLy backups.
 *
 * Supported formats:
 *   PDF:   pdf
 *   Audio: mp3, ogg, wav, aac, flac, m4a
 *   Video: mp4, webm, ogv, mov
 *
 * Installation:
 *   1. Place upload-file.php in the HTMLy root directory (same level as index.php).
 *   2. Apply the editor patch from add-content.patch.html to:
 *        system/admin/views/add-content.html.php
 *        system/admin/views/edit-content.html.php
 *        system/admin/views/add-page.html.php
 *        system/admin/views/edit-page.html.php
 *
 * @package  htmly-media-upload
 * @author   TestDesk
 * @link     https://github.com/testdesk/htmly-media-upload
 * @version  1.0.1
 * @requires HTMLy v3.1.1+
 * @license  GPL-2.0
 */

// Start session the same way HTMLy does
$samesite = 'strict';
if (PHP_VERSION_ID < 70300) {
    session_set_cookie_params('samesite=' . $samesite);
} else {
    session_set_cookie_params(['samesite' => $samesite]);
}
session_start();

header('Content-Type: application/json');

// Reconstruct site URL to match HTMLy's session key
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'];
$siteUrl  = $protocol . '://' . $host;

// Check HTMLy login session
$loggedIn = isset($_SESSION[$siteUrl]['user']) && !empty($_SESSION[$siteUrl]['user']);
if (!$loggedIn) {
    $loggedIn = isset($_SESSION[$siteUrl . '/']['user']) && !empty($_SESSION[$siteUrl . '/']['user']);
}

if (!$loggedIn) {
    http_response_code(403);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

// Allowed MIME types and their extensions
$allowed = [
    'application/pdf'  => 'pdf',
    'audio/mpeg'       => 'mp3',
    'audio/mp3'        => 'mp3',
    'audio/ogg'        => 'ogg',
    'audio/wav'        => 'wav',
    'audio/x-wav'      => 'wav',
    'audio/aac'        => 'aac',
    'audio/flac'       => 'flac',
    'audio/x-flac'     => 'flac',
    'audio/mp4'        => 'm4a',
    'audio/x-m4a'      => 'm4a',
    'video/mp4'        => 'mp4',
    'video/webm'       => 'webm',
    'video/ogg'        => 'ogv',
    'video/quicktime'  => 'mov',
];

// Maximum file size: 200 MB
$maxSize = 200 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    echo json_encode(['error' => 'No file received']);
    exit;
}

$file     = $_FILES['file'];
$tmpPath  = $file['tmp_name'];
$origName = basename($file['name']);
$size     = $file['size'];
$error    = $file['error'];

// Handle upload errors
if ($error !== UPLOAD_ERR_OK) {
    $msgs = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in form',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION  => 'Upload stopped by PHP extension',
    ];
    echo json_encode(['error' => $msgs[$error] ?? 'Unknown upload error: ' . $error]);
    exit;
}

// Check file size
if ($size > $maxSize) {
    echo json_encode(['error' => 'File too large (max. 200 MB)']);
    exit;
}

// Validate MIME type
$mime = mime_content_type($tmpPath);
if (!array_key_exists($mime, $allowed)) {
    echo json_encode(['error' => 'File type not allowed: ' . $mime]);
    exit;
}

$ext = $allowed[$mime];

// Sanitize filename
$safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '-', pathinfo($origName, PATHINFO_FILENAME));
$safeName = strtolower(trim($safeName, '-'));
$safeName = preg_replace('/-+/', '-', $safeName);

// Target directory
$uploadDir = __DIR__ . '/content/files/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Use original filename; append -2, -3 etc. if file already exists
$filename = $safeName . '.' . $ext;
if (file_exists($uploadDir . $filename)) {
    $counter = 2;
    while (file_exists($uploadDir . $safeName . '-' . $counter . '.' . $ext)) {
        $counter++;
    }
    $filename = $safeName . '-' . $counter . '.' . $ext;
}

$destPath = $uploadDir . $filename;

if (!move_uploaded_file($tmpPath, $destPath)) {
    echo json_encode(['error' => 'Could not save file']);
    exit;
}

// Determine type for tag generation in the editor
if ($ext === 'pdf') {
    $type = 'pdf';
} elseif (in_array($ext, ['mp3', 'ogg', 'wav', 'aac', 'flac', 'm4a'])) {
    $type = 'audio';
} else {
    $type = 'video';
}

$publicPath = '/content/files/' . $filename;

echo json_encode([
    'path'     => $publicPath,
    'filename' => $filename,
    'type'     => $type,
    'ext'      => $ext,
    'error'    => 0,
]);
