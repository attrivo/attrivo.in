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

$to = trim((string) ($input["to"] ?? ""));
$subject = trim((string) ($input["subject"] ?? ""));
$body = trim((string) ($input["body"] ?? ""));
$submissionId = trim((string) ($input["submissionId"] ?? ""));
if ($to === "" || $subject === "" || $body === "") {
    forms_json(["ok" => false, "error" => "To, subject, and message are required."], 400);
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    forms_json(["ok" => false, "error" => "Invalid recipient email."], 400);
}

$config = forms_config();

// Send through Gmail / Workspace via the Apps Script.
[$sent, $err] = forms_apps_script([
    "action" => "send",
    "password" => (string) ($config["admin_password"] ?? ""),
    "to" => $to,
    "subject" => $subject,
    "body" => $body,
]);

// Fall back to server mail() only if the Apps Script is not configured at all.
if (!$sent && (string) ($config["apps_script_url"] ?? "") === "") {
    $from = (string) ($config["notify_email"] ?? "info@attrivo.in");
    $headers = [
        "From: Attrivo <{$from}>",
        "Reply-To: {$from}",
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
    ];
    $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
    $err = $sent ? "" : "Server could not send mail.";
}

if (!$sent) {
    forms_json(["ok" => false, "error" => $err ?: "Could not send the reply."], 502);
}

// Record the outbound message on the conversation thread.
if ($submissionId !== "") {
    forms_add_message($submissionId, "out", $subject, $body, "Attrivo");
}

forms_json(["ok" => true]);
