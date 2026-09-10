<?php
/**
 * Data access for website form submissions.
 * MySQL is the primary store; when no DB is configured (or it is unreachable)
 * everything falls back to forms/data/submissions.jsonl so no lead is lost.
 */

require_once __DIR__ . "/bootstrap.php";

function forms_jsonl_path(): string
{
    return forms_data_dir() . "/submissions.jsonl";
}

/** Shape a DB row (+ its messages) into the object the admin UI expects. */
function forms_row_to_item(array $row, array $messages = []): array
{
    $fields = array_filter([
        "firstName" => $row["first_name"] ?? "",
        "lastName" => $row["last_name"] ?? "",
        "mobile" => $row["mobile"] ?? "",
        "email" => $row["email"] ?? "",
        "company" => $row["company"] ?? "",
        "message" => $row["message"] ?? "",
        "plan" => $row["plan"] ?? "",
        "industry" => $row["industry"] ?? "",
        "role" => $row["role"] ?? "",
        "linkedin" => $row["linkedin"] ?? "",
    ], fn($v) => $v !== "" && $v !== null);

    $history = json_decode((string) ($row["history"] ?? "[]"), true);

    $resume = null;
    if (!empty($row["resume_name"]) || !empty($row["resume_stored"])) {
        $resume = array_filter([
            "name" => $row["resume_name"] ?? "Resume",
            "stored" => $row["resume_stored"] ?? null,
            "url" => $row["resume_url"] ?? null,
        ], fn($v) => $v !== null && $v !== "");
    }

    return [
        "id" => (string) $row["id"],
        "at" => gmdate("c", strtotime((string) $row["created_at"] . " UTC")),
        "updatedAt" => gmdate("c", strtotime((string) ($row["updated_at"] ?? $row["created_at"]) . " UTC")),
        "formKind" => (string) $row["form_kind"],
        "page" => (string) ($row["page"] ?? ""),
        "section" => (string) ($row["section"] ?? ""),
        "status" => (string) ($row["status"] ?? "new"),
        "history" => is_array($history) ? $history : [],
        "thread" => array_map(fn($m) => [
            "at" => gmdate("c", strtotime((string) $m["created_at"] . " UTC")),
            "direction" => (string) $m["direction"],
            "subject" => (string) ($m["subject"] ?? ""),
            "body" => (string) ($m["body"] ?? ""),
            "by" => (string) ($m["sender"] ?? ""),
        ], $messages),
        "fields" => (object) $fields,
        "resume" => $resume ?: null,
    ];
}

