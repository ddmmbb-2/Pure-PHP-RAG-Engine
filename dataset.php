<?php
session_start();

// ---------- 語言設定 ----------
$lang = $_SESSION['lang'] ?? 'zh';
if (isset($_POST['lang'])) {
    $lang = $_POST['lang'];
    $_SESSION['lang'] = $lang;
    header('Location: dataset.php');
    exit;
}

// 介面文字
$texts = [
    'zh' => [
        'title' => 'Dataset 文章管理',
        'upload_title' => '新增 / 上傳文章',
        'paste_text' => '貼上文字',
        'upload_file' => '上傳 TXT 檔案',
        'attachments' => '附加檔案（可多選）',
        'submit' => '提交',
        'article_list' => '文章列表',
        'id' => '編號',
        'article_title' => '標題',
        'summary' => '簡介',
        'tags' => '標籤',
        'time' => '建立時間',
        'files' => '附件',
        'actions' => '操作',
        'edit_content' => '修改',
        'edit_tags' => '標籤',
        'rewrite' => '重寫',
        'delete' => '刪除',
        'confirm_delete' => '確定要刪除嗎？',
        'confirm_rewrite' => '確定要讓 LLM 重寫此文章嗎？',
        'settings' => 'LLM 設定',
        'api_type' => 'API 類型',
        'api_url' => 'API URL',
        'model' => '模型',
        'api_key' => 'API Key',
        'save_settings' => '儲存設定',
        'language' => '語言',
        'no_articles' => '尚無文章',
        'no_attachments' => '無附件',
        'attach_label' => '選擇檔案',
        'current_attachments' => '現有附件',
        'upload_new_attachments' => '上傳新附件（會新增，不會覆蓋）',
        'search' => '搜尋',
        'search_placeholder' => '搜尋標題、簡介、標籤或內文...',
        'clear' => '清除',
        'rag_chat' => 'RAG 對話',
        'prev_page' => '上一頁',
        'next_page' => '下一頁',
    ],
    'en' => [
        'title' => 'Dataset Management',
        'upload_title' => 'Upload Article',
        'paste_text' => 'Paste text',
        'upload_file' => 'Upload TXT file',
        'attachments' => 'Attach files (multiple)',
        'submit' => 'Submit',
        'article_list' => 'Article List',
        'id' => 'ID',
        'article_title' => 'Title',
        'summary' => 'Summary',
        'tags' => 'Tags',
        'time' => 'Created',
        'files' => 'Files',
        'actions' => 'Actions',
        'edit_content' => 'Edit',
        'edit_tags' => 'Tags',
        'rewrite' => 'Rewrite',
        'delete' => 'Delete',
        'confirm_delete' => 'Are you sure?',
        'confirm_rewrite' => 'Rewrite this article using LLM?',
        'settings' => 'LLM Settings',
        'api_type' => 'API Type',
        'api_url' => 'API URL',
        'model' => 'Model',
        'api_key' => 'API Key',
        'save_settings' => 'Save Settings',
        'language' => 'Language',
        'no_articles' => 'No articles yet',
        'no_attachments' => 'None',
        'attach_label' => 'Choose files',
        'current_attachments' => 'Current attachments',
        'upload_new_attachments' => 'Upload new attachments',
        'search' => 'Search',
        'search_placeholder' => 'Search title, summary, tags...',
        'clear' => 'Clear',
        'rag_chat' => 'RAG Chat',
        'prev_page' => 'Prev',
        'next_page' => 'Next',
    ]
];
$t = $texts[$lang];

