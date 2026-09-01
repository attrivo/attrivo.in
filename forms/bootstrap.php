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
