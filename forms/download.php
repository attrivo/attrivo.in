<?php
require __DIR__ . "/bootstrap.php";

$password = (string) ($_GET["password"] ?? $_POST["password"] ?? "");
if (!forms_check_password($password)) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

$file = basename((string) ($_GET["file"] ?? ""));
if (!preg_match("/^[A-Za-z0-9._-]+$/", $file)) {
    http_response_code(400);
    echo "Invalid file";
    exit;
}

$path = forms_uploads_dir() . "/" . $file;
if (!is_file($path)) {
    http_response_code(404);
    echo "Not found";
    exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mimes = [
    "pdf" => "application/pdf",
    "doc" => "application/msword",
    "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "png" => "image/png",
    "jpg" => "image/jpeg",
    "jpeg" => "image/jpeg",
    "gif" => "image/gif",
    "webp" => "image/webp",
];
$mime = $mimes[$ext] ?? "application/octet-stream";
$inline = isset($_GET["inline"]) && $_GET["inline"] !== "0";
$canInline = in_array($ext, ["pdf", "png", "jpg", "jpeg", "gif", "webp"], true);

header("Content-Type: " . $mime);
header("X-Content-Type-Options: nosniff");
header(
    "Content-Disposition: " . ($inline && $canInline ? "inline" : "attachment") .
    "; filename=\"" . $file . "\""
);
readfile($path);