// ---------- 資料庫初始化 ----------
$db = new PDO('sqlite:articles.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA foreign_keys = ON");
$db->exec("CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    summary TEXT NOT NULL,
    tags TEXT NOT NULL DEFAULT '[]',
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    filename TEXT NOT NULL,
    filepath TEXT NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE
)");

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// ---------- 設定管理 ----------
$settingsFile = 'settings.json';
if (!file_exists($settingsFile)) {
    file_put_contents($settingsFile, json_encode([
        'api_type' => 'openai',
        'api_url' => 'https://api.openai.com/v1/chat/completions',
        'model' => 'gpt-4o-mini',
        'api_key' => ''
    ], JSON_PRETTY_PRINT));
}
$settings = json_decode(file_get_contents($settingsFile), true);

if (isset($_POST['save_settings'])) {
    $settings['api_type'] = $_POST['api_type'];
    $settings['api_url'] = $_POST['api_url'];
    $settings['model'] = $_POST['model'];
    $settings['api_key'] = $_POST['api_key'] ?? '';
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    header('Location: dataset.php');
    exit;
}

// ---------- LLM 呼叫函數 ----------
function callLLM($prompt, $system = 'You are a helpful assistant.', $temperature = 0.3) {
    global $settings;
    $url = $settings['api_url'];
    $model = $settings['model'];
    $apiKey = $settings['api_key'];

    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $prompt]
    ];

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature
    ];

    $headers = ['Content-Type: application/json'];
    if ($settings['api_type'] === 'openai') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("LLM API error (HTTP $httpCode): $response");
    }

    $data = json_decode($response, true);
    if (isset($data['choices'][0]['message']['content'])) {
        return trim($data['choices'][0]['message']['content']);
    }
    if (isset($data['message']['content'])) {
        return trim($data['message']['content']);
    }
    throw new Exception("Unexpected API response: $response");
}

// ---------- 輔助函數：儲存附件 ----------
function saveAttachments($articleId, $files) {
    global $db, $dataDir;
    $uploaded = [];
    foreach ($files['tmp_name'] as $i => $tmpName) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        $origName = basename($files['name'][$i]);
        $safeName = uniqid() . '_' . $origName;
        $destPath = $dataDir . '/' . $safeName;
        if (move_uploaded_file($tmpName, $destPath)) {
            $stmt = $db->prepare("INSERT INTO attachments (article_id, filename, filepath) VALUES (?, ?, ?)");
            $stmt->execute([$articleId, $origName, $destPath]);
            $uploaded[] = $origName;
        }
    }
    return $uploaded;
}

// ---------- 處理文章上傳 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_article'])) {
    $text = '';
    if (!empty($_POST['text_content'])) {
        $text = $_POST['text_content'];
    } elseif (!empty($_FILES['txt_file']['tmp_name'])) {
        $text = file_get_contents($_FILES['txt_file']['tmp_name']);
    }

    if (trim($text) === '') {
        $error = '請輸入文字或上傳檔案';
    } else {
        try {
            $titlePrompt = "請根據以下文章內容，用文章本身使用的語言給予一個簡潔的標題。只回傳標題文字，不要加任何引號或說明。\n\n文章：\n$text";
            $title = callLLM($titlePrompt);

            $summaryPrompt = "請根據以下文章內容，用文章本身使用的語言撰寫一段簡短摘要（100字以內）。只回傳摘要文字，不要加任何引號或說明。\n\n文章：\n$text";
            $summary = callLLM($summaryPrompt);

            $tagsPrompt = "請根據以下文章內容，用文章本身使用的語言生成多個主題標籤。如果文章中提及任何具體日期或時間，請務必加入一個「yyyyMMdd」格式的標籤（例如20231001）。以 JSON 陣列格式回傳，例如 [\"科技\", \"AI\", \"20231001\"]。\n\n文章：\n$text";
            $tagsRaw = callLLM($tagsPrompt);
            $tagsRaw = preg_replace('/^```(json)?\s*|\s*```$/m', '', $tagsRaw);
            $tags = json_decode($tagsRaw, true);
            if (!is_array($tags)) {
                $tags = array_map('trim', explode(',', trim($tagsRaw, '[]"\' ')));
            }
            $tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE);

            $stmt = $db->prepare("INSERT INTO articles (title, summary, tags, content) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $summary, $tagsJson, $text]);
            $articleId = $db->lastInsertId();

            if (!empty($_FILES['attachments']['tmp_name'][0])) {
                saveAttachments($articleId, $_FILES['attachments']);
            }

            header('Location: dataset.php');
            exit;
        } catch (Exception $e) {
            $error = 'LLM 處理失敗：' . $e->getMessage();
        }
    }
}

// ---------- 其他操作 ----------
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? null;

