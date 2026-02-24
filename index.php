<?php
// 1. Environment & Database Settings (Robust setup)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Prevent script termination if the user closes the browser during long AI tasks
ignore_user_abort(true); 
// Remove execution time limit for lengthy AI rewriting/analysis
set_time_limit(0); 

$db = new PDO('sqlite:documents.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Enable WAL mode and timeout to completely prevent SQLite locking/malformed issues
$db->exec("PRAGMA journal_mode = WAL;");
$db->exec("PRAGMA busy_timeout = 10000;");
$db->exec("PRAGMA synchronous = NORMAL;");

// Initialize Tables
$db->exec("CREATE TABLE IF NOT EXISTS documents (
    id INTEGER PRIMARY KEY, title TEXT, description TEXT, content TEXT, 
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, tags TEXT
)");
$db->exec("CREATE TABLE IF NOT EXISTS settings (key_name TEXT PRIMARY KEY, key_value TEXT)");

// Load Settings
$settings = [];
$stmt = $db->query("SELECT * FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key_name']] = $row['key_value'];
}
$apiUrl = $settings['api_url'] ?? 'http://127.0.0.1:11434/v1/chat/completions';
$modelName = $settings['model_name'] ?? 'llama3';

$message = "";

// 2. AI Core Functions
function call_ai_api($url, $model, $system_prompt, $user_prompt, $is_json = false) {
    $data = [
        "model" => $model,
        "messages" => [
            ["role" => "system", "content" => $system_prompt],
            ["role" => "user", "content" => $user_prompt]
        ],
        "temperature" => 0.1
    ];
    if ($is_json) $data["response_format"] = ["type" => "json_object"];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) return "Error: " . curl_error($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    $content = $result['choices'][0]['message']['content'] ?? "";

    if ($is_json) {
        $content = trim($content);
        $content = preg_replace('/^```json/i', '', $content);
        $content = preg_replace('/```$/', '', $content);
        return json_decode(trim($content), true);
    }
    return $content;
}

function get_ai_analysis($apiUrl, $modelName, $content) {
    $meta_prompt = "Please read the following text and return in JSON format: 1. 'title' 2. 'description' (approx 50 words) 3. 'tags' (array). Content:\n" . mb_substr($content, 0, 2000);
    return call_ai_api($apiUrl, $modelName, "You are a professional assistant. Reply strictly in JSON.", $meta_prompt, true);
}

function get_ai_rewrite($apiUrl, $modelName, $content) {
    $rewrite_prompt = "Please rewrite and condense the following article, keeping key information using bullet points. Original text:\n" . $content;
    return call_ai_api($apiUrl, $modelName, "You are a professional document summarizer.", $rewrite_prompt, false);
}

// 3. Handle POST Requests (Add/Delete/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($action === 'delete' && $id > 0) {
        $stmt = $db->prepare("DELETE FROM documents WHERE id = ?");
        $stmt->execute([$id]);
        $message = "🗑️ Document ID: $id deleted";
    }
    elseif ($action === 'update_manual' && $id > 0) {
        $stmt = $db->prepare("UPDATE documents SET title = ?, description = ?, tags = ?, content = ? WHERE id = ?");
        $stmt->execute([$_POST['title'], $_POST['description'], $_POST['tags'], $_POST['content'], $id]);
        $message = "📝 ID: $id manually updated successfully!";
    }
    elseif (($action === 'regenerate' || $action === 'rewrite') && $id > 0) {
        $stmt = $db->prepare("SELECT content FROM documents WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($doc) {
            $newContent = $doc['content'];
            $isRewriteSuccess = false;

            // Perform long AI operations first without locking the database
            if ($action === 'rewrite') {
                $rewritten = get_ai_rewrite($apiUrl, $modelName, $doc['content']);
                if ($rewritten && strpos($rewritten, "Error") !== 0) {
                    $newContent = $rewritten;
                    $isRewriteSuccess = true;
                }
            }
            
            // AI Tag Analysis
            $aiData = get_ai_analysis($apiUrl, $modelName, $newContent);
            
            if ($aiData) {
                $title = $aiData['title'] ?? 'Untitled Document';
                $desc = $aiData['description'] ?? 'No description';
                $tags_str = (isset($aiData['tags']) && is_array($aiData['tags'])) ? implode(',', $aiData['tags']) : 'General';
                
                // Atomic Update to prevent DB corruption
                $stmt = $db->prepare("UPDATE documents SET title = ?, description = ?, tags = ?, content = ? WHERE id = ?");
                $stmt->execute([$title, $desc, $tags_str, $newContent, $id]);
                
                $message = $isRewriteSuccess ? "✍️ ID: $id AI rewritten and analyzed successfully!" : "✅ ID: $id AI analyzed successfully!";
            } else {
                $message = "❌ ID: $id AI analysis failed. Database not updated.";
            }
        }
    } elseif (empty($action)) {
        // Add new document
        $content = $_POST['pasted_text'] ?? '';
        if (isset($_FILES['file_upload']) && $_FILES['file_upload']['error'] === UPLOAD_ERR_OK) {
            $content = file_get_contents($_FILES['file_upload']['tmp_name']);
        }
        if (!empty($content)) {
            $aiData = get_ai_analysis($apiUrl, $modelName, $content);
            $title = $aiData['title'] ?? 'Untitled Document';
            $desc = $aiData['description'] ?? 'No description';
            $tags = (isset($aiData['tags']) && is_array($aiData['tags'])) ? implode(',', $aiData['tags']) : 'General';
            
            $stmt = $db->prepare("INSERT INTO documents (title, description, content, tags) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $desc, $content, $tags]);
            $message = "✅ Document uploaded and analyzed successfully!";
        }
    }
}

