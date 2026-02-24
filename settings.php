<?php
// 1. Environment & Database Settings
ini_set('display_errors', 1);
error_reporting(E_ALL);

$dbPath = __DIR__ . '/documents.db';
$message = "";

try {
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Ensure table exists
    $db->exec("CREATE TABLE IF NOT EXISTS settings (key_name TEXT PRIMARY KEY, key_value TEXT)");
} catch (Exception $e) {
    die("System initialization failed: " . $e->getMessage());
}

// 2. Handle POST Request (Save Settings)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    $apiUrl = trim($_POST['api_url']);
    $modelName = trim($_POST['model_name']);

    // Use INSERT OR REPLACE for SQLite to handle both insert and update
    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key_name, key_value) VALUES (?, ?)");
    
    try {
        $db->beginTransaction();
        $stmt->execute(['api_url', $apiUrl]);
        $stmt->execute(['model_name', $modelName]);
        $db->commit();
        $message = "✅ Settings saved successfully!";
    } catch (Exception $e) {
        $db->rollBack();
        $message = "❌ Error saving settings: " . $e->getMessage();
    }
}

// 3. Load Current Settings
$settings = [];
$stmt = $db->query("SELECT * FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key_name']] = $row['key_value'];
}

// Default values if not set
$currentApiUrl = $settings['api_url'] ?? 'http://127.0.0.1:11434/v1/chat/completions';
$currentModelName = $settings['model_name'] ?? 'llama3';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AI System Settings</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; max-width: 1000px; margin: 0 auto; background: #f4f7f6; padding: 20px; }
        .navbar { background: #343a40; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; color: white; }
        .nav-links a { color: white; text-decoration: none; margin-left: 15px; font-weight: bold; padding: 8px 12px; border-radius: 4px; transition: 0.3s; }
        .nav-links a:hover { background: #495057; }
        .nav-links .active-link { background: #007bff; }
        
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; max-width: 600px; margin-left: auto; margin-right: auto; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        .form-group input[type="text"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 14px; font-family: monospace; }
        .form-group small { color: #666; font-size: 12px; display: block; margin-top: 5px; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; font-size: 14px; width: 100%; }
        .btn-blue { background: #007bff; color: white; }
        .btn-blue:hover { background: #0056b3; }
        
        .message { padding: 12px; background: #d4edda; color: #155724; margin-bottom: 20px; border-radius: 4px; border: 1px solid #c3e6cb; text-align: center; max-width: 600px; margin-left: auto; margin-right: auto; }
    </style>
</head>
<body>

    <div class="navbar">
        <div style="font-size: 1.2em;">📁 AI Document Admin</div>
        <div class="nav-links">
            <a href="index.php">Admin Panel</a>
            <a href="dokuwiki.php" style="background: #28a745;">💬 RAG Chat</a>
            <a href="settings.php" class="active-link">⚙️ Settings</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>⚙️ Model & API Settings</h3>
        <p style="color: #666; font-size: 14px; margin-bottom: 20px;">Configure your LLM endpoint and model name here. These settings will be applied globally to both the Admin Panel and the RAG Chat.</p>
        
        <form method="POST">
            <input type="hidden" name="action" value="save_settings">
            
            <div class="form-group">
                <label for="api_url">OpenAI-Compatible API URL</label>
                <input type="text" id="api_url" name="api_url" value="<?php echo htmlspecialchars($currentApiUrl); ?>" required>
                <small>e.g., http://127.0.0.1:11434/v1/chat/completions (Ollama) or https://api.openai.com/v1/chat/completions</small>
            </div>
            
            <div class="form-group">
                <label for="model_name">Model Name</label>
                <input type="text" id="model_name" name="model_name" value="<?php echo htmlspecialchars($currentModelName); ?>" required>
                <small>e.g., gemma3:12b, llama3, gpt-4o-mini</small>
            </div>
            
            <button type="submit" class="btn btn-blue">Save Settings</button>
        </form>
    </div>

</body>
</html>