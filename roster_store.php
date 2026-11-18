<?php
declare(strict_types=1);

function readRosters(): array
{
    $defaults = require __DIR__ . '/rosters.php';
    $path = rosterPath();
    if (!is_file($path)) {
        return $defaults;
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The roster could not be read.');
    }
    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException('The roster could not be locked for reading.');
        }
        return decodeRosters(stream_get_contents($handle), $defaults);
    } finally {
        fclose($handle);
    }
}

function rosterPath(): string
{
    $configured = getenv('BIG8_DATA_DIR');
    if ($configured !== false && $configured !== '') {
        return rtrim($configured, '/\\') . DIRECTORY_SEPARATOR . 'rosters.json';
    }
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($root === false) {
        throw new RuntimeException('Apache document root could not be located.');
    }
    return dirname($root) . DIRECTORY_SEPARATOR . 'big8-attendance-data' . DIRECTORY_SEPARATOR . 'rosters.json';
}

function decodeRosters($json, array $defaults): array
{
    if ($json === '') {
        return $defaults;
    }
    $rosters = json_decode((string) $json, true);
    if (!is_array($rosters) || !isset($rosters['employees'], $rosters['collectors'])
        || !is_array($rosters['employees']) || !is_array($rosters['collectors'])) {
        throw new RuntimeException('The saved roster could not be read.');
    }
    return $rosters;
}

function updateRoster(string $group, string $entry, bool $remove): void
{
    $path = rosterPath();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Apache could not create the roster data folder. See README.md for setup.');
    }
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Apache could not open the roster data folder for writing.');
    }
    $temp = null;
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('The roster is busy. Try again.');
        }
        $rosters = readRosters();
        $entries = array_values(array_filter($rosters[$group], static fn(string $item): bool => strcasecmp($item, $entry) !== 0));
        if (!$remove) {
            $entries[] = $entry;
        }
        $rosters[$group] = $entries;
        $json = json_encode($rosters, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $temp = @tempnam($directory, 'roster-');
        if ($temp === false || $json === false || @file_put_contents($temp, $json) !== strlen($json) || !@rename($temp, $path)) {
            throw new RuntimeException('The roster could not be saved.');
        }
    } finally {
        if (is_string($temp) && is_file($temp)) {
            @unlink($temp);
        }
        fclose($lock);
    }
}
