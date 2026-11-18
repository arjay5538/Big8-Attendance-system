<?php
declare(strict_types=1);

require __DIR__ . '/attendance.php';
require __DIR__ . '/roster_store.php';
require __DIR__ . '/correction_store.php';

function input(array $source, string $key, string $default = ''): string
{
    return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : $default;
}

function redirectTo(array $query = []): void
{
    header('Location: index.php' . ($query ? '?' . http_build_query($query) : ''), true, 303);
    exit;
}

ini_set('session.use_strict_mode', '1');
session_name('Big8Attendance');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Cache-Control: no-store');
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}
$token = $_SESSION['csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = input($_POST, 'action');
    $destination = in_array($action, ['roster-add', 'roster-remove'], true) ? ['view' => 'roster'] : [];
    $editing = in_array($action, ['save-punches', 'reset-punches'], true);
    if ($editing) {
        $destination = ['report' => '1'];
        foreach (['import', 'group', 'person', 'from', 'to', 'edit'] as $field) {
            $destination[$field] = input($_POST, $field);
        }
    }
    try {
        if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new RuntimeException('PHP could not accept the form data. If the file is too large, see README.md for the XAMPP upload settings.');
        }
        if (!hash_equals($token, input($_POST, 'csrf'))) {
            throw new RuntimeException('The form expired. Reload the page and try again.');
        }
        if ($action === 'import') {
            $file = $_FILES['log'] ?? [];
            $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new RuntimeException('The file is larger than the upload limit. See README.md for the XAMPP settings.');
            }
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new RuntimeException('Choose a biometric TXT or AGL file to upload.');
            }
            if ($file['size'] > 8 * 1024 * 1024 || $file['size'] === 0) {
                throw new RuntimeException('Choose a file between 1 byte and 8 MB.');
            }
            $name = basename(str_replace('\\', '/', $file['name']));
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['txt', 'agl'], true)) {
                throw new RuntimeException('Choose a file with a .txt or .agl extension.');
            }
            $contents = file_get_contents($file['tmp_name']);
            if ($contents === false) {
                throw new RuntimeException('The uploaded file could not be read.');
            }
            $_SESSION['import'] = parseAgl($contents, $name);
            $_SESSION['notice'] = 'Attendance log imported.';
        } elseif ($editing) {
            $active = $_SESSION['import'] ?? [];
            $editedDate = $destination['edit'];
            $editedPerson = $destination['person'];
            $times = $active['people'][$editedPerson]['days'][$editedDate] ?? [];
            if ($destination['import'] !== ($active['id'] ?? null) || !$times
                || $editedDate < $destination['from'] || $editedDate > $destination['to']) {
                throw new RuntimeException('Open the report again before editing these punches.');
            }
            $cells = $action === 'save-punches' ? correctionCells((array) ($_POST['cells'] ?? []), $times) : null;
            saveCorrection(correctionPath($editedPerson, $editedDate, $times), $cells);
            $_SESSION['notice'] = $cells === null ? 'Restored the suggested punches.' : 'Punch correction saved.';
            unset($destination['edit']);
        } elseif ($action === 'clear') {
            unset($_SESSION['import']);
            $_SESSION['notice'] = 'Import cleared.';
        } elseif (in_array($action, ['roster-add', 'roster-remove'], true)) {
            $group = input($_POST, 'roster');
            if (!in_array($group, ['employees', 'collectors'], true)) {
                throw new RuntimeException('Choose a roster.');
            }
            $entry = trim(input($_POST, 'entry'));
            if ($action === 'roster-add' && isset($_SESSION['import']['people'][input($_POST, 'device_person')])) {
                $entry = input($_POST, 'device_person');
            }
            if ($entry === '' || strlen($entry) > 160 || !preg_match('/^[^\x00-\x1F\x7F]+$/uD', $entry)) {
                throw new RuntimeException('Choose a person or enter a valid roster name.');
            }
            updateRoster($group, $entry, $action === 'roster-remove');
            $_SESSION['notice'] = $action === 'roster-remove' ? 'Roster entry removed.' : 'Roster entry saved.';
        }
    } catch (RuntimeException $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    redirectTo($destination);
}

