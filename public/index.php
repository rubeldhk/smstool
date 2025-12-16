<?php
require_once __DIR__ . '/../bootstrap.php';
require_once BASE_PATH . '/src/Auth.php';
require_once BASE_PATH . '/src/Storage.php';
require_once BASE_PATH . '/src/SwiftSmsClient.php';

$storage = new Storage();
$message = null;
$error = null;
$previewMessages = [];
$allowedCountries = ['CA', 'AU', 'NZ'];

function calculate_counts(array $recipients, int $invalid): array
{
    $counts = [
        'total' => count($recipients),
        'valid' => count($recipients),
        'invalid' => $invalid,
        'sent' => 0,
        'failed' => 0,
        'pending' => 0,
    ];

    foreach ($recipients as $recipient) {
        if ($recipient['status'] === 'sent') {
            $counts['sent']++;
        } elseif ($recipient['status'] === 'failed') {
            $counts['failed']++;
        } else {
            $counts['pending']++;
        }
    }

    return $counts;
}

function update_counts(Storage $storage, int $campaignId, int $invalid): array
{
    $recipients = $storage->recipients($campaignId);
    $counts = calculate_counts($recipients, $invalid);
    $storage->setCampaign($campaignId, ['counts' => $counts]);
    return $counts;
}

if (($_GET['action'] ?? '') === 'logout') {
    Auth::logout();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    verify_csrf($_POST['csrf'] ?? '');
    if (Auth::login($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid credentials';
}

if (!Auth::check()) {
    $csrf = csrf_token();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>SwiftSMS Bulk Sender - Login</title>
        <style>
            body {font-family: Arial, sans-serif; background: #f2f2f2; display: flex; align-items: center; justify-content: center; height: 100vh;}
            .card {background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.15); width: 360px;}
            .field {margin-bottom: 12px;}
            label {display: block; font-weight: bold; margin-bottom: 4px;}
            input[type="text"], input[type="password"] {width: 100%; padding: 8px; box-sizing: border-box;}
            button {padding: 10px 16px; background: #006699; color: #fff; border: none; border-radius: 4px; cursor: pointer; width: 100%;}
            .error {color: #b30000; margin-bottom: 8px;}
        </style>
    </head>
    <body>
    <div class="card">
        <h2>SwiftSMS Bulk Sender</h2>
        <?php if ($error): ?><div class="error"><?php echo escape($error); ?></div><?php endif; ?>
        <form method="post" action="">
            <input type="hidden" name="csrf" value="<?php echo escape($csrf); ?>">
            <input type="hidden" name="action" value="login">
            <div class="field">
                <label for="username">Username</label>
                <input type="text" name="username" id="username" required>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" required>
            </div>
            <button type="submit">Login</button>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_campaign') {
        verify_csrf($_POST['csrf'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $messageTemplate = trim($_POST['message_template'] ?? '');
        $senderId = trim($_POST['sender_id'] ?? '') ?: null;
        $country = strtoupper(trim($_POST['country'] ?? 'CA'));

        if (!in_array($country, $allowedCountries, true)) {
            $error = 'Invalid country selection.';
        } elseif ($name === '' || $messageTemplate === '') {
            $error = 'Campaign name and message template are required.';
        } elseif (strlen($messageTemplate) > 480) {
            $error = 'Message template must be 480 characters or less.';
        } elseif (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            $error = 'CSV upload failed. Please try again.';
        } else {
            $mime = mime_content_type($_FILES['csv']['tmp_name']);
            $allowed = ['text/plain', 'text/csv', 'application/vnd.ms-excel'];
            if (!in_array($mime, $allowed, true)) {
                $error = 'Only CSV files are allowed.';
            }
        }

        if (!$error && $_FILES['csv']['size'] > 2 * 1024 * 1024) {
            $error = 'CSV must be under 2MB.';
        }

        if (!$error) {
            $handle = fopen($_FILES['csv']['tmp_name'], 'r');
            $recipients = [];
            $seen = [];
            $invalid = 0;
            $previewMessages = [];
            $header = fgetcsv($handle);

            $normalizeHeader = static function ($value): string {
                $value = strtolower(trim((string) $value));
                $value = preg_replace('/[^a-z0-9]+/', '_', $value ?? '') ?? '';
                return trim($value, '_');
            };

            if ($header === false) {
                $error = 'CSV file is empty.';
            } else {
                $columnMap = [];
                $aliases = [
                    'customer' => 'customer_name',
                    'customername' => 'customer_name',
                    'receivername' => 'receiver_name',
                ];

                foreach ($header as $index => $column) {
                    $normalized = $normalizeHeader($column);
                    $normalized = $aliases[$normalized] ?? $normalized;
                    $columnMap[$normalized] = $index;
                }

                $requiredColumns = ['phone', 'customer_name', 'receiver_name'];
                $missing = array_filter($requiredColumns, static fn($col) => !array_key_exists($col, $columnMap));
                if (!empty($missing)) {
                    $error = 'CSV is missing required columns: ' . implode(', ', $missing);
                } else {
                    while (($row = fgetcsv($handle)) !== false) {
                        $rawPhone = trim($row[$columnMap['phone']] ?? '');
                        $customerName = trim($row[$columnMap['customer_name']] ?? '');
                        $receiverName = trim($row[$columnMap['receiver_name']] ?? '');

                        if ($rawPhone === '') {
                            continue;
                        }

                        $phone = normalize_phone_for_country($rawPhone, $country);

                        if (!validate_normalized_phone($phone, $country)) {
                            $invalid++;
                            continue;
                        }

                        if (isset($seen[$phone])) {
                            continue;
                        }
                        $seen[$phone] = true;

                        $rendered = render_message_template($messageTemplate, [
                            'customer_name' => $customerName,
                            'receiver_name' => $receiverName,
                            'phone' => $phone,
                        ]);

                        $recipients[] = [
                            'phone' => $phone,
                            'customer_name' => $customerName,
                            'receiver_name' => $receiverName,
                            'rendered_message' => $rendered,
                        ];

                        if (count($previewMessages) < 5) {
                            $previewMessages[] = $rendered;
                        }
                    }
                }
            }
            fclose($handle);

            if (!$error && empty($recipients)) {
                $error = 'No valid phone numbers found.';
            } else {
                $campaign = $storage->createCampaign($name, $messageTemplate, $senderId, $country, $recipients, $invalid, $previewMessages);
                $previewMessages = $campaign['preview_messages'] ?? $previewMessages;
                $message = 'Campaign created. Valid numbers: ' . count($recipients) . '. Invalid: ' . $invalid . '. Set status to start when ready.';
            }
        }
        if ($error) {
            $previewMessages = [];
        }
    } elseif (in_array($action, ['start', 'stop', 'resume'], true)) {
        verify_csrf($_POST['csrf'] ?? '');
        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        $campaigns = $storage->campaigns();
        $exists = false;
        foreach ($campaigns as $campaign) {
            if ((int) $campaign['id'] === $campaignId) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $error = 'Campaign not found.';
        } else {
            if ($action === 'start' || $action === 'resume') {
                $storage->updateCampaignStatus($campaignId, 'queued');
                $message = 'Campaign queued for sending.';
            } elseif ($action === 'stop') {
                $storage->updateCampaignStatus($campaignId, 'stopped');
                $message = 'Campaign stopped.';
            }
        }
    }
}

$campaigns = $storage->campaigns();
$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SwiftSMS Bulk Sender</title>
    <style>
        body {font-family: Arial, sans-serif; margin: 0; background: #f4f6f9;}
        header {background: #0d4d73; color: #fff; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center;}
        main {padding: 16px;}
        h1 {margin: 0; font-size: 20px;}
        .grid {display: grid; grid-template-columns: 1fr 1fr; gap: 16px;}
        .card {background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.08);} 
        .field {margin-bottom: 12px;}
        label {display: block; font-weight: bold; margin-bottom: 4px;}
        input[type="text"], textarea, select {width: 100%; padding: 8px; box-sizing: border-box;}
        textarea {min-height: 120px;}
        button {padding: 8px 12px; background: #0d4d73; color: #fff; border: none; border-radius: 4px; cursor: pointer;}
        table {width: 100%; border-collapse: collapse; margin-top: 12px;}
        th, td {border: 1px solid #ddd; padding: 8px; text-align: left;}
        th {background: #f0f0f0;}
        .status {padding: 4px 8px; border-radius: 4px; display: inline-block; font-size: 12px; text-transform: capitalize;}
        .status.draft {background: #eef2f7;}
        .status.queued {background: #fff4e0;}
        .status.running {background: #e0f7f3;}
        .status.completed {background: #e3f3e7;}
        .status.failed, .status.stopped {background: #fdecea;}
        .alert {margin-bottom: 12px; padding: 10px; border-radius: 6px;}
        .alert.success {background: #e6f5ea; color: #256029;}
        .alert.error {background: #fdecea; color: #611a15;}
        .actions form {display: inline-block; margin-right: 6px;}
        .logout {color: #fff; text-decoration: none;}
        .campaigns-table {max-height: 380px; overflow-y: auto; margin-top: 8px;}
    </style>
</head>
<body>
<header>
    <h1>SwiftSMS Bulk Sender</h1>
    <a class="logout" href="?action=logout">Logout</a>
</header>
<main>
    <?php if ($message): ?><div class="alert success"><?php echo escape($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?php echo escape($error); ?></div><?php endif; ?>
    <div class="grid">
        <div class="card">
            <h2>Create Campaign</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?php echo escape($csrf); ?>">
                <input type="hidden" name="action" value="create_campaign">
                <div class="field">
                    <label for="name">Campaign Name</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="field">
                    <label for="country">Recipient Country</label>
                    <select id="country" name="country" required>
                        <?php foreach ($allowedCountries as $option): ?>
                            <option value="<?php echo escape($option); ?>"><?php echo escape($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="message_template">Message Template (supports {{customer_name}}, {{receiver_name}}, {{phone}})</label>
                    <textarea id="message_template" name="message_template" maxlength="480" required></textarea>
                </div>
                <div class="field">
                    <label for="sender_id">Sender ID (optional)</label>
                    <input type="text" id="sender_id" name="sender_id" placeholder="Default from environment will be used if blank">
                </div>
                <div class="field">
                    <label for="csv">Upload CSV (Phone, Customer Name, Receiver Name)</label>
                    <input type="file" id="csv" name="csv" accept=".csv" required>
                </div>
                <p>Columns are matched case-insensitively (phone / customer_name / receiver_name). Numbers are normalized per selected country and must not include "+".</p>
                <button type="submit">Create Campaign</button>
            </form>
            <?php if (!empty($previewMessages)): ?>
                <h3>Preview (first 5)</h3>
                <ul>
                    <?php foreach ($previewMessages as $preview): ?>
                        <li><?php echo escape($preview); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="card">
            <h2>Campaigns</h2>
            <?php if (empty($campaigns)): ?>
                <p>No campaigns yet.</p>
            <?php else: ?>
                <div class="campaigns-table">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Country</th>
                                <th>Status</th>
                                <th>Counts</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($campaigns as $campaign):
                            $counts = $campaign['counts'] ?? update_counts($storage, (int) $campaign['id'], $campaign['counts']['invalid'] ?? 0);
                            ?>
                            <tr>
                                <td><?php echo (int) $campaign['id']; ?></td>
                                <td><?php echo escape($campaign['name']); ?></td>
                                <td><?php echo escape(strtoupper($campaign['country'] ?? 'CA')); ?></td>
                                <td><span class="status <?php echo escape($campaign['status']); ?>"><?php echo escape($campaign['status']); ?></span></td>
                                <td>
                                    Total: <?php echo $counts['total']; ?><br>
                                    Sent: <?php echo $counts['sent']; ?> | Failed: <?php echo $counts['failed']; ?> | Pending: <?php echo $counts['pending']; ?><br>
                                    Invalid: <?php echo $counts['invalid']; ?>
                                </td>
                                <td class="actions">
                                    <form method="post" style="margin-bottom:4px;">
                                        <input type="hidden" name="csrf" value="<?php echo escape($csrf); ?>">
                                        <input type="hidden" name="campaign_id" value="<?php echo (int) $campaign['id']; ?>">
                                        <input type="hidden" name="action" value="start">
                                        <button type="submit">Start</button>
                                    </form>
                                    <form method="post" style="margin-bottom:4px;">
                                        <input type="hidden" name="csrf" value="<?php echo escape($csrf); ?>">
                                        <input type="hidden" name="campaign_id" value="<?php echo (int) $campaign['id']; ?>">
                                        <input type="hidden" name="action" value="resume">
                                        <button type="submit">Resume</button>
                                    </form>
                                    <form method="post" style="margin-bottom:4px;">
                                        <input type="hidden" name="csrf" value="<?php echo escape($csrf); ?>">
                                        <input type="hidden" name="campaign_id" value="<?php echo (int) $campaign['id']; ?>">
                                        <input type="hidden" name="action" value="stop">
                                        <button type="submit">Stop</button>
                                    </form>
                                    <form method="get" action="report.php">
                                        <input type="hidden" name="campaign_id" value="<?php echo (int) $campaign['id']; ?>">
                                        <input type="hidden" name="csrf" value="<?php echo escape($csrf); ?>">
                                        <button type="submit">Download CSV</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
