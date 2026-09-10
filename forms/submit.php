<?php
require __DIR__ . "/bootstrap.php";
require __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    forms_json(["ok" => false, "error" => "Method not allowed"], 405);
}

// Honeypot — pretend success.
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

// Simple per-IP rate limit (12 / hour).
$ip = (string) ($_SERVER["REMOTE_ADDR"] ?? "unknown");
$rateFile = forms_data_dir() . "/rate-" . preg_replace("/[^a-zA-Z0-9._-]/", "_", $ip) . ".json";
$now = time();
$hits = is_file($rateFile) ? (json_decode((string) file_get_contents($rateFile), true) ?: []) : [];
$hits = array_values(array_filter($hits, fn($t) => is_int($t) && $t > $now - 3600));
if (count($hits) >= 12) {
    forms_json(["ok" => false, "error" => "Too many submissions. Please try again later."], 429);
}
$hits[] = $now;
file_put_contents($rateFile, json_encode($hits));

$id = date("YmdHis") . "-" . bin2hex(random_bytes(4));

// Optional résumé (careers).
$resumeMeta = null;
$resumeBase64 = "";
if (!empty($_FILES["resume"]) && is_uploaded_file($_FILES["resume"]["tmp_name"])) {
    $name = (string) $_FILES["resume"]["name"];
    $size = (int) $_FILES["resume"]["size"];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($size > 4 * 1024 * 1024 || !in_array($ext, ["pdf", "doc", "docx"], true)) {
        forms_json(["ok" => false, "error" => "Resume must be PDF or Word, up to 4 MB."], 400);
    }
    $stored = $id . "." . $ext;
    if (!move_uploaded_file($_FILES["resume"]["tmp_name"], forms_uploads_dir() . "/" . $stored)) {
        forms_json(["ok" => false, "error" => "Could not store resume."], 500);
    }
    $mime = (string) ($_FILES["resume"]["type"] ?: "application/octet-stream");
    $resumeMeta = ["name" => $name, "stored" => $stored, "mime" => $mime];
    $resumeBase64 = base64_encode((string) file_get_contents(forms_uploads_dir() . "/" . $stored));
}

$fields = array_filter([
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
], fn($v) => $v !== "");

$iso = gmdate("c");
$record = [
    "id" => $id,
    "at" => $iso,
    "updatedAt" => $iso,
    "formKind" => $kind,
    "page" => substr(trim((string) ($_POST["page"] ?? "")), 0, 120),
    "section" => substr(trim((string) ($_POST["section"] ?? "")), 0, 80),
    "fields" => $fields,
    "resume" => $resumeMeta,
    "status" => "new",
    "history" => [["at" => $iso, "status" => "new"]],
    "ip" => $ip,
];

// 1. Primary store — MySQL (flat file when no DB configured).
forms_store_submission($record);

// 2. Mirror + email — Google Apps Script (Gmail send + Google Sheet row + Drive résumé).
$config = forms_config();
[$mailed, $mailErr] = forms_apps_script(array_filter([
    "action" => "submit",
    "id" => $id,
    "formKind" => $kind,
    "page" => $record["page"],
    "section" => $record["section"],
    "firstName" => $firstName,
    "lastName" => $lastName,
    "mobile" => $mobile,
    "email" => $email,
    "company" => $fields["company"] ?? "",
    "message" => $fields["message"] ?? "",
    "plan" => $fields["plan"] ?? "",
    "industry" => $fields["industry"] ?? "",
    "role" => $fields["role"] ?? "",
    "linkedin" => $fields["linkedin"] ?? "",
    "to" => (string) ($config["notify_email"] ?? "info@attrivo.in"),
    "resumeName" => $resumeMeta["name"] ?? "",
    "resumeMime" => $resumeMeta["mime"] ?? "",
    "resumeBase64" => $resumeBase64,
], fn($v) => $v !== "" && $v !== null));

if (!$mailed) {
    error_log("submit.php: apps script mirror failed for {$id}: {$mailErr}");
    // Last-ditch local mail so a copy still reaches the inbox owner.
    $notify = (string) ($config["notify_email"] ?? "");
    if ($notify !== "" && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
        $lines = ["Form: {$kind}", "Page: {$record["page"]}", ""];
        foreach ($fields as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        @mail($notify, "Attrivo website — {$kind} — {$firstName} {$lastName}", implode("\n", $lines), "Reply-To: {$email}");
    }
}

forms_json(["ok" => true, "id" => $id, "mailed" => $mailed]);
