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

$filter = preg_replace("/[^a-z]/", "", strtolower((string) ($input["formKind"] ?? "")));
$file = forms_data_dir() . "/submissions.jsonl";
$items = [];
if (is_file($file)) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        if ($filter !== "" && ($row["formKind"] ?? "") !== $filter) {
            continue;
        }
        $items[] = $row;
    }
}

usort($items, function ($a, $b) {
    $aWhen = (string) ($a["updatedAt"] ?? $a["at"] ?? "");
    $bWhen = (string) ($b["updatedAt"] ?? $b["at"] ?? "");
    return strcmp($bWhen, $aWhen);
});
forms_json(["ok" => true, "items" => $items]);
