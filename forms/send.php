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

$to = trim((string) ($input["to"] ?? ""));
$subject = trim((string) ($input["subject"] ?? ""));
$body = trim((string) ($input["body"] ?? ""));
if ($to === "" || $subject === "" || $body === "") {
    forms_json(["ok" => false, "error" => "To, subject, and message are required."], 400);
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    forms_json(["ok" => false, "error" => "Invalid recipient email."], 400);
}

$config = forms_config();
$from = (string) ($config["notify_email"] ?? "info@attrivo.in");
$headers = [
    "From: Attrivo <" . $from . ">",
    "Reply-To: " . $from,
    "MIME-Version: 1.0",
    "Content-Type: text/plain; charset=UTF-8",
];

$ok = @mail($to, $subject, $body, implode("\r\n", $headers));
if (!$ok) {
    forms_json(["ok" => false, "error" => "Server could not send mail."], 500);
}

forms_json(["ok" => true]);