$import = $_SESSION['import'] ?? null;
$notice = $_SESSION['notice'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['notice'], $_SESSION['error']);
session_write_close();

try {
    $rosters = readRosters();
} catch (RuntimeException $e) {
    $rosters = require __DIR__ . '/rosters.php';
    $error = $e->getMessage();
}

$people = $import['people'] ?? [];
$selectablePeople = array_filter($people, static function (array $person): bool {
    $name = trim($person['name']);
    return $name !== '' && strcasecmp($name, 'Unnamed person') !== 0;
});
[$groups, $unmatched] = rosterGroups($people, $rosters);
$view = input($_GET, 'view') === 'roster' ? 'roster' : 'report';
$group = input($_GET, 'group', 'all');
$group = isset(GROUPS[$group]) ? $group : 'all';
$visible = $group === 'all' ? $people : array_intersect_key($people, $groups[$group]);
$key = input($_GET, 'person');
$person = $visible[$key] ?? null;
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$yearStart = $today->format('Y-01-01');
$yearEnd = $today->format('Y-12-31');
$defaultFrom = max($yearStart, $import['first'] ?? $yearStart);
$defaultTo = min($yearEnd, $import['last'] ?? $yearEnd);
if ($defaultFrom > $defaultTo) {
    $defaultFrom = $yearStart;
    $defaultTo = $today->format('Y-m-d');
}
$from = input($_GET, 'from', $defaultFrom);
$to = input($_GET, 'to', $defaultTo);
$half = input($_GET, 'half');
if (in_array($half, ['1', '2'], true) && validDate($from)) {
    $month = new DateTimeImmutable($from);
    $from = $month->format('Y-m-') . ($half === '1' ? '01' : '16');
    $to = $month->format('Y-m-') . ($half === '1' ? '15' : $month->format('t'));
}
$submitted = isset($_GET['report']) || in_array($half, ['1', '2'], true);
$isCollector = $group === 'collectors'
    || ($group === 'all' && isset($groups['collectors'][$key]) && !isset($groups['employees'][$key]));
$validRange = validDate($from) && validDate($to) && $from <= $to;
$currentImport = $import && input($_GET, 'import') === $import['id'];
$reportReady = $submitted && $person && $validRange && $currentImport;
$rows = $reportReady ? reportRows($person['days'], $from, $to) : [];
$editDate = input($_GET, 'edit');
$reportQuery = ['group' => $group, 'person' => $key, 'from' => $from, 'to' => $to,
    'import' => $import['id'] ?? '', 'report' => '1'];
