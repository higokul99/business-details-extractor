<?php
session_start();

// Access Configuration
define('ACCESS_CODE', '854747'); // Your 6-digit code

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'business_leads');
define('DB_USER', 'root');
define('DB_PASS', '');

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Handle Login
$authError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_code'])) {
    if ($_POST['login_code'] === ACCESS_CODE) {
        $_SESSION['authenticated'] = true;
    } else {
        $authError = 'Invalid access code. Please try again.';
    }
}

// Check Authentication
$isAuthenticated = $_SESSION['authenticated'] ?? false;

// Handle AJAX Toggles
if ($isAuthenticated && isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    try {
        $pdo_toggle = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        if ($action === 'toggle_closed') {
            $pdo_toggle->prepare("UPDATE businesses SET is_closed = 1 - is_closed WHERE id = ?")->execute([$id]);
        } elseif ($action === 'toggle_review') {
            $pdo_toggle->prepare("UPDATE businesses SET is_review_later = 1 - is_review_later WHERE id = ?")->execute([$id]);
        }
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Configuration & Data Fields
$allDataFields = [
  'address'      => 'Full address',
  'phone'        => 'All phone numbers',
  'website'      => 'Website URL',
  'google_maps'  => 'Google Maps location link',
  'facebook'     => 'Facebook page',
  'instagram'    => 'Instagram profile',
  'rating'       => 'Rating & review count',
  'hours'        => 'Business hours',
  'email'        => 'Email address',
  'whatsapp'     => 'WhatsApp number',
  'digital_gaps' => 'Digital presence gaps & suggestions',
];

$allSources = [
  'google_search' => 'Google Search',
  'google_maps'   => 'Google Maps',
  'justdial'      => 'Just Dial',
  'sulekha'       => 'Sulekha / IndiaMart',
  'yellowpages'   => 'Yellow Pages India',
  'tradeindia'    => 'Tradeindia / ExportersIndia',
];

$businessPresets = ['Salons', 'Restaurants', 'Gyms', 'Hospitals', 'Hotels', 'Pharmacies', 'Schools', 'Law Firms', 'Clinics', 'Bakeries', 'Auto Repair Shops', 'Dentists'];

// Handle Database Import
$dbMessage = '';
if ($isAuthenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_sql'])) {
    $sql = $_POST['sql_input'] ?? '';

    if ($sql) {
        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec($sql);
            $dbMessage = ["success", "Data successfully imported to database."];
        } catch (PDOException $e) {
            $dbMessage = ["error", "Database Error: " . $e->getMessage()];
        }
    }
}

// Handle Prompt Generation
$generated = false;
$prompt = '';
$businessType = '';
$district = '';
$state = '';
$country = 'India';

if ($isAuthenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['business_type'])) {
    $businessType = trim($_POST['business_type'] ?? '');
    $district     = trim($_POST['district'] ?? '');
    $state        = trim($_POST['state'] ?? '');
    $country      = trim($_POST['country'] ?? 'India');
    $maxCount     = $_POST['max_count'] ?? 'all';
    $dataFields   = $_POST['data_fields'] ?? [];
    $sources      = $_POST['sources'] ?? [];

    if ($businessType && $district) {
        $generated = true;
        $locationStr = implode(', ', array_filter([$district, $state, $country]));
        
        // Generate Batch ID (Type + IST Timestamp)
        date_default_timezone_set('Asia/Kolkata');
        $batchId = strtoupper(str_replace(' ', '_', $businessType)) . '_' . date('Ymd_His');

        // Dynamic SQL Generation for the Prompt
        $sqlColumns = [
            "`name` VARCHAR(255)", 
            "`address` TEXT", 
            "`state` VARCHAR(100)",
            "`business_type` VARCHAR(100)", 
            "`batch_id` VARCHAR(100)"
        ];
        $insertColumns = ["`name`", "`address`", "`state`", "`business_type`", "`batch_id`"];
        
        $headerMap = [
            'google_maps'  => ['Google Maps Link', '`google_maps` TEXT'],
            'digital_gaps' => ['Digital Gaps & Recommendations', '`digital_gaps` TEXT'],
            'phone'        => ['Phone(s)', '`phone` VARCHAR(255)'],
            'website'      => ['Website', '`website` VARCHAR(255)'],
            'facebook'     => ['Facebook', '`facebook` VARCHAR(255)'],
            'instagram'    => ['Instagram', '`instagram` VARCHAR(255)'],
            'address'      => ['Address', '`address` TEXT'],
            'rating'       => ['Rating', '`rating` VARCHAR(50)'],
            'hours'        => ['Hours', '`hours` TEXT'],
            'email'        => ['Email', '`email` VARCHAR(255)'],
            'whatsapp'     => ['WhatsApp', '`whatsapp` VARCHAR(255)'],
        ];

        $dataList = '';
        foreach ($dataFields as $key) {
            if (isset($allDataFields[$key])) {
                $dataList .= "  - {$allDataFields[$key]}\n";
                if ($key !== 'address') {
                    $colName = $headerMap[$key][1] ?? "`$key` TEXT";
                    $sqlColumns[] = $colName;
                    preg_match('/(`[\w_]+`)/', $colName, $m);
                    $insertColumns[] = $m[1] ?? "`$key`";
                }
            }
        }
        
        $createTableSql = "CREATE TABLE IF NOT EXISTS `businesses` (\n  `id` INT AUTO_INCREMENT PRIMARY KEY,\n  " . implode(",\n  ", $sqlColumns) . ",\n  `is_closed` TINYINT(1) DEFAULT 0,\n  `is_review_later` TINYINT(1) DEFAULT 0,\n  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n);";
        $insertIntoSql = "INSERT INTO `businesses` (" . implode(", ", $insertColumns) . ") VALUES";

        $sourceList = '';
        foreach ($sources as $key) {
            if (isset($allSources[$key])) $sourceList .= "  - {$allSources[$key]}\n";
        }

        $gapRules = in_array('digital_gaps', $dataFields) ? "\n## Digital Gap Analysis\n- Identify missing assets (website, social, etc.)\n- Provide 1-2 suggestions." : "";

        $prompt = "You are a research agent. Find {$businessType} in {$locationStr}.\n\n" .
                  "## Objective\nCollect:\n{$dataList}\n" .
                  "## Sources\n{$sourceList}\n" .
                  "## Output Format (SQL)\n\n{$createTableSql}\n\n{$insertIntoSql}\n" .
                  "('Name', 'Address', '{$businessType}', '{$batchId}', ...);\n\n" .
                  "## Rules\n- Use '{$businessType}' and '{$batchId}' for metadata columns.\n" .
                  "- Escape single quotes.\n- Use NULL for missing data.\n{$gapRules}";

        // Save Prompt to History
        try {
            $pdo_save = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
            $pdo_save->exec("CREATE TABLE IF NOT EXISTS `generated_prompts` (`id` INT AUTO_INCREMENT PRIMARY KEY, `business_type` VARCHAR(255), `location` VARCHAR(255), `prompt_text` LONGTEXT, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
            $stmt = $pdo_save->prepare("INSERT INTO `generated_prompts` (`business_type`, `location`, `prompt_text`) VALUES (?, ?, ?)");
            $stmt->execute([$businessType, $locationStr, $prompt]);
        } catch (PDOException $e) {}
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ResearchAgent v1.3</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --bg: #0a0e1a; --surface: #111827; --surface2: #1a2235; --border: #1e2d45; --accent: #f59e0b; --text: #e2e8f0; --muted: #64748b; --success: #10b981; --radius: 10px; }
  body { background: var(--bg); color: var(--text); font-family: 'Syne', sans-serif; min-height: 100vh; margin: 0; }
  nav { display: flex; align-items: center; justify-content: space-between; padding: 1rem 2rem; border-bottom: 1px solid var(--border); background: rgba(10,14,26,0.9); backdrop-filter: blur(10px); sticky: top; z-index: 100; }
  .nav-logo { font-weight: 800; color: var(--accent); }
  .nav-tag { font-family: 'JetBrains Mono', monospace; font-size: 0.7rem; padding: 0.3rem 0.6rem; border: 1px solid var(--border); border-radius: 4px; color: var(--muted); cursor: pointer; }
  .nav-tag.active { border-color: var(--accent); color: var(--accent); }
  .container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; }
  .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; margin-bottom: 1.5rem; }
  .card-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem; }
  .card-num { background: var(--accent); color: #000; width: 24px; height: 24px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 800; }
  .card-title { font-weight: 700; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; }
  input, select, textarea { width: 100%; background: var(--surface2); border: 1px solid var(--border); border-radius: 6px; color: var(--text); padding: 0.7rem; font-family: 'JetBrains Mono', monospace; box-sizing: border-box; }
  label { display: block; font-size: 0.7rem; color: var(--muted); margin-bottom: 0.4rem; text-transform: uppercase; }
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
  .preset-btn { background: var(--surface2); border: 1px solid var(--border); color: var(--muted); padding: 0.4rem 0.8rem; border-radius: 6px; cursor: pointer; font-size: 0.75rem; transition: 0.2s; }
  .preset-btn:hover, .preset-btn.active { border-color: var(--accent); color: var(--accent); }
  .preset-btn.active { background: var(--accent); color: #000; }
  .check-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
  .check-item { display: flex; align-items: center; gap: 0.5rem; background: var(--surface2); padding: 0.6rem; border-radius: 6px; border: 1px solid var(--border); cursor: pointer; }
  .check-item.checked { border-color: var(--accent); background: rgba(245,158,11,0.05); }
  .check-box { width: 16px; height: 16px; border: 1px solid var(--border); border-radius: 3px; }
  .check-item.checked .check-box { background: var(--accent); border-color: var(--accent); }
  .btn-generate { width: 100%; background: var(--accent); color: #000; font-weight: 800; padding: 1rem; border: none; border-radius: 8px; cursor: pointer; text-transform: uppercase; margin-top: 1rem; }
  .result-card { background: var(--surface); border: 1px solid var(--success); border-radius: var(--radius); padding: 1.5rem; margin-top: 1rem; }
  .prompt-box { background: var(--surface2); padding: 1rem; border-radius: 6px; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; white-space: pre-wrap; color: #94a3b8; max-height: 300px; overflow-y: auto; }
  table { width: 100%; border-collapse: collapse; font-family: 'JetBrains Mono', monospace; font-size: 0.7rem; }
  th, td { text-align: left; padding: 0.8rem; border-bottom: 1px solid var(--border); }
  tr.closed { background: rgba(239, 68, 68, 0.05); }
  tr.review { background: rgba(245, 158, 11, 0.05); }
</style>
</head>
<body>

<nav>
  <div class="nav-logo">⬡ RESEARCHAGENT</div>
  <div style="display: flex; gap: 0.5rem;">
    <?php if ($isAuthenticated): ?>
      <a href="?page=generator" class="nav-tag <?= (!isset($_GET['page']) || $_GET['page']==='generator')?'active':'' ?>" style="text-decoration:none;">Generator</a>
      <a href="?page=viewer" class="nav-tag <?= (isset($_GET['page']) && $_GET['page']==='viewer')?'active':'' ?>" style="text-decoration:none;">View Data</a>
      <a href="?logout=1" class="nav-tag" style="color:#ef4444; text-decoration:none;">Logout</a>
    <?php endif; ?>
  </div>
</nav>

<?php if (!$isAuthenticated): ?>
  <div class="container" style="max-width: 400px; margin-top: 10vh;">
    <div class="card" style="text-align: center;">
      <div class="card-header" style="justify-content: center;"><div class="card-num">🔒</div><div class="card-title">Access Code</div></div>
      <form method="POST">
        <input type="password" name="login_code" placeholder="000000" maxlength="6" style="text-align:center; font-size: 1.5rem; letter-spacing: 0.5rem;">
        <?php if ($authError): ?><p style="color:#ef4444; font-size: 0.7rem;"><?= $authError ?></p><?php endif; ?>
        <button type="submit" class="btn-generate">Unlock</button>
      </form>
    </div>
  </div>
<?php else: ?>
  
  <?php if (isset($_GET['page']) && $_GET['page'] === 'viewer'): ?>
    <!-- VIEWER UI -->
    <?php
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $types = $pdo->query("SELECT DISTINCT business_type FROM businesses")->fetchAll(PDO::FETCH_COLUMN);
    $batches = $pdo->query("SELECT DISTINCT batch_id FROM businesses ORDER BY created_at DESC")->fetchAll(PDO::FETCH_COLUMN);
    
    $f_type = $_GET['f_type'] ?? '';
    $f_batch = $_GET['f_batch'] ?? '';
    $where = ["1=1"]; $params = [];
    if ($f_type) { $where[] = "business_type = ?"; $params[] = $f_type; }
    if ($f_batch) { $where[] = "batch_id = ?"; $params[] = $f_batch; }
    
    $stmt = $pdo->prepare("SELECT * FROM businesses WHERE ".implode(" AND ", $where)." ORDER BY created_at DESC");
    $stmt->execute($params);
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $selectedCols = $_GET['cols'] ?? ['address', 'state', 'phone', 'website', 'rating'];
    ?>
    <div class="container" style="max-width: 1200px;">
      <div class="card">
        <form method="GET" class="grid-3">
          <input type="hidden" name="page" value="viewer">
          <select name="f_type"><option value="">All Types</option><?php foreach ($types as $t): ?><option value="<?= $t ?>" <?= $f_type===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select>
          <select name="f_batch"><option value="">All Batches</option><?php foreach ($batches as $b): ?><option value="<?= $b ?>" <?= $f_batch===$b?'selected':'' ?>><?= $b ?></option><?php endforeach; ?></select>
          <button type="submit" class="preset-btn active">Filter</button>
          <div style="grid-column: span 3; display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem;">
            <label style="font-size: 0.6rem;"><input type="checkbox" name="cols[]" value="state" <?= in_array('state', $selectedCols)?'checked':'' ?>> State</label>
            <?php foreach ($allDataFields as $k => $l): ?>
              <label style="font-size: 0.6rem;"><input type="checkbox" name="cols[]" value="<?= $k ?>" <?= in_array($k, $selectedCols)?'checked':'' ?>> <?= $l ?></label>
            <?php endforeach; ?>
          </div>
        </form>
      </div>
      <div class="card" style="overflow-x: auto;">
        <table>
          <thead><tr><th>Actions</th><th>Name</th><?php foreach ($selectedCols as $c): ?><th><?= $c ?></th><?php endforeach; ?><th>Date</th></tr></thead>
          <tbody>
            <?php foreach ($leads as $l): ?>
              <tr class="<?= $l['is_closed']?'closed':($l['is_review_later']?'review':'') ?>">
                <td>
                  <button onclick="toggle(<?= $l['id'] ?>, 'toggle_closed', this)" class="nav-tag <?= $l['is_closed']?'active':'' ?>">🚫</button>
                  <button onclick="toggle(<?= $l['id'] ?>, 'toggle_review', this)" class="nav-tag <?= $l['is_review_later']?'active':'' ?>">⭐</button>
                </td>
                <td><?= htmlspecialchars($l['name']) ?></td>
                <?php foreach ($selectedCols as $c): ?><td><?= htmlspecialchars($l[$c]??'—') ?></td><?php endforeach; ?>
                <td><?= date('d/m H:i', strtotime($l['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <script>
    function toggle(id, action, btn) {
      fetch(`?action=${action}&id=${id}`).then(r => r.json()).then(d => { if(d.success) location.reload(); });
    }
    </script>

  <?php else: ?>
    <div class="container">
      <?php if ($dbMessage): ?>
        <div class="result-card" style="border-color: <?= $dbMessage[0]==='success'?'var(--success)':'#ef4444' ?>;"><?= $dbMessage[1] ?></div>
      <?php endif; ?>

      <?php if ($generated): ?>
        <div class="result-card">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <div style="color:var(--success); font-weight:800; font-size:0.7rem;">PROMPT READY</div>
            <button class="nav-tag active" onclick="copyPrompt()">Copy SQL Prompt</button>
          </div>
          <div class="prompt-box" id="promptText"><?= htmlspecialchars($prompt) ?></div>
        </div>
      <?php endif; ?>

      <form method="POST">
        <div class="card">
          <div class="card-header"><div class="card-num">1</div><div class="card-title">Business & Location</div></div>
          <div class="grid-2">
            <input type="text" name="business_type" placeholder="Business Type (e.g. Salons)" required value="<?= htmlspecialchars($businessType) ?>">
            <input type="text" name="district" placeholder="District *" required value="<?= htmlspecialchars($district) ?>">
          </div>
          <div class="grid-2" style="margin-top: 1rem;">
            <input type="text" name="state" placeholder="State" value="<?= htmlspecialchars($state) ?>">
            <input type="text" name="country" placeholder="Country" value="<?= htmlspecialchars($country) ?>">
          </div>
          <div class="presets" style="margin-top: 1rem;">
            <?php foreach ($businessPresets as $p): ?>
              <button type="button" class="preset-btn" onclick="document.getElementsByName('business_type')[0].value='<?= $p ?>'"><?= $p ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><div class="card-num">2</div><div class="card-title">Data Fields</div></div>
          <div class="check-grid">
            <?php foreach ($allDataFields as $k => $v): ?>
              <label class="check-item checked">
                <input type="checkbox" name="data_fields[]" value="<?= $k ?>" checked style="display:none;" onchange="this.parentElement.classList.toggle('checked')">
                <span class="check-box"></span><span style="font-size:0.7rem;"><?= $v ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><div class="card-num">3</div><div class="card-title">Sources</div></div>
          <div class="check-grid">
            <?php foreach ($allSources as $k => $v): ?>
              <label class="check-item checked">
                <input type="checkbox" name="sources[]" value="<?= $k ?>" checked style="display:none;" onchange="this.parentElement.classList.toggle('checked')">
                <span class="check-box"></span><span style="font-size:0.7rem;"><?= $v ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <button type="submit" class="btn-generate">Generate Research Prompt</button>
      </form>

      <div class="card" style="margin-top: 3rem; border-color: var(--accent);">
        <div class="card-header"><div class="card-num">!</div><div class="card-title">Import Results</div></div>
        <form method="POST">
          <textarea name="sql_input" style="height: 150px;" placeholder="Paste SQL from LLM here..."></textarea>
          <button type="submit" name="execute_sql" class="btn-generate" style="background:var(--success);">Import Data</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<footer style="text-align:center; padding: 2rem; color:var(--muted); font-size:0.6rem; font-family:'JetBrains Mono', monospace;">ResearchAgent v1.3</footer>

<script>
function copyPrompt() {
  navigator.clipboard.writeText(document.getElementById('promptText').textContent).then(() => alert('Copied!'));
}
</script>
</body>
</html>