/** Persist a new submission. $record is the same array shape used by the flat file. */
function forms_store_submission(array $record): void
{
    $pdo = forms_db();
    if ($pdo) {
        try {
            $f = $record["fields"];
            $stmt = $pdo->prepare(
                "INSERT INTO submissions
                   (id, created_at, updated_at, form_kind, page, section, status,
                    first_name, last_name, mobile, email, company, message,
                    plan, industry, role, linkedin, resume_name, resume_stored, resume_url, history, ip)
                 VALUES
                   (:id, :created_at, :updated_at, :form_kind, :page, :section, :status,
                    :first_name, :last_name, :mobile, :email, :company, :message,
                    :plan, :industry, :role, :linkedin, :resume_name, :resume_stored, :resume_url, :history, :ip)"
            );
            $stmt->execute([
                ":id" => $record["id"],
                ":created_at" => forms_dt($record["at"] ?? null),
                ":updated_at" => forms_dt($record["updatedAt"] ?? $record["at"] ?? null),
                ":form_kind" => $record["formKind"],
                ":page" => $record["page"] ?? "",
                ":section" => $record["section"] ?? "",
                ":status" => $record["status"] ?? "new",
                ":first_name" => $f["firstName"] ?? "",
                ":last_name" => $f["lastName"] ?? "",
                ":mobile" => $f["mobile"] ?? "",
                ":email" => $f["email"] ?? "",
                ":company" => $f["company"] ?? "",
                ":message" => $f["message"] ?? "",
                ":plan" => $f["plan"] ?? "",
                ":industry" => $f["industry"] ?? "",
                ":role" => $f["role"] ?? "",
                ":linkedin" => $f["linkedin"] ?? "",
                ":resume_name" => $record["resume"]["name"] ?? null,
                ":resume_stored" => $record["resume"]["stored"] ?? null,
                ":resume_url" => $record["resume"]["url"] ?? null,
                ":history" => json_encode($record["history"] ?? [["at" => $record["at"], "status" => "new"]]),
                ":ip" => $record["ip"] ?? null,
            ]);
            return;
        } catch (Throwable $e) {
            error_log("forms_store_submission: " . $e->getMessage());
        }
    }

    file_put_contents(forms_jsonl_path(), json_encode($record) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** List submissions (newest first), optionally filtered by form kind. */
function forms_list_submissions(string $filter = ""): array
{
    $pdo = forms_db();
    if ($pdo) {
        try {
            $where = "";
            $args = [];
            if ($filter !== "") {
                $where = " WHERE form_kind = :k";
                $args[":k"] = $filter;
            }
            $rows = $pdo->prepare("SELECT * FROM submissions{$where} ORDER BY updated_at DESC, created_at DESC");
            $rows->execute($args);
            $rows = $rows->fetchAll();

            $byId = [];
            foreach ($rows as $r) {
                $byId[$r["id"]] = [];
            }
            if ($byId) {
                $in = implode(",", array_fill(0, count($byId), "?"));
                $msgStmt = $pdo->prepare(
                    "SELECT * FROM messages WHERE submission_id IN ({$in}) ORDER BY created_at ASC"
                );
                $msgStmt->execute(array_keys($byId));
                foreach ($msgStmt->fetchAll() as $m) {
                    $byId[$m["submission_id"]][] = $m;
                }
            }

            return array_map(fn($r) => forms_row_to_item($r, $byId[$r["id"]] ?? []), $rows);
        } catch (Throwable $e) {
            error_log("forms_list_submissions: " . $e->getMessage());
        }
    }

    // Flat-file fallback.
    $file = forms_jsonl_path();
    if (!is_file($file)) {
        return [];
    }
    $items = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        if ($filter !== "" && ($row["formKind"] ?? "") !== $filter) {
            continue;
        }
        $items[] = $row;
    }
    usort($items, fn($a, $b) => strcmp(
        (string) ($b["updatedAt"] ?? $b["at"] ?? ""),
        (string) ($a["updatedAt"] ?? $a["at"] ?? "")
    ));
    return $items;
}

/** Move a submission to a new pipeline status. Returns true if a row changed. */
function forms_update_status(string $id, string $status): bool
{
    $now = gmdate("c");
    $pdo = forms_db();
    if ($pdo) {
        try {
            $cur = $pdo->prepare("SELECT history FROM submissions WHERE id = :id");
            $cur->execute([":id" => $id]);
            $row = $cur->fetch();
            if (!$row) {
                return false;
            }
            $history = json_decode((string) ($row["history"] ?? "[]"), true);
            if (!is_array($history)) {
                $history = [];
            }
            $history[] = ["at" => $now, "status" => $status];
            $upd = $pdo->prepare(
                "UPDATE submissions SET status = :s, updated_at = :u, history = :h WHERE id = :id"
            );
            $upd->execute([
                ":s" => $status,
                ":u" => forms_dt($now),
                ":h" => json_encode($history),
                ":id" => $id,
            ]);
            return $upd->rowCount() > 0;
        } catch (Throwable $e) {
            error_log("forms_update_status: " . $e->getMessage());
        }
    }

    // Flat-file fallback.
    $file = forms_jsonl_path();
    if (!is_file($file)) {
        return false;
    }
    $out = [];
    $found = false;
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
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
        return false;
    }
    file_put_contents($file, implode(PHP_EOL, $out) . PHP_EOL, LOCK_EX);
    return true;
}

/** Append a message to a submission's conversation thread. */
function forms_add_message(string $id, string $direction, string $subject, string $body, string $sender = ""): void
{
    $now = gmdate("c");
    $pdo = forms_db();
    if ($pdo) {
        try {
            $pdo->prepare(
                "INSERT INTO messages (submission_id, created_at, direction, subject, body, sender)
                 VALUES (:id, :at, :dir, :sub, :body, :sender)"
            )->execute([
                ":id" => $id,
                ":at" => forms_dt($now),
                ":dir" => $direction === "in" ? "in" : "out",
                ":sub" => $subject,
                ":body" => $body,
                ":sender" => $sender,
            ]);
            $pdo->prepare("UPDATE submissions SET updated_at = :u WHERE id = :id")
                ->execute([":u" => forms_dt($now), ":id" => $id]);
            return;
        } catch (Throwable $e) {
            error_log("forms_add_message: " . $e->getMessage());
        }
    }

    // Flat-file fallback.
    $file = forms_jsonl_path();
    if (!is_file($file)) {
        return;
    }
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (trim($line) === "") {
            continue;
        }
        $row = json_decode($line, true);
        if (is_array($row) && (string) ($row["id"] ?? "") === $id) {
            $thread = is_array($row["thread"] ?? null) ? $row["thread"] : [];
            $thread[] = [
                "at" => $now,
                "direction" => $direction === "in" ? "in" : "out",
                "subject" => $subject,
                "body" => $body,
                "by" => $sender,
            ];
            $row["thread"] = $thread;
            $row["updatedAt"] = $now;
        }
        $out[] = json_encode($row);
    }
    file_put_contents($file, implode(PHP_EOL, $out) . PHP_EOL, LOCK_EX);
}