$storageError = false;
$lateHours = $halfDaysAbsent = $workdaysWithPunches = 0;
foreach ($rows as &$row) {
    $row['corrected'] = false;
    try {
        $saved = readCorrection(correctionPath($key, $row['date'], $row['times']), $row['times']);
        if ($saved !== null) {
            $row['cells'] = $saved;
            $row['review'] = false;
            $row['corrected'] = true;
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $storageError = true;
    }
    if ((new DateTimeImmutable($row['date']))->format('N') === '7') {
        $row['adjustmentLabel'] = 'Sunday off';
        continue;
    }
    $workdaysWithPunches++;
    $adjustment = attendanceAdjustment($row['cells']);
    $row['adjustmentLabel'] = $adjustment['label'];
    $lateHours += $adjustment['hours'];
    $halfDaysAbsent += $adjustment['halfDays'];
}
unset($row);
$selectedWorkdays = 0;
if ($reportReady) {
    $startDate = new DateTimeImmutable($from);
    $selectedDays = $startDate->diff(new DateTimeImmutable($to))->days + 1;
    $sundays = intdiv($selectedDays + (int) $startDate->format('N') - 1, 7);
    $selectedWorkdays = $selectedDays - $sundays;
}
$daysWithoutPunches = $selectedWorkdays - $workdaysWithPunches;
$absentDays = $daysWithoutPunches + $halfDaysAbsent / 2;
$lateTotal = $lateHours . ($lateHours === 1 ? ' hour' : ' hours');
$absentTotal = $absentDays . ($absentDays == 1 ? ' day' : ' days');
$absenceBreakdown = $daysWithoutPunches . ' without punches + ' . $halfDaysAbsent
    . ($halfDaysAbsent === 1 ? ' half-day' : ' half-days');
$reviewCount = count(array_filter($rows, static fn(array $row): bool => $row['review']));
$canPrint = $reportReady && $view === 'report' && !$storageError;

if (input($_GET, 'download') === 'csv') {
    if (!$rows || $storageError) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Run an attendance report with records before downloading.');
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="attendance-' . $person['device'] . '-' . $person['id'] . '-' . $from . '-to-' . $to . '.csv"');
    $csv = fopen('php://output', 'wb');
    fwrite($csv, "\xEF\xBB\xBF");
    fputcsv($csv, ['Person', 'Device', 'Employee ID', ...REPORT_COLUMNS, 'Raw punches'], ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($csv, [
            csvCell($person['name']), $person['device'], $person['id'], $row['date'],
            ...array_map('clockTime', $row['cells']), $row['corrected'] ? 'Reviewed' : ($row['review'] ? 'Needs review' : 'Complete'),
            implode(' | ', array_map(static fn(string $time): string => clockTime($time, true), $row['times'])),
        ], ',', '"', '');
    }
    exit;
}

$csvLink = '?' . http_build_query($reportQuery + ['download' => 'csv']);

$reportYears = [];
if ($view === 'report') {
    foreach ($people as $item) {
        foreach ($item['days'] as $date => $_times) {
            $reportYears[substr($date, 0, 4)] = true;
        }
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Big 8 Attendance</title>
    <link rel="stylesheet" href="assets/app.css?v=3">
    <script src="assets/app.js?v=2" defer></script>
</head>
<body<?= $canPrint ? ' class="has-print-report"' : '' ?>>
<header class="topbar">
    <div class="wrap topbar-inner">
        <a class="brand" href="index.php"><img src="assets/big8.png" alt=""><span>Big 8 Attendance</span></a>
        <nav aria-label="Main navigation">
            <a href="index.php" <?= $view === 'report' ? 'aria-current="page"' : '' ?>>Attendance</a>
            <a href="?view=roster" <?= $view === 'roster' ? 'aria-current="page"' : '' ?>>Rosters</a>
        </nav>
    </div>
</header>
<main class="wrap">
    <?php if ($notice !== ''): ?><p class="message success" role="status"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="message error" role="alert"><?= h($error) ?></p><?php endif; ?>

    <?php if ($view === 'roster'): ?>
        <div class="intro"><h1>Rosters</h1><p>Manage who appears in the Employees and Collectors filters.</p></div>
        <div class="roster-grid">
        <?php foreach (['employees', 'collectors'] as $roster): ?>
            <section class="card">
                <h2><?= h(GROUPS[$roster]) ?></h2>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="roster" value="<?= h($roster) ?>">
                    <?php if ($import): ?>
                        <label>Choose from current import
                            <select name="device_person">
                                <option value="">Choose a person</option>
                                <?php foreach ($selectablePeople as $personKey => $item): ?>
                                    <option value="<?= h($personKey) ?>"><?= h($item['name'] . ' · ID ' . $item['id'] . ' · Device ' . $item['device']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <label><?= $import ? 'Or enter a roster name' : 'Enter a roster name' ?>
                        <input name="entry" maxlength="160" placeholder="Name as shown on the device" <?= $import ? '' : 'required' ?>>
                    </label>
                    <button name="action" value="roster-add">Add to roster</button>
                </form>
                <ul class="roster-list">
                    <?php foreach ($rosters[$roster] as $entry): ?>
                        <li><div>
                            <?= h(isset($people[$entry]) ? $people[$entry]['name'] . ' · ID ' . $people[$entry]['id'] . ' · Device ' . $people[$entry]['device'] : $entry) ?>
                            <?php if ($import && in_array($entry, $unmatched[$roster], true)): ?>
                                <small>No unique match in this import</small>
                            <?php endif; ?>
                        </div>
                        <form method="post" class="remove-entry">
                            <input type="hidden" name="csrf" value="<?= h($token) ?>">
                            <input type="hidden" name="roster" value="<?= h($roster) ?>">
                            <input type="hidden" name="entry" value="<?= h($entry) ?>">
                            <button class="quiet" name="action" value="roster-remove" aria-label="<?= h('Remove ' . ($people[$entry]['name'] ?? $entry)) ?>">Remove</button>
                        </form></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="intro"><h1>Attendance reports</h1><p>Upload a biometric log, then check punches by person and date.</p></div>
        <section class="card upload-card" aria-labelledby="upload-title">
            <div>
                <h2 id="upload-title"><?= $import ? 'Current import' : 'Upload attendance log' ?></h2>
                <?php if ($import): ?>
                    <p class="filename"><?= h($import['filename']) ?></p>
                    <p class="meta"><?= h($import['first'] . ' to ' . $import['last']) ?> · <?= number_format($import['count']) ?> punches · <?= count($people) ?> people</p>
                    <?php if ($import['skipped'] || $import['duplicates']): ?>
                        <p class="meta"><?= number_format($import['skipped']) ?> invalid rows skipped · <?= number_format($import['duplicates']) ?> repeat punches removed</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="meta">Select the file exported by the biometric device.</p>
                <?php endif; ?>
            </div>
            <div>
                <form method="post" enctype="multipart/form-data" class="upload-form">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <label class="file-label"><span class="sr-only">Biometric log file</span>
                        <input type="file" name="log" accept=".txt,.agl" required aria-describedby="file-hint">
                    </label>
                    <button name="action" value="import"><?= $import ? 'Replace file' : 'Import file' ?></button>
                </form>
                <p class="meta" id="file-hint">TXT or AGL · up to 8 MB</p>
                <?php if ($import): ?>
                    <form method="post" id="clear-import">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <button class="quiet" name="action" value="clear">Clear import</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($import): ?>
            <section class="card filters" aria-labelledby="filter-title">
                <h2 id="filter-title">Report filters</h2>
                <form method="get" id="report-filters">
                    <input type="hidden" name="import" value="<?= h($import['id']) ?>">
                    <div class="filter-grid">
                        <label>Group
                            <select name="group" id="group">
                                <?php foreach (GROUPS as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $group === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Person
                            <input id="person-search" type="search" aria-label="Search by name or employee ID" placeholder="Search by name or ID" autocomplete="off" hidden>
                            <select id="person" name="person" required>
                                <option value="">Choose a person</option>
                                <?php foreach ($selectablePeople as $personKey => $item): ?>
                                    <option value="<?= h($personKey) ?>" data-employee="<?= (int) isset($groups['employees'][$personKey]) ?>" data-collector="<?= (int) isset($groups['collectors'][$personKey]) ?>" <?= $key === $personKey && $person ? 'selected' : '' ?>><?= h($item['name'] . ' · ID ' . $item['id'] . ' · Device ' . $item['device']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php foreach (['from' => $from, 'to' => $to] as $field => $date): ?>
                            <div class="date-field">
                                <label for="<?= $field ?>-date"><?= ucfirst($field) ?></label>
                                <input id="<?= $field ?>-date" type="date" name="<?= $field ?>" value="<?= validDate($date) ? h($date) : '' ?>" required>
                                <button type="button" class="date-trigger" data-date-field="<?= $field ?>" aria-haspopup="dialog" hidden>
                                    <span>Choose a date</span><span class="calendar-icon" aria-hidden="true"></span>
                                </button>
                            </div>
                        <?php endforeach; ?>
                        <button name="report" value="1">View report</button>
                    </div>
                    <div class="pay-presets">
                        <button class="secondary" name="half" value="1">1st–15th</button>
                        <button class="secondary" name="half" value="2">16th–month end</button>
                        <span class="meta">Uses the month in From.</span>
                    </div>
                </form>
                <dialog id="date-calendar" aria-labelledby="calendar-title" data-today="<?= $today->format('Y-m-d') ?>" data-years="<?= h(implode(',', array_keys($reportYears))) ?>">
                    <div class="calendar-heading">
                        <h2 id="calendar-title"></h2>
                        <button type="button" class="secondary" data-calendar-close aria-label="Close calendar">×</button>
                    </div>
                    <div class="calendar-navigation">
                        <button type="button" class="secondary" data-calendar-step="-1" aria-label="Previous month">‹</button>
                        <select id="calendar-month" aria-label="Month"></select>
                        <select id="calendar-year" aria-label="Year"></select>
                        <button type="button" class="secondary" data-calendar-step="1" aria-label="Next month">›</button>
                    </div>
                    <div class="calendar-week" aria-hidden="true">
                        <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                    </div>
                    <div class="calendar-days"></div>
                    <div class="calendar-footer">
                        <button type="button" class="secondary" data-calendar-today>Today</button>
                    </div>
                </dialog>
                <?php if ($group !== 'all' && $unmatched[$group]): ?>
                    <p class="meta"><?= count($unmatched[$group]) ?> roster entries have no unique match in this import. <a href="?view=roster">Review roster</a></p>
                <?php endif; ?>
            </section>
            <?php if ($submitted): ?>
                <section class="card report" aria-labelledby="report-title">
                    <?php if (!$currentImport || !$person || !$validRange): ?>
                        <h2 id="report-title">Attendance report</h2>
                        <p role="alert"><?= !$currentImport ? 'The active import changed. Run the report again.' : (!$person ? 'Choose a person in the selected group.' : 'Enter a valid date range with From on or before To.') ?></p>
                    <?php else: ?>
                        <div class="report-head">
                            <div>
                                <h2 id="report-title"><?= h($person['name']) ?></h2>
                                <p class="meta"><?= h('Employee ID ' . $person['id'] . ' · Device ' . $person['device'] . ' · ' . $from . ' to ' . $to) ?></p>
                            </div>
                            <?php if ($canPrint): ?>
                                <div class="actions"><button type="button" class="secondary" id="print-report">Print</button><?php if ($rows): ?><a class="button" href="<?= h($csvLink) ?>">Download CSV</a><?php endif; ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if (!$storageError): ?>
                            <p class="meta">Late: <?= h($lateTotal) ?> · Total absent: <?= h($absentTotal) ?> (<?= h($absenceBreakdown) ?>)</p>
                        <?php endif; ?>
                        <?php if (!$rows): ?>
                            <p class="empty">No punches for this person in the selected date range.</p>
                        <?php else: ?>
                            <p class="meta report-summary"><?= count($rows) ?> days with punches · <?= $reviewCount ?> need review</p>
                            <div class="table-wrap" tabindex="0" role="region" aria-label="Attendance table">
                                <table>
                                    <thead><tr><?php foreach (REPORT_COLUMNS as $column): ?><th scope="col"><?= h($column) ?></th><?php endforeach; ?></tr></thead>
                                    <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <tr id="punch-<?= h($row['date']) ?>">
                                            <th scope="row"><?= h($row['date']) ?></th>
                                            <?php foreach ($row['cells'] as $cell): ?><td><?= h(clockTime($cell) ?: '—') ?></td><?php endforeach; ?>
                                            <td>
                                                <details>
                                                    <summary class="<?= $row['review'] ? 'review' : 'complete' ?>"><?= $row['corrected'] ? 'Reviewed' : ($row['review'] ? 'Needs review' : 'Complete') ?></summary>
                                                    <div class="raw-punches"><?= h(implode(' · ', array_map(static fn(string $time): string => clockTime($time, true), $row['times']))) ?></div>
                                                </details>
                                                <?php if ($editDate === $row['date']): ?>
                                                    <form method="post" class="punch-editor">
                                                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                                                        <?php foreach ($reportQuery + ['edit' => $editDate] as $field => $value): ?>
                                                            <input type="hidden" name="<?= h($field) ?>" value="<?= h($value) ?>">
                                                        <?php endforeach; ?>
                                                        <?php foreach (array_slice(REPORT_COLUMNS, 1, 4) as $slot => $label): ?>
                                                            <label><?= h($label) ?>
                                                                <select name="cells[]">
                                                                    <option value="">Blank</option>
                                                                    <?php foreach ($row['times'] as $time): ?>
                                                                        <option value="<?= h($time) ?>" <?= $row['cells'][$slot] === $time ? 'selected' : '' ?>><?= h(clockTime($time, true)) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </label>
                                                        <?php endforeach; ?>
                                                        <div class="actions">
                                                            <button name="action" value="save-punches">Save punches</button>
                                                            <?php if ($row['corrected']): ?><button class="secondary" name="action" value="reset-punches">Use suggested times</button><?php endif; ?>
                                                            <a href="?<?= h(http_build_query($reportQuery)) ?>">Cancel</a>
                                                        </div>
                                                    </form>
                                                <?php else: ?>
                                                    <a class="edit-punches" href="?<?= h(http_build_query($reportQuery + ['edit' => $row['date']])) ?>#punch-<?= h($row['date']) ?>">Edit punches</a>
                                                <?php endif; ?>

                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="meta report-note">With two punches, a morning arrival followed by a checkout from noon to 12:59 PM is shown as a morning-only half-day. Use Edit punches to correct the suggested assignments. Dates from Monday through Saturday without punches count as absent. Sundays are days off.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php else: ?>
            <section class="card empty-card">
                <h2>Attendance will appear here</h2>
                <p>After importing a log, select a person and date range to see daily In and Out times.</p>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php if ($canPrint): ?>
    <section class="company-print" aria-label="Company attendance report">
        <header class="company-print-header">
            <img src="assets/big8.png" alt="Big 8">
            <div>
                <h2>Attendance Report</h2>
                <p>Name: <?= h($person['name']) ?></p>
                <p>Date Range: <?= h($from . ' to ' . $to) ?></p>
                <p class="company-description">Description: <?= $isCollector ? 'Collectors' : 'Staff' ?></p>
            </div>
        </header>
        <table>
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Morning In</th>
                    <th scope="col">Morning Out</th>
                    <th scope="col">Afternoon In</th>
                    <th scope="col">Afternoon Out</th>
                    <th scope="col">Late / Absent</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6">No punches in the selected date range.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= h($row['date']) ?></td>
                        <?php foreach ($row['cells'] as $cell): ?>
                            <td><?= h($cell === '' ? '-' : str_pad(clockTime($cell), 8, '0', STR_PAD_LEFT)) ?></td>
                        <?php endforeach; ?>
                        <td><?= h($row['adjustmentLabel'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <footer class="company-print-footer">
            <div>
                <p>Late: <?= h($lateTotal) ?></p>
                <p>Total absent: <?= h($absentTotal) ?> (<?= h($absenceBreakdown) ?>)</p>
                <p>No. of Present: __________</p>
                <div class="company-manager">
                    <p>Erwena Popelo</p>
                    <p>Manager In Charge</p>
                </div>
            </div>
            <div class="company-signature">
                <p>Signature of <?= $isCollector ? 'Collector' : 'Staff' ?>:</p>
                <p class="company-signature-line">Signature over printed name</p>
            </div>
        </footer>
    </section>
<?php endif; ?>
</body>
</html> 
