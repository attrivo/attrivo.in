<?php
/**
 * One-time import of forms/data/submissions.jsonl into MySQL.
 *
 *   https://attrivo.in/forms/migrate.php?password=YOUR_ADMIN_PASSWORD
 *
 * Safe to run more than once — existing ids are skipped. Delete this file
 * (or leave it; it does nothing without the password) once migration is done.
 */

require __DIR__ . "/bootstrap.php";
require __DIR__ . "/db.php";

header("Content-Type: text/plain; charset=utf-8");

$password = (string) ($_GET["password"] ?? $_POST["password"] ?? "");
if (!forms_check_password($password)) {
    http_response_code(401);
    echo "Unauthorized\n";
    exit;
}

$pdo = forms_db();
if (!$pdo) {
    http_response_code(500);
    echo "No database configured — set the db block in forms/config.php first.\n";
    exit;
}

$file = forms_jsonl_path();
if (!is_file($file)) {
    echo "Nothing to migrate: {$file} does not exist.\n";
    exit;
}

$have = $pdo->query("SELECT id FROM submissions")->fetchAll(PDO::FETCH_COLUMN);
$have = array_flip($have);

$imported = 0;
$skipped = 0;
$threadRows = 0;

foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $row = json_decode($line, true);
    if (!is_array($row) || empty($row["id"])) {
        continue;
    }
    if (isset($have[$row["id"]])) {
        $skipped++;
        continue;
    }

    // Normalise flat-file shape → forms_store_submission shape.
    $record = [
        "id" => $row["id"],
        "at" => $row["at"] ?? gmdate("c"),
        "updatedAt" => $row["updatedAt"] ?? $row["at"] ?? gmdate("c"),
        "formKind" => $row["formKind"] ?? "contact",
        "page" => $row["page"] ?? "",
        "section" => $row["section"] ?? "",
        "status" => $row["status"] ?? "new",
        "history" => $row["history"] ?? [["at" => $row["at"] ?? gmdate("c"), "status" => "new"]],
        "fields" => $row["fields"] ?? [],
        "resume" => $row["resume"] ?? null,
        "ip" => $row["ip"] ?? null,
    ];
    forms_store_submission($record);
    $imported++;

    foreach ($row["thread"] ?? [] as $m) {
        forms_add_message(
            $row["id"],
            ($m["direction"] ?? "out") === "in" ? "in" : "out",
            (string) ($m["subject"] ?? ""),
            (string) ($m["body"] ?? ""),
            (string) ($m["by"] ?? "")
        );
        $threadRows++;
    }
}

echo "Imported: {$imported}\nSkipped (already present): {$skipped}\nThread messages: {$threadRows}\n";