// 4. Search Logic
$search = $_GET['q'] ?? '';
$sql = "SELECT * FROM documents";
$params = [];
if (!empty($search)) {
    $sql .= " WHERE title LIKE ? OR tags LIKE ? OR description LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}
$sql .= " ORDER BY id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AI Document Admin</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; max-width: 1000px; margin: 0 auto; background: #f4f7f6; padding: 20px; }
        .navbar { background: #343a40; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; color: white; }
        .nav-links a { color: white; text-decoration: none; margin-left: 15px; font-weight: bold; padding: 8px 12px; border-radius: 4px; transition: 0.3s; }
        .nav-links a:hover { background: #495057; }
        .nav-links .active-link { background: #007bff; }
        
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        textarea { width: 100%; height: 100px; border: 1px solid #ccc; padding: 10px; border-radius: 4px; box-sizing: border-box; font-family: inherit; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        th, td { padding: 15px; border-bottom: 1px solid #eee; text-align: left; }
        th { background: #007bff; color: white; }
        
        .btn { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; font-size: 0.85em; }
        .btn-blue { background: #007bff; color: white; }
        .btn-green { background: #28a745; color: white; }
        .btn-red { background: #dc3545; color: white; }
        .btn-outline { border: 1px solid #ccc; background: white; color: #666; }
        
        .message { padding: 12px; background: #d4edda; color: #155724; margin-bottom: 20px; border-radius: 4px; border: 1px solid #c3e6cb; }
        .tag { display: inline-block; background: #e9ecef; padding: 2px 8px; border-radius: 12px; font-size: 0.75em; margin-right: 5px; color: #495057; }
        
        /* Modal Style */
        #editModal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; }
        .modal-content { background:white; width:600px; margin: 50px auto; padding:20px; border-radius:8px; }
    </style>
</head>
<body>

    <div class="navbar">
        <div style="font-size: 1.2em;">📁 AI Document Admin</div>
        <div class="nav-links">
            <a href="index.php" class="active-link">Admin Panel</a>
            <a href="dokuwiki.php" style="background: #28a745;">💬 RAG Chat</a>
            <a href="settings.php">⚙️ Settings</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>➕ Upload New Document</h3>
        <form method="POST" enctype="multipart/form-data">
            <textarea name="pasted_text" placeholder="Paste article content here..."></textarea>
            <div style="margin-top: 15px; display: flex; justify-content: space-between;">
                <input type="file" name="file_upload" accept=".txt">
                <button type="submit" class="btn btn-blue">Upload & AI Analyze</button>
            </div>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th width="50">ID</th>
                <th>Details</th>
                <th width="200">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($docs as $doc): ?>
            <tr>
                <td><?php echo $doc['id']; ?></td>
                <td>
                    <strong><?php echo htmlspecialchars($doc['title']); ?></strong><br>
                    <small style="color:#666;"><?php echo htmlspecialchars($doc['description']); ?></small><br>
                    <?php if(!empty($doc['tags'])) foreach(explode(',',$doc['tags']) as $t) echo "<span class='tag'>#$t</span>"; ?>
                </td>
                <td>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:5px;">
                        <button class="btn btn-outline" onclick='openEdit(<?php echo json_encode($doc); ?>)'>✏️ Edit</button>
                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this document?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $doc['id']; ?>">
                            <button type="submit" class="btn btn-red" style="width:100%;">🗑️ Delete</button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="action" value="regenerate">
                            <input type="hidden" name="id" value="<?php echo $doc['id']; ?>">
                            <button type="submit" class="btn btn-green" style="width:100%;">🤖 Analyze</button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="action" value="rewrite">
                            <input type="hidden" name="id" value="<?php echo $doc['id']; ?>">
                            <button type="submit" class="btn btn-blue" style="width:100%;">✍️ Rewrite</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div id="editModal">
        <div class="modal-content">
            <h3>📝 Manual Edit Document</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_manual">
                <input type="hidden" name="id" id="edit_id">
                <label>Title:</label><br>
                <input type="text" name="title" id="edit_title" style="width:100%; margin-bottom:10px;"><br>
                <label>Description:</label><br>
                <input type="text" name="description" id="edit_desc" style="width:100%; margin-bottom:10px;"><br>
                <label>Tags (comma separated):</label><br>
                <input type="text" name="tags" id="edit_tags" style="width:100%; margin-bottom:10px;"><br>
                <label>Content:</label><br>
                <textarea name="content" id="edit_content" style="height:200px; padding:10px; border:1px solid #ccc; width:100%; box-sizing:border-box; font-family:inherit;"></textarea><br>
                <div style="text-align:right; margin-top:10px;">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('editModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-blue">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openEdit(doc) {
        document.getElementById('edit_id').value = doc.id;
        document.getElementById('edit_title').value = doc.title;
        document.getElementById('edit_desc').value = doc.description;
        document.getElementById('edit_tags').value = doc.tags;
        document.getElementById('edit_content').value = doc.content;
        document.getElementById('editModal').style.display = 'block';
    }
    </script>

</body>
</html>