<?php
// --- 0. Basic Settings ---
error_reporting(E_ALL & ~E_NOTICE); 
ini_set('display_errors', 0); 

// --- 1. Configurations ---
$dbPath = __DIR__ . '/documents.db';
$apiUrl = 'http://127.0.0.1:11434/v1/chat/completions';
$modelName = 'gemma3:12b';
$currentTime = date('Y-m-d H:i:s');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['chat_history'])) { $_SESSION['chat_history'] = []; }

ini_set('max_execution_time', 600);
set_time_limit(600);

// --- 2. Database Initialization ---
try {
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // WAL mode for safe concurrent reading
    $db->exec("PRAGMA journal_mode = WAL;");
} catch (Exception $e) {
    die("System initialization failed.");
}

// --- 3. AI Core Function ---
function callAiChat($messages, $isJson = false) {
    global $apiUrl, $modelName;
    $data = [
        "model" => $modelName, "messages" => $messages,
        "temperature" => 0.2, "max_tokens" => 1200
    ];
    if ($isJson) { $data["response_format"] = ["type" => "json_object"]; }
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 180
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $arr = json_decode($res, true);
    return $arr['choices'][0]['message']['content'] ?? null;
}

// --- 4. Process User Input ---
$processLog = []; 
$userQuestion = isset($_POST['question']) ? trim($_POST['question']) : '';
$lastFinalDocs = []; // For frontend display

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($userQuestion) && !isset($_POST['action'])) {
    
    // Step A: Keyword/Tag Extraction
    $resA = callAiChat([
        ["role" => "system", "content" => "Return JSON: {\"tags\":[]}"],
        ["role" => "user", "content" => "Extract keywords from: \"$userQuestion\""]
    ], true);
    $tags = json_decode($resA, true)['tags'] ?? [$userQuestion];
    $processLog[] = "🏷️ Tags: " . implode(',', $tags);

    // Step B: Retrieval (PHP Aggregation based on Multiple Tags)
    $candidates = []; 
    $activeTags = array_slice($tags, 0, 3); // Take top 3 tags
    
    foreach ($activeTags as $tag) {
        $q = "%$tag%";
        try {
            $stmt = $db->prepare("SELECT title, description, content FROM documents WHERE title LIKE ? OR tags LIKE ? OR content LIKE ? LIMIT 20");
            $stmt->execute([$q, $q, $q]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($rows as $row) {
                $title = $row['title'];
                if (!isset($candidates[$title])) {
                    $candidates[$title] = $row;
                    $candidates[$title]['agg_score'] = 10; // Base score
                } else {
                    $candidates[$title]['agg_score'] += 15; // Tag overlap bonus
                }
                
                if (mb_strpos($title, $tag) !== false) {
                    $candidates[$title]['agg_score'] += 5; // Title match bonus
                }
            }
        } catch (Exception $e) {
            $processLog[] = "❌ Error searching tag [$tag]";
        }
    }

    usort($candidates, function($a, $b) {
        return $b['agg_score'] <=> $a['agg_score'];
    });

    $docs = array_slice(array_values($candidates), 0, 10);
    $processLog[] = "🔍 Aggregation search done, filtered " . count($docs) . " candidates";

    // Step C: AI Reranking
    if (!empty($docs)) {
        $listStr = "";
        foreach ($docs as $i => $d) {
            $listStr .= "ID:$i | Title:{$d['title']}\n";
        }

       $resC = callAiChat([
            [
                "role" => "system",
                "content" => "You are a document relevance scorer.\n\n[System Time Calibration]\nCurrent time is: $currentTime\nIf the user's question contains time-relative words (e.g., 'current', 'now', 'this year', 'latest'), use this current time to evaluate which document title is most relevant.\n\nTask:\nEvaluate if each title can answer the question.\n\nScoring standard:\n100 = Perfectly matches the question\n70 = Relevant\n40 = Slightly relevant\n0 = Irrelevant\n\nScore all IDs.\n\nOutput ONLY JSON:\n{\"scores\":[{\"id\":0,\"score\":85}]}"
            ],
            [
                "role" => "user",
                "content" => "Question: \"$userQuestion\"\n\nList to evaluate:\n$listStr"
            ]
        ], true);

        // Analysis Log Block
        $analysisLogFile = __DIR__ . '/rerank_analysis.log';
        $logContent = "========================================\n";
        $logContent .= "Time: " . date('Y-m-d H:i:s') . "\n";
        $logContent .= "User Question: $userQuestion\n";
        $logContent .= "List provided to AI: \n$listStr";
        $logContent .= "AI Raw Response: $resC\n";
        $logContent .= "========================================\n\n";
        @file_put_contents($analysisLogFile, $logContent, FILE_APPEND);

        $data = json_decode($resC, true);
        $scores = $data['scores'] ?? [];

        if (empty($scores)) {
            $lastFinalDocs = [];
            $processLog[] = "⚠️ Rerank parsing failed or no score";
        } else {
            usort($scores, function($a, $b) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            });

            $threshold = 10; // Minimum score threshold
            $pickedIds = [];
            $lastFinalDocs = []; 
            
            foreach (array_slice($scores, 0, 10) as $s) { 
                $currentScore = $s['score'] ?? 0;
                $idx = $s['id'] ?? null;
                
                if ($currentScore >= $threshold && $idx !== null && isset($docs[$idx])) {
                    $lastFinalDocs[] = $docs[$idx];
                    $pickedIds[] = "ID:$idx ({$currentScore} pts)";
                    if (count($lastFinalDocs) >= 3) break; 
                }
            }

            if (empty($lastFinalDocs)) {
                $maxScore = (!empty($scores)) ? $scores[0]['score'] : 0;
                $processLog[] = "☁️ Not enough relevant documents (Highest $maxScore pts, threshold $threshold not met)";
            } else {
                $processLog[] = "🎯 AI Selected: " . implode(', ', $pickedIds);
            }
        }
    }

    // Step D: Context & Time Calibration (Dynamic Snippet Extraction)
    $timeInstruction = "[System Time Calibration]\n- Current physical time: $currentTime\n- Year: 2026\nPlease use this as the baseline for calculations.";
    $ragContext = "";
    
    if (!empty($lastFinalDocs)) {
        foreach ($lastFinalDocs as $d) {
            $content = strip_tags($d['content']);
            $bestPos = 0;

            // Find the position of the first matching tag
            foreach ($tags as $tag) {
                if (!empty($tag)) {
                    $pos = mb_strpos($content, $tag);
                    if ($pos !== false) {
                        // Move back 100 characters to keep context
                        $bestPos = max(0, $pos - 100);
                        break; 
                    }
                }
            }

            // Extract 800 characters
            $cleanContent = mb_substr($content, $bestPos, 800);
            
            if ($bestPos > 0) {
                $cleanContent = "..." . $cleanContent;
            }

            $ragContext .= "[Source: {$d['title']}]\n" . $cleanContent . "\n---\n";
        }
        
        $systemPrompt = $timeInstruction . "\n
Please answer based on the provided data first. Explain in detail if available in the database.

[Important Reasoning Instruction]
If the question involves time calculation, tenure, or numerical ranges, follow these steps strictly:
1. List the known numbers/dates extracted from the question at the beginning.
2. Show your calculation process step by step.
3. Compare the result with the criteria in the source data exactly.
4. Finally, provide the conclusion.

Data Context:
" . $ragContext;
        $processLog[] = "📚 Extracted key snippets from " . count($lastFinalDocs) . " documents";
    } else {
        $systemPrompt = $timeInstruction . "\nNo relevant documents found in the database currently. Please answer using general knowledge, but clarify that the answer is not from the database.";
        $processLog[] = "☁️ General Chat Mode";
    }

    // Step E: Final Response Generation
    $messages = [["role" => "system", "content" => $systemPrompt]];
    foreach (array_slice($_SESSION['chat_history'], -10) as $chat) {
        $messages[] = ["role" => "user", "content" => $chat['q']];
        $messages[] = ["role" => "assistant", "content" => $chat['a']];
    }
    $messages[] = ["role" => "user", "content" => $userQuestion];

    $aiResponse = callAiChat($messages);
    if ($aiResponse) {
        $_SESSION['chat_history'][] = [
            'q' => $userQuestion, 
            'a' => $aiResponse,
            'docs' => $lastFinalDocs 
        ];
    }
}