if ($action === 'delete' && $id) {
    $stmt = $db->prepare("SELECT filepath FROM attachments WHERE article_id = ?");
    $stmt->execute([$id]);
    $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($paths as $p) {
        if (file_exists($p)) unlink($p);
    }
    $stmt = $db->prepare("DELETE FROM articles WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: dataset.php');
    exit;
}

if ($action === 'edit_content' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $newContent = $_POST['content'];
    $stmt = $db->prepare("UPDATE articles SET content = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$newContent, $id]);

    if (!empty($_FILES['attachments']['tmp_name'][0])) {
        saveAttachments($id, $_FILES['attachments']);
    }
    header('Location: dataset.php');
    exit;
}

if ($action === 'edit_tags' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tagsInput = $_POST['tags'];
    $tagsArray = array_map('trim', explode(',', $tagsInput));
    $tagsJson = json_encode($tagsArray, JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare("UPDATE articles SET tags = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$tagsJson, $id]);
    header('Location: dataset.php');
    exit;
}

if ($action === 'rewrite' && $id) {
    $stmt = $db->prepare("SELECT content FROM articles WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        try {
            $rewritePrompt = "請將以下文章進行重點整理、細節補充，使文章更加精簡易讀，但保留所有重要資訊。使用原文語言輸出。\n\n文章：\n" . $row['content'];
            $rewritten = callLLM($rewritePrompt);
            $stmt = $db->prepare("UPDATE articles SET content = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$rewritten, $id]);
        } catch (Exception $e) {
            $error = 'LLM 重寫失敗：' . $e->getMessage();
        }
    }
    header('Location: dataset.php');
    exit;
}

// 取得編輯中的文章
$editArticle = null;
if ($action === 'edit_content' && $id) {
    $stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
    $stmt->execute([$id]);
    $editArticle = $stmt->fetch(PDO::FETCH_ASSOC);
}

$editTags = null;
if ($action === 'edit_tags' && $id) {
    $stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
    $stmt->execute([$id]);
    $editTags = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ---------- 搜尋與分頁處理 ----------
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 30; // 滿30筆下一頁
$offset = ($page - 1) * $limit;

$whereClause = "";
$params = [];
if ($search !== '') {
    $whereClause = "WHERE a.title LIKE ? OR a.summary LIKE ? OR a.tags LIKE ? OR a.content LIKE ?";
    $searchParam = "%$search%";
    $params = [$searchParam, $searchParam, $searchParam, $searchParam];
}

// 計算總筆數與總頁數
$countStmt = $db->prepare("SELECT COUNT(*) FROM articles a $whereClause");
$countStmt->execute($params);
$totalArticles = $countStmt->fetchColumn();
$totalPages = ceil($totalArticles / $limit);

// 取得文章列表
$query = "
    SELECT a.*, 
           (SELECT COUNT(*) FROM attachments WHERE article_id = a.id) AS file_count
    FROM articles a 
    $whereClause
    ORDER BY a.created_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $db->prepare($query);
$stmt->execute($params);
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getAttachments($articleId) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM attachments WHERE article_id = ? ORDER BY uploaded_at DESC");
    $stmt->execute([$articleId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $t['title'] ?></title>
    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .card { border: none; border-radius: 12px; }
        .card-header { background-color: #fff; border-bottom: 2px solid #f0f2f5; font-weight: bold; border-radius: 12px 12px 0 0 !important; }
        .table-hover tbody tr:hover { background-color: #f1f4f8; }
        .badge { font-weight: 500; padding: 0.4em 0.6em; }
    </style>
</head>
<body class="container py-4">

    <!-- 頂部導覽列 (加入 RAG 與 LLM 設定按鈕) -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-2">
        <h2 class="mb-2 mb-md-0 text-primary fw-bold">
            <i class="bi bi-database-fill"></i> <?= $t['title'] ?>
        </h2>
        <div class="d-flex align-items-center gap-2">
            <!-- 跳轉 RAG 按鈕 -->
            <a href="index.php" class="btn btn-primary shadow-sm">
                <i class="bi bi-chat-dots-fill me-1"></i> <?= $t['rag_chat'] ?>
            </a>
            
            <!-- LLM 設定按鈕 (觸發 Modal) -->
            <button type="button" class="btn btn-outline-secondary bg-white shadow-sm" data-bs-toggle="modal" data-bs-target="#settingsModal" title="<?= $t['settings'] ?>">
                <i class="bi bi-gear-fill"></i>
            </button>
            
            <!-- 語言切換 -->
            <form method="post" class="mb-0 ms-1">
                <select name="lang" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()" style="min-width: 90px;">
                    <option value="zh" <?= $lang === 'zh' ? 'selected' : '' ?>>中文</option>
                    <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
                </select>
            </form>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger shadow-sm rounded-3"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- 上傳區域 -->
    <div class="card shadow-sm mb-4">
        <div class="card-header py-3"><i class="bi bi-cloud-arrow-up-fill text-primary me-2"></i><?= $t['upload_title'] ?></div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold text-secondary"><?= $t['paste_text'] ?></label>
                        <textarea name="text_content" class="form-control bg-light" rows="5" placeholder="<?= $t['paste_text'] ?>..."></textarea>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary"><?= $t['upload_file'] ?></label>
                            <input type="file" name="txt_file" class="form-control bg-light" accept=".txt">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary"><?= $t['attachments'] ?></label>
                            <input type="file" name="attachments[]" class="form-control bg-light" multiple>
                            <div class="form-text"><?= $lang==='zh'?'可同時上傳多個附件，會儲存於 data 目錄':'Multiple files allowed, saved in data folder.' ?></div>
                        </div>
                    </div>
                </div>
                <div class="text-end">
                    <button type="submit" name="upload_article" class="btn btn-primary px-4 shadow-sm"><i class="bi bi-send-fill me-1"></i> <?= $t['submit'] ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- 編輯內文表單 -->
    <?php if ($editArticle): ?>
    <div class="card shadow-sm mb-4 border-primary">
        <div class="card-header bg-primary text-white"><i class="bi bi-pencil-square me-2"></i><?= $t['edit_content'] ?></div>
        <div class="card-body">
            <form method="post" action="?action=edit_content&id=<?= $editArticle['id'] ?>" enctype="multipart/form-data">
                <textarea name="content" class="form-control mb-3 bg-light" rows="10"><?= htmlspecialchars($editArticle['content']) ?></textarea>
                <div class="mb-3">
                    <label class="form-label"><?= $t['upload_new_attachments'] ?></label>
                    <input type="file" name="attachments[]" class="form-control" multiple>
                </div>
                <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> 儲存</button>
                <a href="dataset.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> 取消</a>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- 編輯標籤表單 -->
    <?php if ($editTags): ?>
    <div class="card shadow-sm mb-4 border-info">
        <div class="card-header bg-info text-dark"><i class="bi bi-tags-fill me-2"></i><?= $t['edit_tags'] ?></div>
        <div class="card-body">
            <form method="post" action="?action=edit_tags&id=<?= $editTags['id'] ?>">
                <?php
                    $currentTags = json_decode($editTags['tags'], true) ?: [];
                    $tagsString = implode(', ', $currentTags);
                ?>
                <input type="text" name="tags" class="form-control mb-3" value="<?= htmlspecialchars($tagsString) ?>" placeholder="tag1, tag2, ...">
                <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> 儲存</button>
                <a href="dataset.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> 取消</a>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- 文章列表與搜尋列 -->
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center py-3">
            <span><i class="bi bi-card-list text-primary me-2"></i><?= $t['article_list'] ?></span>
            
            <!-- 搜尋表單 -->
            <form method="get" class="d-flex m-0 gap-2" style="max-width: 400px; width: 100%;">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="<?= $t['search_placeholder'] ?>" value="<?= htmlspecialchars($search) ?>">
                    <?php if($search): ?>
                        <a href="dataset.php" class="btn btn-outline-secondary" title="<?= $t['clear'] ?>"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary px-3"><?= $t['search'] ?></button>
                </div>
            </form>
        </div>

        <div class="card-body p-0">
            <?php if (empty($articles)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                    <?= $search ? '找不到符合條件的文章' : $t['no_articles'] ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th class="ps-3"><?= $t['id'] ?></th>
                                <th><?= $t['article_title'] ?></th>
                                <th style="width: 30%;"><?= $t['summary'] ?></th>
                                <th><?= $t['tags'] ?></th>
                                <th><?= $t['files'] ?></th>
                                <th><?= $t['time'] ?></th>
                                <th class="text-end pe-3"><?= $t['actions'] ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($articles as $article): 
                                $attList = getAttachments($article['id']);
                            ?>
                            <tr>
                                <td class="ps-3 fw-bold text-secondary">#<?= $article['id'] ?></td>
                                <td class="fw-medium text-dark"><?= htmlspecialchars($article['title']) ?></td>
                                <td class="text-muted small"><?= htmlspecialchars(mb_substr($article['summary'], 0, 70)) ?>...</td>
                                <td>
                                    <?php $tagList = json_decode($article['tags'], true) ?: [];
                                    foreach ($tagList as $tag): ?>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary-subtle me-1 rounded-pill"><?= htmlspecialchars($tag) ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php if ($article['file_count'] > 0): ?>
                                        <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="collapse" data-bs-target="#files-<?= $article['id'] ?>">
                                            <i class="bi bi-paperclip"></i> <?= $article['file_count'] ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small"><?= $t['no_attachments'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted">
                                    <?= date('Y-m-d', strtotime($article['created_at'])) ?><br>
                                    <?= date('H:i', strtotime($article['created_at'])) ?>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="btn-group shadow-sm">
                                        <a href="?action=edit_content&id=<?= $article['id'] ?>" class="btn btn-sm btn-outline-primary" title="<?= $t['edit_content'] ?>"><i class="bi bi-pencil"></i></a>
                                        <a href="?action=edit_tags&id=<?= $article['id'] ?>" class="btn btn-sm btn-outline-info" title="<?= $t['edit_tags'] ?>"><i class="bi bi-tags"></i></a>
                                        <a href="?action=rewrite&id=<?= $article['id'] ?>" class="btn btn-sm btn-outline-warning" title="<?= $t['rewrite'] ?>" onclick="return confirm('<?= $t['confirm_rewrite'] ?>')"><i class="bi bi-stars"></i></a>
                                        <a href="?action=delete&id=<?= $article['id'] ?>" class="btn btn-sm btn-outline-danger" title="<?= $t['delete'] ?>" onclick="return confirm('<?= $t['confirm_delete'] ?>')"><i class="bi bi-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <!-- 摺疊附件列 -->
                            <?php if ($article['file_count'] > 0): ?>
                            <tr class="collapse" id="files-<?= $article['id'] ?>">
                                <td colspan="7" class="bg-light px-4 py-3 border-bottom">
                                    <strong><i class="bi bi-folder2-open me-2"></i><?= $t['current_attachments'] ?>:</strong>
                                    <ul class="mb-0 mt-2 list-unstyled d-flex flex-wrap gap-3">
                                        <?php foreach ($attList as $att): ?>
                                            <li>
                                                <a href="<?= htmlspecialchars($att['filepath']) ?>" target="_blank" class="text-decoration-none border rounded px-2 py-1 bg-white shadow-sm d-inline-block">
                                                    <i class="bi bi-file-earmark-text"></i> <?= htmlspecialchars($att['filename']) ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 分頁導覽區塊 -->
    <?php if ($totalPages > 1): ?>
    <nav aria-label="Page navigation" class="mb-5">
        <ul class="pagination justify-content-center shadow-sm">
            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>"><?= $t['prev_page'] ?></a>
            </li>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>"><?= $t['next_page'] ?></a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>

    <!-- LLM 設定 Modal -->
    <div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="settingsModalLabel"><i class="bi bi-gear-fill text-secondary me-2"></i><?= $t['settings'] ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label fw-bold"><?= $t['api_type'] ?></label>
                            <select name="api_type" class="form-select bg-light" id="apiTypeSelect">
                                <option value="openai" <?= $settings['api_type'] === 'openai' ? 'selected' : '' ?>>OpenAI</option>
                                <option value="ollama" <?= $settings['api_type'] === 'ollama' ? 'selected' : '' ?>>Ollama</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold"><?= $t['api_url'] ?></label>
                            <input type="text" name="api_url" class="form-control bg-light" value="<?= htmlspecialchars($settings['api_url']) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold"><?= $t['model'] ?></label>
                            <input type="text" name="model" class="form-control bg-light" value="<?= htmlspecialchars($settings['model']) ?>">
                        </div>
                        <div class="mb-4" id="apiKeyGroup">
                            <label class="form-label fw-bold"><?= $t['api_key'] ?></label>
                            <input type="password" name="api_key" class="form-control bg-light" value="<?= htmlspecialchars($settings['api_key'] ?? '') ?>">
                        </div>
                        <div class="d-grid">
                            <button type="submit" name="save_settings" class="btn btn-primary"><i class="bi bi-save me-1"></i> <?= $t['save_settings'] ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 切換 API Key 顯示邏輯
        const apiTypeSelect = document.getElementById('apiTypeSelect');
        const apiKeyGroup = document.getElementById('apiKeyGroup');
        function toggleApiKey() {
            apiKeyGroup.style.display = apiTypeSelect.value === 'openai' ? 'block' : 'none';
        }
        apiTypeSelect.addEventListener('change', toggleApiKey);
        toggleApiKey();
    </script>
</body>
</html>