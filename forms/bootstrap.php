<?php

function forms_config(): array
{
    $local = __DIR__ . "/config.php";
    $example = __DIR__ . "/config.example.php";
    if (is_file($local)) {
        return require $local;
    }
    return require $example;
}

function forms_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode($payload);
    exit;
}

function forms_data_dir(): string
{
    $dir = __DIR__ . "/data";
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function forms_uploads_dir(): string
{
    $dir = __DIR__ . "/uploads";
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function forms_check_password(string $password): bool
{
    $config = forms_config();
    $expected = (string) ($config["admin_password"] ?? "");
    if ($expected === "" || $password === "") {
        return false;
    }
    return hash_equals($expected, $password);
}

/** Normalise an ISO-8601 string to a MySQL DATETIME (UTC). */
function forms_dt(?string $iso): string
{
    $ts = $iso ? strtotime($iso) : false;
    return gmdate("Y-m-d H:i:s", $ts ?: time());
}

/**
 * PDO handle for the forms database, or null when no DB is configured
 * or the connection fails (callers fall back to the flat-file store).
 */
function forms_db(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }

    $cfg = forms_config()["db"] ?? null;
    if (!is_array($cfg) || empty($cfg["name"])) {
        return $pdo = null;
    }

    try {
        $host = (string) ($cfg["host"] ?? "localhost");
        $dsn = "mysql:host={$host};dbname={$cfg["name"]};charset=utf8mb4";
        $pdo = new PDO($dsn, (string) ($cfg["user"] ?? ""), (string) ($cfg["pass"] ?? ""), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        error_log("forms_db: " . $e->getMessage());
        $pdo = null;
    }

    return $pdo;
}

/**
 * POST a payload to the Google Apps Script Web App (Gmail send + Sheet mirror).
 * Returns [bool ok, string error].
 */
function forms_apps_script(array $params): array
{
    $url = (string) (forms_config()["apps_script_url"] ?? "");
    if ($url === "") {
        return [false, "apps_script_url is not configured"];
    }

    $body = http_build_query($params);

    if (function_exists("curl_init")) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded"],
        ]);
        $res = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            return [false, $curlErr ?: "request failed"];
        }
    } else {
        $ctx = stream_context_create([
            "http" => [
                "method" => "POST",
                "header" => "Content-Type: application/x-www-form-urlencoded",
                "content" => $body,
                "timeout" => 25,
                "ignore_errors" => true,
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            return [false, "request failed"];
        }
    }

    $json = json_decode((string) $res, true);
    if (!is_array($json)) {
        return [false, "unexpected response"];
    }
    return [(bool) ($json["ok"] ?? false), (string) ($json["error"] ?? "")];
}
