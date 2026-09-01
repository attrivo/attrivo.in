<?php
require __DIR__ . "/bootstrap.php";

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

$file = forms_data_dir() . "/submissions.jsonl";
if (!is_file($file)) {
    forms_json(["ok" => false, "error" => "Not found."], 404);
}

$lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
$out = [];
$found = false;
$now = gmdate("c");
foreach ($lines as $line) {
    if (trim($line) === "") {
        continue;
    }
    $row = json_decode($line, true);
    if (is_array($row) && (string) ($row["id"] ?? "") === $id) {
        $row["status"] = $status;
        $row["updatedAt"] = $now;
        $history = is_array($row["history"] ?? null) ? $row["history"] : [];
        $history[] = ["at" => $now, "status" => $status];
        $row["history"] = $history;
        $found = true;
    }
    $out[] = json_encode($row);
}

if (!$found) {
    forms_json(["ok" => false, "error" => "Not found."], 404);
}

file_put_contents($file, implode(PHP_EOL, $out) . PHP_EOL, LOCK_EX);
forms_json(["ok" => true, "updatedAt" => $now]);