// Clear History
if (isset($_POST['action']) && $_POST['action'] === 'clear') {
    $_SESSION['chat_history'] = [];
    header("Location: " . $_SERVER['REQUEST_URI']); exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AI RAG Assistant</title>
    <style>
        body, html { margin: 0; padding: 0; height: 100%; width: 100%; background: #fff; font-family: 'Segoe UI', sans-serif; overflow: hidden; }
        .ai-chat-wrap { display: flex; flex-direction: column; height: 100vh; }
        .ai-header { background: #343a40; color: #fff; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; font-size: 14px; }
        .ai-header a { color: #fff; text-decoration: none; background: #007bff; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; transition: 0.3s; }
        .ai-header a:hover { background: #0056b3; }
        .ai-log { font-size: 11px; color: #666; background: #f8f9fa; padding: 6px 15px; border-bottom: 1px solid #eee; }
        .ai-box { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 15px; }
        
        /* Message Styles */
        .ai-msg { padding: 14px 18px; border-radius: 12px; max-width: 85%; font-size: 14px; line-height: 1.6; }
        .user { align-self: flex-end; background: #007bff; color: white; border-bottom-right-radius: 2px; }
        .bot { align-self: flex-start; background: #f1f3f4; color: #333; border-bottom-left-radius: 2px; width: 100%; box-sizing: border-box; }
        
        /* Source Tags */
        .source-wrap { margin-top: 12px; border-top: 1px dashed #ccc; padding-top: 10px; }
        .source-tag { 
            display: inline-block; background: #fff; border: 1px solid #007bff; color: #007bff; 
            padding: 4px 10px; border-radius: 12px; font-size: 11px; margin: 4px 4px 0 0; cursor: pointer; 
            transition: all 0.2s;
        }
        .source-tag:hover { background: #007bff; color: #fff; }

        /* Modal Styles */
        #modalOverlay { 
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center; 
        }
        .modal-content { 
            background: white; width: 90%; max-width: 800px; max-height: 80%; border-radius: 10px; 
            display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.3);
        }
        .modal-header { padding: 15px 20px; background: #f8f9fa; border-bottom: 1px solid #eee; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 20px; overflow-y: auto; font-size: 14px; line-height: 1.7; white-space: pre-wrap; color: #333; }
        
        /* Input Area */
        .ai-input-area { padding: 15px 20px; border-top: 1px solid #eee; background: #fff; display: flex; gap: 10px; }
        .ai-input-area input { flex: 1; padding: 12px 18px; border: 1px solid #ddd; border-radius: 25px; outline: none; font-size: 14px; }
        .ai-input-area button { padding: 10px 24px; background: #007bff; color: white; border: none; border-radius: 25px; cursor: pointer; font-weight: bold; font-size: 14px; }
        #loading { display: none; text-align: center; color: #888; font-size: 13px; margin-bottom: 10px; font-style: italic; }
    </style>
</head>
<body>

<div id="modalOverlay">
    <div class="modal-content">
        <div class="modal-header">
            <span id="mTitle"></span>
            <span onclick="closeModal()" style="cursor:pointer; font-size:24px; line-height:1;">&times;</span>
        </div>
        <div class="modal-body" id="mBody"></div>
    </div>
</div>

<div class="ai-chat-wrap">
    <div class="ai-header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <span style="font-size: 16px; font-weight: bold;">🤖 AI RAG Assistant</span>
            <a href="index.php">📁 Admin Panel</a>
        </div>
        <form method="POST" style="margin:0;">
            <input type="hidden" name="action" value="clear">
            <button type="submit" style="background:transparent; border:1px solid rgba(255,255,255,0.5); color:#fff; cursor:pointer; font-size:11px; border-radius:4px; padding:4px 8px;">Clear History</button>
        </form>
    </div>

    <?php if (!empty($processLog)): ?>
        <div class="ai-log"><?php echo implode(' &nbsp;|&nbsp; ', $processLog); ?></div>
    <?php endif; ?>

    <div class="ai-box" id="aiBox">
        <?php foreach ($_SESSION['chat_history'] as $chat): ?>
            <div class="ai-msg user"><?php echo htmlspecialchars($chat['q']); ?></div>
            <div class="ai-msg bot">
                <div><?php echo nl2br(htmlspecialchars($chat['a'])); ?></div>
                <?php if (!empty($chat['docs'])): ?>
                    <div class="source-wrap">
                        <div style="font-size: 11px; color: #888; margin-bottom: 5px;">📍 Sources (Click to read full text):</div>
                        <?php foreach ($chat['docs'] as $d): ?>
                            <div class="source-tag" onclick="openModal('<?php echo addslashes($d['title']); ?>', <?php echo htmlspecialchars(json_encode($d['content'])); ?>)">
                                📖 <?php echo htmlspecialchars($d['title']); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div id="loading">✨ Searching documents and thinking...</div>
    </div>

    <form class="ai-input-area" method="POST" onsubmit="document.getElementById('loading').style.display='block';">
        <input type="text" name="question" placeholder="Ask a question..." required autocomplete="off">
        <button type="submit">Send</button>
    </form>
</div>

<script>
    var box = document.getElementById('aiBox');
    box.scrollTop = box.scrollHeight;

    function openModal(title, content) {
        document.getElementById('mTitle').innerText = title;
        document.getElementById('mBody').innerText = content;
        document.getElementById('modalOverlay').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('modalOverlay').style.display = 'none';
    }

    // Close modal on background click
    window.onclick = function(event) {
        if (event.target == document.getElementById('modalOverlay')) closeModal();
    }
</script>
</body>
</html>
