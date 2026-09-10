<?php
require __DIR__ . "/bootstrap.php";
require __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    forms_json(["ok" => false, "error" => "Method not allowed"], 405);
}

$input = json_decode((string) file_get_contents("php://input"), true);
if (!is_array($input)) {
    $input = $_POST;
}

$password = (string) ($input["password"] ?? "");
if (!forms_check_password($password)) {
    forms_json(["ok" => false, "error" => "Invalid password."], 401);
}

$id = trim((string) ($input["id"] ?? ""));
$status = preg_replace("/[^a-z-]/", "", strtolower((string) ($input["status"] ?? "")));
$allowed = [
    "new",
    "working",
    "replied",
    "closed",
    "screen",
    "hm-review",
    "deep-dive",
    "interview",
    "reference",
    "offer",
    "hired",
    "rejected",
    "hold",
    "withdrawn",
];
if ($id === "" || !in_array($status, $allowed, true)) {
    forms_json(["ok" => false, "error" => "Invalid update."], 400);
}

if (!forms_update_status($id, $status)) {
    forms_json(["ok" => false, "error" => "Not found."], 404);
}

// Best-effort mirror to the Google Sheet.
forms_apps_script([
    "action" => "update",
    "password" => (string) (forms_config()["admin_password"] ?? ""),
    "id" => $id,
    "status" => $status,
]);

forms_json(["ok" => true, "updatedAt" => gmdate("c")]);
