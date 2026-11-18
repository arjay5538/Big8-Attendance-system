<?php
declare(strict_types=1);

function correctionPath(string $person, string $date, array $times): string
{
    $fingerprint = hash('sha256', implode('|', [$person, $date, ...$times]));
    return dirname(rosterPath()) . DIRECTORY_SEPARATOR . 'corrections' . DIRECTORY_SEPARATOR . $fingerprint . '.json';
}

function correctionCells(array $cells, array $times): array
{
    if (array_keys($cells) !== [0, 1, 2, 3]) {
        throw new RuntimeException('Choose a punch or leave each of the four fields blank.');
    }
    $previous = '';
    foreach ($cells as $time) {
        if ($time === '') {
            continue;
        }
        if (!is_string($time) || !in_array($time, $times, true) || $time <= $previous) {
            throw new RuntimeException('Choose original punches in time order and use each punch once.');
        }
        $previous = $time;
    }
    return $cells;
}

function readCorrection(string $path, array $times): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $data = @file_get_contents($path);
    $cells = $data === false ? null : json_decode($data, true);
    if (!is_array($cells)) {
        throw new RuntimeException('A saved correction could not be read.');
    }
    return correctionCells($cells, $times);
}

function saveCorrection(string $path, ?array $cells): void
{
    if ($cells === null) {
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('The saved correction could not be reset.');
        }
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Apache could not create the correction data folder.');
    }
    $temp = @tempnam($dir, 'punch-');
    if ($temp === false) {
        throw new RuntimeException('Apache could not write to the correction data folder.');
    }
    try {
        $json = json_encode($cells, JSON_THROW_ON_ERROR);
        if (@file_put_contents($temp, $json) !== strlen($json) || !@rename($temp, $path)) {
            throw new RuntimeException('The correction could not be saved.');
        }
    } finally {
        if (is_file($temp)) {
            @unlink($temp);
        }
    }
}
