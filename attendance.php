<?php
declare(strict_types=1);

const AGL_HEADER = ['No', 'TMNo', 'EnNo', 'Name', 'GMNo', 'Mode', 'DateTime'];
const REPORT_COLUMNS = ['Date', 'AM In', 'AM Out', 'PM In', 'PM Out', 'Status'];
const GROUPS = ['all' => 'All people', 'employees' => 'Employees', 'collectors' => 'Collectors'];
const MIDDAY_CHECKOUT_START = '12:00:00';
const MIDDAY_CHECKOUT_END = '13:00:00';

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function validDate(string $date): bool
{
    return preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts)
        && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

function parseAgl(string $contents, string $filename): array
{
    if (substr($contents, 0, 3) === "\xEF\xBB\xBF") {
        $contents = substr($contents, 3);
    } elseif (in_array(substr($contents, 0, 2), ["\xFF\xFE", "\xFE\xFF"], true)) {
        $encoding = $contents[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE';
        $contents = @iconv($encoding, 'UTF-8', substr($contents, 2));
        if ($contents === false) {
            throw new RuntimeException('The file could not be decoded.');
        }
    }

    $lines = preg_split('/\r\n|\n|\r/', $contents);
    if (explode("\t", trim(array_shift($lines))) !== AGL_HEADER) {
        throw new RuntimeException('Choose the attendance TXT or AGL export from the biometric device. Its column header could not be recognized.');
    }

    $people = [];
    $count = $skipped = $duplicates = 0;
    $first = $last = '';
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $fields = array_map('trim', explode("\t", $line));
        if (count($fields) !== 7 || !ctype_digit($fields[1]) || !ctype_digit($fields[2])
            || !preg_match('~^([0-9]{4}/[0-9]{2}/[0-9]{2}) ((?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9])$~D', $fields[6], $match)) {
            $skipped++;
            continue;
        }
        $date = str_replace('/', '-', $match[1]);
        if (!validDate($date)) {
            $skipped++;
            continue;
        }

        $key = $fields[1] . ':' . $fields[2];
        if (!isset($people[$key])) {
            $name = $fields[3];
            if (!preg_match('//u', $name)) {
                $name = @iconv('Windows-1252', 'UTF-8//IGNORE', $name);
            }
            $people[$key] = ['name' => $name ?: 'Unnamed person', 'device' => $fields[1], 'id' => $fields[2], 'days' => []];
        }
        $time = $match[2];
        if (isset($people[$key]['days'][$date][$time])) {
            $duplicates++;
            continue;
        }
        $people[$key]['days'][$date][$time] = $time;
        $first = $first === '' || $date < $first ? $date : $first;
        $last = $date > $last ? $date : $last;
        $count++;
    }

    if ($count === 0) {
        throw new RuntimeException('The file contains no usable attendance punches.');
    }

    uasort($people, static function (array $a, array $b): int {
        return strnatcasecmp($a['name'], $b['name']) ?: strnatcmp($a['id'], $b['id']);
    });
    foreach ($people as &$person) {
        ksort($person['days']);
        foreach ($person['days'] as &$times) {
            sort($times, SORT_STRING);
        }
        unset($times);
    }
    unset($person);

    return compact('filename', 'people', 'count', 'skipped', 'duplicates', 'first', 'last') + ['id' => bin2hex(random_bytes(12))];
}

function rosterGroups(array $people, array $rosters): array
{
    $groups = $unmatched = [];
    foreach ($rosters as $group => $names) {
        $groups[$group] = [];
        $unmatched[$group] = [];
        foreach ($names as $entry) {
            if (isset($people[$entry])) {
                $groups[$group][$entry] = true;
                continue;
            }
            $partial = $exact = [];
            foreach ($people as $key => $person) {
                if (strcasecmp($person['name'], $entry) === 0) {
                    $exact[] = $key;
                } elseif (stripos($person['name'], $entry) !== false) {
                    $partial[] = $key;
                }
            }
            $matches = $exact ?: $partial;
            if (count($matches) === 1) {
                $groups[$group][$matches[0]] = true;
            } else {
                $unmatched[$group][] = $entry;
            }
        }
    }
    return [$groups, $unmatched];
}

function reportRows(array $days, string $from, string $to): array
{
    $rows = [];
    foreach ($days as $date => $times) {
        if ($date < $from || $date > $to) {
            continue;
        }
        $am = $pm = [];
        foreach ($times as $time) {
            if ($time < '12:00:00') {
                $am[] = $time;
            } else {
                $pm[] = $time;
            }
        }
        $middayOut = null;
        $return = null;
        if (count($am) === 1 && count($pm) === 1 && isMiddayCheckout($pm[0])) {
            $middayOut = array_shift($pm);
        } elseif ($am && count($pm) >= 3) {
            $firstMinute = substr($pm[0], 0, 5);
            $lastMinute = substr(end($pm), 0, 5);
            // Prefer a return in a separate minute between the exit and final departure.
            foreach (array_slice($pm, 1, -1) as $time) {
                $minute = substr($time, 0, 5);
                if ($minute !== $firstMinute && $minute !== $lastMinute) {
                    $return = $time;
                    break;
                }
            }
            // With only one morning punch, allow exit and return in the same minute.
            if ($return === null && count($am) === 1 && substr($pm[1], 0, 5) !== $lastMinute) {
                $return = $pm[1];
            }
            if ($return !== null) {
                $middayOut = array_shift($pm);
            }
        }
        $cells = [
            $am[0] ?? '',
            $middayOut ?? (count($am) > 1 ? end($am) : ''),
            $return ?? (count($pm) > 1 ? $pm[0] : ''),
            $pm ? end($pm) : '',
        ];
        $review = count($am) + (int) ($middayOut !== null) !== 2 || count($pm) !== 2;
        $rows[] = compact('date', 'times', 'cells', 'review');
    }
    return $rows;
}

function clockTime(string $time, bool $seconds = false): string
{
    if ($time === '') {
        return '';
    }
    $hour = (int) substr($time, 0, 2);
    return ($hour % 12 ?: 12) . substr($time, 2, $seconds ? 6 : 3) . ($hour < 12 ? ' AM' : ' PM');
}

function isMiddayCheckout(string $time): bool
{
    return $time >= MIDDAY_CHECKOUT_START && $time < MIDDAY_CHECKOUT_END;
}

function attendanceAdjustment(array $cells): array
{
    [$morningIn, $morningOut, $afternoonIn, $afternoonOut] = $cells;
    $minute = substr($morningIn, 0, 5);
    $morningOnly = $morningIn !== '' && isMiddayCheckout($morningOut)
        && $afternoonIn === '' && $afternoonOut === '';
    $halfDays = (int) ($morningOnly || $minute >= '09:31');
    $hours = 0;
    if ($minute > '08:00' && $minute < '09:31') {
        $hours = $minute <= '08:30' ? 1 : 2;
    }
    $labels = [];
    if ($hours > 0) {
        $labels[] = $hours === 1 ? '1 hour late' : '2 hours late';
    }
    if ($halfDays > 0) {
        $labels[] = '½ day absent';
    }
    return compact('hours', 'halfDays') + ['label' => implode(' · ', $labels)];
}

function csvCell(string $value): string
{
    return preg_match('/^[\x00-\x20]*[=+@-]/', $value) ? "'" . $value : $value;
}
