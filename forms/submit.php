<?php
require __DIR__ . "/bootstrap.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    forms_json(["ok" => false, "error" => "Method not allowed"], 405);
}

if (!empty($_POST["website"])) {
    forms_json(["ok" => true, "id" => "ignored"]);
}

$kind = preg_replace("/[^a-z]/", "", strtolower((string) ($_POST["formKind"] ?? "")));
$allowed = ["contact", "demo", "pricing", "solutions", "careers"];
if (!in_array($kind, $allowed, true)) {
    forms_json(["ok" => false, "error" => "Unknown form."], 400);
}

$firstName = trim((string) ($_POST["firstName"] ?? ""));
$lastName = trim((string) ($_POST["lastName"] ?? ""));
$mobile = trim((string) ($_POST["mobile"] ?? ""));
$email = trim((string) ($_POST["email"] ?? ""));
if ($firstName === "" || $lastName === "" || $mobile === "" || $email === "") {
    forms_json(["ok" => false, "error" => "Please fill in all required fields."], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    forms_json(["ok" => false, "error" => "Please enter a valid email."], 400);
}

$ip = (string) ($_SERVER["REMOTE_ADDR"] ?? "unknown");
$rateFile = forms_data_dir() . "/rate-" . preg_replace("/[^a-zA-Z0-9._-]/", "_", $ip) . ".json";
$now = time();
$hits = [];
if (is_file($rateFile)) {
    $hits = json_decode((string) file_get_contents($rateFile), true) ?: [];
}
$hits = array_values(array_filter($hits, fn($t) => is_int($t) && $t > $now - 3600));
if (count($hits) >= 12) {
    forms_json(["ok" => false, "error" => "Too many submissions. Please try again later."], 429);
}
$hits[] = $now;
file_put_contents($rateFile, json_encode($hits));

$id = date("YmdHis") . "-" . bin2hex(random_bytes(4));
$resumeMeta = null;
if (!empty($_FILES["resume"]) && is_uploaded_file($_FILES["resume"]["tmp_name"])) {
    $name = (string) $_FILES["resume"]["name"];
    $size = (int) $_FILES["resume"]["size"];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $okExt = ["pdf", "doc", "docx"];
    if ($size > 4 * 1024 * 1024 || !in_array($ext, $okExt, true)) {
        forms_json(["ok" => false, "error" => "Resume must be PDF or Word, up to 4 MB."], 400);
    }
    $stored = $id . "." . $ext;
    if (!move_uploaded_file($_FILES["resume"]["tmp_name"], forms_uploads_dir() . "/" . $stored)) {
        forms_json(["ok" => false, "error" => "Could not store resume."], 500);
    }
    $resumeMeta = ["name" => $name, "stored" => $stored];
}

$fields = [
    "firstName" => $firstName,
    "lastName" => $lastName,
    "mobile" => $mobile,
    "email" => $email,
    "company" => trim((string) ($_POST["company"] ?? "")),
    "message" => trim((string) ($_POST["message"] ?? "")),
    "plan" => trim((string) ($_POST["plan"] ?? "")),
    "industry" => trim((string) ($_POST["industry"] ?? "")),
    "role" => trim((string) ($_POST["role"] ?? "")),
    "linkedin" => trim((string) ($_POST["linkedin"] ?? "")),
];
$fields = array_filter($fields, fn($v) => $v !== "");

$record = [
    "id" => $id,
    "at" => gmdate("c"),
    "formKind" => $kind,
    "page" => substr(trim((string) ($_POST["page"] ?? "")), 0, 120),
    "section" => substr(trim((string) ($_POST["section"] ?? "")), 0, 80),
    "fields" => $fields,
    "resume" => $resumeMeta,
    "status" => "new",
    "updatedAt" => gmdate("c"),
    "history" => [["at" => gmdate("c"), "status" => "new"]],
];

$store = forms_data_dir() . "/submissions.jsonl";
file_put_contents($store, json_encode($record) . PHP_EOL, FILE_APPEND | LOCK_EX);

$config = forms_config();
$notify = (string) ($config["notify_email"] ?? "");
if ($notify !== "" && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
    $subject = "Attrivo website — " . $kind . " — " . $firstName . " " . $lastName;
    $lines = [
        "Form: " . $kind,
        "Page: " . $record["page"],
        "Section: " . $record["section"],
        "",
    ];
    foreach ($fields as $key => $value) {
        $lines[] = $key . ": " . $value;
    }
    @mail($notify, $subject, implode("\n", $lines), "Reply-To: " . $email);
}

forms_json(["ok" => true, "id" => $id]);
