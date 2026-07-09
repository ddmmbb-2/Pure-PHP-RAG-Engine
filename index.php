<?php
session_start();

// ---------- 1. 語言與初始化設定 ----------
$lang = $_SESSION['lang'] ?? 'zh';
if (isset($_GET['lang'])) {
    $lang = $_GET['lang'];
    $_SESSION['lang'] = $lang;
    header('Location: index.php');
    exit;
}

// 清除記憶
if (isset($_GET['clear'])) {
    $_SESSION['chat_history'] = [];
    header('Location: index.php');
    exit;
}

// 初始化對話紀錄
if (!isset($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}

// 介面文字
$texts = [
    'zh' => [
        'title' => 'RAG 智能對話',
        'manage_data' => '管理資料庫',
        'clear_memory' => '清除記憶',
        'type_message' => '輸入您的問題...',
        'send' => '發送',
        'bot_typing' => 'AI 思考中...',
        'sys_error' => '系統發生錯誤：',
        'reference' => '參考文件',
        'attachments' => '附件',
        'welcome' => '您好！我是基於您的資料庫運作的 AI 助手。請問有什麼我可以幫忙的？'
    ],
    'en' => [
        'title' => 'RAG Chat Assistant',
        'manage_data' => 'Manage Database',
        'clear_memory' => 'Clear Memory',
        'type_message' => 'Type your message...',
        'send' => 'Send',
        'bot_typing' => 'AI is thinking...',
        'sys_error' => 'System error: ',
        'reference' => 'References',
        'attachments' => 'Attachments',
        'welcome' => 'Hello! I am an AI assistant powered by your database. How can I help you today?'
    ]
];
$t = $texts[$lang];

// ---------- 2. 資料庫與設定檔讀取 ----------
$db = new PDO('sqlite:articles.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$settingsFile = 'settings.json';
if (!file_exists($settingsFile)) {
    die("請先至 Dataset 頁面設定 LLM API。");
}
$settings = json_decode(file_get_contents($settingsFile), true);

// 取得附件的函數
function getAttachments($articleId) {
    global $db;
    $stmt = $db->prepare("SELECT filename, filepath FROM attachments WHERE article_id = ?");
    $stmt->execute([$articleId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ---------- 3. 核心 LLM 呼叫函數 (支援對話陣列) ----------
function callLLM($messages, $temperature = 0.3) {
    global $settings;
    $url = $settings['api_url'];
    $model = $settings['model'];
    $apiKey = $settings['api_key'];

    $payload = [
        'model' => $model,
        // 使用 array_values 確保送出的是乾淨的 JSON 陣列 (避免出現 {"0":...})
        'messages' => array_values($messages),
        'temperature' => $temperature
    ];

    $headers = ['Content-Type: application/json'];
    if ($settings['api_type'] === 'openai' && !empty($apiKey)) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
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

// ---------- 4. 處理 AJAX 聊天請求 (RAG 核心邏輯) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    header('Content-Type: application/json');
    
    // 嚴格檢查空訊息
    $userMsg = trim((string)$_POST['message']);
    if ($userMsg === '') {
        echo json_encode(['status' => 'error', 'message' => '訊息內容不可為空']);
        exit;
    }
    
    try {
        // 【步驟 1】：LLM 針對使用者問題 -> 拆解成標籤
        $tagPrompt = "請從以下使用者的提問中，萃取出關鍵字標籤。請務必依據使用者輸入的語言進行萃取。只回傳一個 JSON 陣列（例如：[\"關鍵字1\", \"關鍵字2\"]），不要加上引號、```json 等任何多餘標記。\n\n提問：\n" . $userMsg;
        $tagMessages = [
            ['role' => 'system', 'content' => 'You are a keyword extraction bot.'],
            ['role' => 'user', 'content' => $tagPrompt]
        ];
        $tagResponse = callLLM($tagMessages, 0.1);
        $tagResponse = preg_replace('/^```(json)?\s*|\s*```$/m', '', $tagResponse);
        $extractedTags = json_decode($tagResponse, true);
        
        if (!is_array($extractedTags)) {
            $extractedTags = [$userMsg];
        }

        // 【步驟 2 & 3】：標籤拿去搜尋資料庫，並計算權重分數
        $stmt = $db->query("SELECT id, title, summary, tags, content, created_at FROM articles ORDER BY created_at DESC");
        $allArticles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $scoredArticles = [];
        foreach ($allArticles as $article) {
            $score = 0;
            $dbTags = json_decode($article['tags'], true) ?: [];
            
            foreach ($extractedTags as $searchTag) {
                if (trim($searchTag) === '') continue; // 略過空的標籤
                $searchTagLower = mb_strtolower($searchTag);
                
                if (mb_strpos(mb_strtolower($article['title']), $searchTagLower) !== false) $score += 10;
                if (mb_strpos(mb_strtolower($article['summary']), $searchTagLower) !== false) $score += 5;
                foreach ($dbTags as $t) {
                    if (mb_strtolower($t) === $searchTagLower) $score += 15;
                }
            }
            
            if ($score > 0) {
                $article['base_score'] = $score;
                $scoredArticles[] = $article;
            }
        }

        usort($scoredArticles, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        for ($i = 0; $i < count($scoredArticles); $i++) {
            $timeBonus = max(0, 10 - $i);
            $scoredArticles[$i]['final_score'] = $scoredArticles[$i]['base_score'] + $timeBonus;
        }

        usort($scoredArticles, function($a, $b) {
            return $b['final_score'] <=> $a['final_score'];
        });

        // 【步驟 4】：準備 Context 給 LLM
        $contextText = "";
        $referenceDocs = [];
        $charLimit = 6000;
        $currentCharCount = 0;

        foreach ($scoredArticles as $article) {
            $docStr = "[文件ID: {$article['id']}] 標題: {$article['title']}\n內容: {$article['content']}\n\n";
            if ($currentCharCount + mb_strlen($docStr) > $charLimit) break;
            
            $contextText .= $docStr;
            $currentCharCount += mb_strlen($docStr);
            
            $referenceDocs[] = [
                'id' => $article['id'],
                'title' => $article['title'],
                'attachments' => getAttachments($article['id'])
            ];
        }

        // 【步驟 5】：LLM 依據問題與上下文列表作答
        $sysPrompt = "你是一個專業的資料庫 RAG AI 助手。請『嚴格依據使用者提問的語言』來回答（使用者用中文問就用中文回答，用英文問就用英文回答）。
請根據以下提供的【參考資料】來回答問題。如果參考資料中沒有答案，請根據你的知識回答，但要註明資料庫中未包含此資訊。
【參考資料開始】\n" . ($contextText ?: "無相關資料") . "\n【參考資料結束】";

        // 準備對話歷史 (嚴格過濾空訊息，避免報錯)
        $messages = [];
        $messages[] = ['role' => 'system', 'content' => (string)$sysPrompt];
        
        $historyCharCount = 0;
        $tempHistory = [];
        for ($i = count($_SESSION['chat_history']) - 1; $i >= 0; $i--) {
            $h = $_SESSION['chat_history'][$i];
            
            // 嚴密防禦：若歷史紀錄中缺少 role、content 或 content 為空，則捨棄不送給 LLM
            if (!isset($h['role']) || !isset($h['content']) || trim((string)$h['content']) === '') {
                continue; 
            }
            
            $historyCharCount += mb_strlen($h['content']);
            if ($historyCharCount > 4000) break; 
            
            // 確保只傳送合法的 key 格式
            array_unshift($tempHistory, [
                'role' => $h['role'],
                'content' => (string)$h['content']
            ]);
        }
        $messages = array_merge($messages, $tempHistory);
        
        // 加入本次問題 (已確認非空)
        $messages[] = ['role' => 'user', 'content' => $userMsg];

        // 呼叫 LLM 產出最終回答
        $finalAnswer = callLLM($messages, 0.5);

        // 將本次對話寫入 Session (確保寫入前非空)
        if (trim($finalAnswer) !== '') {
            $_SESSION['chat_history'][] = ['role' => 'user', 'content' => $userMsg];
            $_SESSION['chat_history'][] = ['role' => 'assistant', 'content' => $finalAnswer];
        }

        // 【步驟 6】：回傳最終答案與參考文件
        echo json_encode([
            'status' => 'success',
            'answer' => $finalAnswer,
            'references' => $referenceDocs
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $t['title'] ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f0f2f5; height: 100vh; display: flex; flex-direction: column; }
        .chat-container { flex: 1; overflow-y: auto; padding: 20px; }
        .message-row { margin-bottom: 20px; display: flex; }
        .message-row.user { justify-content: flex-end; }
        .message-bubble { max-width: 75%; padding: 12px 16px; border-radius: 18px; line-height: 1.5; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
        .message-row.user .message-bubble { background-color: #0d6efd; color: white; border-bottom-right-radius: 4px; }
        .message-row.assistant .message-bubble { background-color: white; color: #212529; border-bottom-left-radius: 4px; }
        .chat-input-area { background-color: white; padding: 15px 20px; border-top: 1px solid #dee2e6; }
        textarea.form-control { resize: none; border-radius: 20px; padding-left: 20px; padding-right: 20px; }
        .ref-box { font-size: 0.85em; background-color: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; padding: 10px; margin-top: 12px; }
        .ref-box a { text-decoration: none; font-weight: 500; }
        /* Markdown rendering basic styles */
        .message-bubble pre { background: #2b2b2b; color: #f8f8f2; padding: 10px; border-radius: 5px; overflow-x: auto; margin-top: 10px;}
        .message-bubble code { font-family: monospace; }
/* Markdown 專屬美化樣式 */
.message-bubble h1, .message-bubble h2, .message-bubble h3, .message-bubble h4 { font-weight: bold; margin-top: 12px; margin-bottom: 8px; line-height: 1.4; }
.message-bubble h3 { font-size: 1.15rem; }
.message-bubble ul, .message-bubble ol { padding-left: 1.5rem; margin-bottom: 10px; }
.message-bubble li { margin-bottom: 4px; }
.message-bubble p { margin-bottom: 10px; }
.message-bubble p:last-child { margin-bottom: 0; }
.message-bubble strong { color: #000; font-weight: 700; }
    </style>
<!-- 引入 marked.js 解析 Markdown (使用穩定的 4.3.0 版本) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/marked/4.3.0/marked.min.js"></script>
</head>
<body>

    <!-- 頂部導覽列 -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm px-4 py-2">
        <a class="navbar-brand fw-bold text-primary" href="#"><i class="bi bi-robot"></i> <?= $t['title'] ?></a>
        <div class="d-flex ms-auto align-items-center gap-3">
            <a href="dataset.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-database"></i> <?= $t['manage_data'] ?></a>
            <a href="?clear=1" class="btn btn-outline-danger btn-sm" title="<?= $t['clear_memory'] ?>"><i class="bi bi-eraser-fill"></i></a>
            
            <form method="get" class="mb-0">
                <select name="lang" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()" style="min-width: 90px;">
                    <option value="zh" <?= $lang === 'zh' ? 'selected' : '' ?>>中文</option>
                    <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
                </select>
            </form>
        </div>
    </nav>

    <!-- 對話顯示區 -->
    <div class="chat-container" id="chatBox">
        <div class="message-row assistant">
            <div class="message-bubble shadow-sm">
                <i class="bi bi-stars text-warning"></i> <?= $t['welcome'] ?>
            </div>
        </div>
        
        <?php foreach ($_SESSION['chat_history'] as $msg): ?>
            <?php if(empty(trim($msg['content']))) continue; // 前端也不渲染空訊息 ?>
            <div class="message-row <?= $msg['role'] ?>">
                <div class="message-bubble shadow-sm content-markdown">
                    <?= htmlspecialchars($msg['content']) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 輸入區 -->
    <div class="chat-input-area">
        <div class="container-fluid max-w-custom p-0">
            <div class="input-group input-group-lg shadow-sm rounded-pill">
                <textarea id="userInput" class="form-control border-0" rows="1" placeholder="<?= $t['type_message'] ?>" onkeydown="handleEnter(event)"></textarea>
                <button class="btn btn-primary px-4 rounded-pill border-0" style="border-top-left-radius: 0 !important; border-bottom-left-radius: 0 !important;" id="sendBtn" onclick="sendMessage()">
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
        </div>
    </div>

    <script>
        const chatBox = document.getElementById('chatBox');
        const userInput = document.getElementById('userInput');
        const sendBtn = document.getElementById('sendBtn');
        
        // 介面文字 (JS版)
        const t_bot_typing = <?= json_encode($t['bot_typing']) ?>;
        const t_sys_error = <?= json_encode($t['sys_error']) ?>;
        const t_reference = <?= json_encode($t['reference']) ?>;
        const t_attachments = <?= json_encode($t['attachments']) ?>;

        // 初始化渲染 Markdown
        // 設定 marked.js 支援 GitHub 格式與自動換行
marked.use({
    breaks: true,
    gfm: true
});

// 初始化渲染 Markdown (修正 HTML 層級判斷)
document.querySelectorAll('.content-markdown').forEach(el => {
    // el 是 .message-bubble，它的父元素是 .message-row
    if(el.parentElement.classList.contains('assistant')) {
        el.innerHTML = marked.parse(el.textContent);
    } else {
        // 使用者輸入保留換行
        el.innerHTML = el.textContent.replace(/\n/g, '<br>');
    }
});

        function scrollToBottom() {
            chatBox.scrollTop = chatBox.scrollHeight;
        }
        scrollToBottom();

        function handleEnter(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        }

        async function sendMessage() {
            const text = userInput.value.trim();
            if (!text) return;

            // 1. 顯示使用者訊息
            appendMessage('user', text);
            userInput.value = '';
            userInput.style.height = 'auto'; // reset height

            // 2. 顯示 AI 思考中
            const typingId = 'typing-' + Date.now();
            appendMessage('assistant', `<span class="spinner-grow spinner-grow-sm text-primary" role="status"></span> ${t_bot_typing}`, typingId);

            // 3. 發送 AJAX 請求
            const formData = new URLSearchParams();
            formData.append('message', text);

            try {
                sendBtn.disabled = true;
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });
                
                const result = await response.json();
                document.getElementById(typingId).remove(); // 移除思考中

                if (result.status === 'success') {
                    // 組合回答內容與參考文獻
                    let finalHTML = marked.parse(result.answer);
                    
                    // 【步驟 6】：附上參考文件名稱與附件
                    if (result.references && result.references.length > 0) {
                        let refHTML = `<div class="ref-box"><div class="fw-bold mb-1"><i class="bi bi-book"></i> ${t_reference}:</div><ul class="mb-0 ps-3">`;
                        
                        result.references.forEach(ref => {
                            refHTML += `<li>
                                <a href="dataset.php?action=edit_content&id=${ref.id}" target="_blank">${ref.title}</a>`;
                            
                            // 附件處理
                            if (ref.attachments.length > 0) {
                                refHTML += ` <span class="text-muted ms-2">[${t_attachments}: `;
                                let attLinks = ref.attachments.map(att => `<a href="${att.filepath}" target="_blank" class="text-info"><i class="bi bi-paperclip"></i>${att.filename}</a>`);
                                refHTML += attLinks.join(', ') + `]`;
                            }
                            refHTML += `</li>`;
                        });
                        refHTML += `</ul></div>`;
                        finalHTML += refHTML;
                    }

                    appendRawHTML('assistant', finalHTML);
                } else {
                    appendMessage('assistant', t_sys_error + result.message);
                }
            } catch (error) {
                document.getElementById(typingId)?.remove();
                appendMessage('assistant', t_sys_error + error.message);
            } finally {
                sendBtn.disabled = false;
                scrollToBottom();
            }
        }

        function appendMessage(role, text, id = '') {
            const div = document.createElement('div');
            div.className = `message-row ${role}`;
            if (id) div.id = id;
            
            const bubble = document.createElement('div');
            bubble.className = 'message-bubble shadow-sm';
            
            if (role === 'user') {
                bubble.innerText = text;
            } else {
                bubble.innerHTML = text; // typing animation requires HTML
            }
            
            div.appendChild(bubble);
            chatBox.appendChild(div);
            scrollToBottom();
        }

        function appendRawHTML(role, htmlStr) {
            const div = document.createElement('div');
            div.className = `message-row ${role}`;
            const bubble = document.createElement('div');
            bubble.className = 'message-bubble shadow-sm';
            bubble.innerHTML = htmlStr;
            div.appendChild(bubble);
            chatBox.appendChild(div);
            scrollToBottom();
        }

        // Textarea auto-resize
        userInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
            if (this.scrollHeight > 150) {
                this.style.overflowY = 'auto';
            } else {
                this.style.overflowY = 'hidden';
            }
        });
    </script>
</body>
</html>