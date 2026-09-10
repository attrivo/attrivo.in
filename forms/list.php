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

$filter = preg_replace("/[^a-z]/", "", strtolower((string) ($input["formKind"] ?? "")));

forms_json(["ok" => true, "items" => forms_list_submissions($filter)]);
