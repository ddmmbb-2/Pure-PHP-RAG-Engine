# Pure PHP RAG Engine 🚀

一個輕量、極簡、**零依賴 (Zero Dependencies)** 的檢索增強生成 (RAG) 系統。完全使用純 PHP 與 SQLite 打造，無需安裝 Composer 或任何複雜的後端框架，只需將檔案放入 PHP 伺服器即可運作！

A lightweight, minimalist, and **Zero Dependencies** Retrieval-Augmented Generation (RAG) system. Built entirely with pure PHP and SQLite, requiring no Composer or complex backend frameworks. Just drop the files into your PHP server and run!

---

## 📋 目錄 / Table of Contents

- [核心特色 / Features](#-核心特色--features)
- [系統需求 / Requirements](#-系統需求--requirements)
- [快速開始 / Quick Start](#-快速開始--quick-start)
- [詳細設定 / Configuration](#-詳細設定--configuration)
  - [LLM API 設定 / LLM API Setup](#-llm-api-設定--llm-api-setup)
  - [支援的 API 類型 / Supported API Types](#-支援的-api-類型--supported-api-types)
  - [使用地端 Ollama / Using Local Ollama](#-使用地端-ollama--using-local-ollama)
- [知識庫管理 (dataset.php)](#-知識庫管理-datasetphp)
- [RAG 對話介面 (index.php)](#-rag-對話介面-indexphp)
- [RAG 運作原理詳解 / How RAG Works](#-rag-運作原理詳解--how-rag-works)
- [權重計分邏輯 / Scoring Algorithm](#-權重計分邏輯--scoring-algorithm)
- [客製化調整 / Customization](#-客製化調整--customization)
- [檔案結構 / File Structure](#-檔案結構--file-structure)
- [常見問題 / FAQ](#-常見問題--faq)
- [貢獻 / Contributing](#-貢獻--contributing)
- [授權 / License](#-授權--license)

---

## ✨ 核心特色 / Features

*   **🪶 絕對輕量 (Zero Dependencies)**
    *   **ZH**: 無須 Composer，無須框架。環境只需要 PHP 7.4+，並啟用 cURL 與 PDO_SQLite 擴充功能。所有依賴（Bootstrap、marked.js）皆透過 CDN 載入。
    *   **EN**: No Composer, no framework. Only requires PHP 7.4+ with cURL and PDO_SQLite extensions enabled. All frontend dependencies (Bootstrap, marked.js) are loaded via CDN.
*   **🤖 靈活的 LLM 支援 (Flexible LLM Support)**
    *   **ZH**: 支援 OpenAI 相容 API（gpt-4o、gpt-3.5 等）以及本地 Ollama。可隨時在 `dataset.php` 設定頁切換。
    *   **EN**: Supports OpenAI-compatible APIs (gpt-4o, gpt-3.5, etc.) and local Ollama. Switch anytime via the settings panel in `dataset.php`.
*   **🧠 獨家 RAG 權重演算法 (Exclusive RAG Scoring)**
    *   **ZH**: 採用「標籤命中（+15）、標題命中（+10）、摘要命中（+5）」加權，並加入「時間衰減補償」（最新文章最高+10），確保精準且具時效性的檢索結果。
    *   **EN**: Weighted scoring based on "Tag hit (+15), Title hit (+10), Summary hit (+5)" plus a time decay bonus (up to +10 for the freshest articles) for precise, timely retrieval.
*   **💬 現代化對話介面 (Modern Chat UI)**
    *   **ZH**: 類似 ChatGPT 的響應式介面，支援 Markdown 即時渲染。每次回答自動附上「參考文件」與「附件下載連結」，點擊文件標題可彈出完整內容。
    *   **EN**: Responsive ChatGPT-like interface with real-time Markdown rendering. Each answer automatically appends "References" and "Attachment Links", with a modal popup to view full document content.
*   **🌍 智慧多語系 (Smart Multilingual)**
    *   **ZH**: 介面支援繁中 / 英文一鍵切換。LLM 嚴格跟隨使用者提問的語言回答，並以相同語言萃取關鍵字。
    *   **EN**: UI toggle for Traditional Chinese / English. The LLM strictly follows the user's input language for both keyword extraction and final responses.
*   **📂 強大的資料集管理 (Powerful Dataset Management)**
    *   **ZH**: `dataset.php` 提供文章上傳（貼上文字或 .txt 檔案），並利用 LLM 自動生成標題、摘要、標籤。支援多附件上傳、搜尋、分頁、內文 / 標籤編輯、LLM 重寫、刪除等完整 CRUD。
    *   **EN**: `dataset.php` allows article upload (paste or .txt), with LLM auto-generating titles, summaries, and tags. Supports multiple attachments, search, pagination, full CRUD (edit content, edit tags, LLM rewrite, delete).

---

## 💻 系統需求 / Requirements

- PHP 7.4 或更高版本 / PHP 7.4 or higher
- 必要 PHP 擴充功能 / Required extensions:
  - `curl`
  - `pdo_sqlite`
  - `mbstring`
  - `json`
- 網頁伺服器（Apache、Nginx、XAMPP、內建 PHP server 皆可）
- 寫入權限於專案根目錄（自動建立資料庫及 `data/` 資料夾）

---

## 🚀 快速開始 / Quick Start

1. **下載或複製專案 / Download or Clone**
   ```bash
   git clone https://github.com/ddmmbb-2/Pure-PHP-RAG-Engine.git
   cd Pure-PHP-RAG-Engine
   ```
   或直接下載 ZIP 並解壓縮至網頁伺服器目錄。

2. **設定寫入權限 / Set Write Permissions**
   確保 PHP 有權限在專案目錄下建立檔案：
   ```bash
   chmod -R 755 .   # Linux / macOS
   ```
   Windows 用戶通常無需特別設定。

3. **啟動伺服器 / Start Server**
   若使用 PHP 內建伺服器：
   ```bash
   php -S localhost:8000
   ```
   然後在瀏覽器打開 `http://localhost:8000`。

4. **首次設定 LLM API / First-time API Setup**
   進入 `dataset.php`，點擊右上角 ⚙️ 按鈕，填入：
   - **API 類型**：OpenAI 或 Ollama
   - **API URL**：預設為 OpenAI `https://api.openai.com/v1/chat/completions`
   - **模型**：例如 `gpt-4o-mini`、`gpt-3.5-turbo`
   - **API Key**：若使用 OpenAI 則必填
   點擊儲存後，系統會自動產生 `settings.json`。

5. **上傳知識文件 / Upload Knowledge**
   在 `dataset.php` 中貼上文字或上傳 `.txt` 檔案，並可附加任意附件（PDF、圖片等，但不解析內容，僅供下載）。LLM 將自動產生標題、摘要與標籤。

6. **開始對話 / Start Chat**
   返回首頁 (`index.php`)，輸入問題，系統將根據您的知識庫回答並附上參考來源。

---

## ⚙️ 詳細設定 / Configuration

### 🔑 LLM API 設定 / LLM API Setup

所有設定儲存在專案根目錄的 `settings.json`。您可以手動編輯，或透過網頁介面（`dataset.php` → 設定按鈕）修改。

```json
{
  "api_type": "openai",
  "api_url": "https://api.openai.com/v1/chat/completions",
  "model": "gpt-4o-mini",
  "api_key": "sk-..."
}
```

- **api_type**：`openai` 或 `ollama`。當選用 `ollama` 時，`api_key` 會被忽略。
- **api_url**：API 端點。使用 Ollama 時通常為 `http://localhost:11434/v1/chat/completions`（須先確保模型已下載）。
- **model**：模型名稱。對於 Ollama，請確認已執行 `ollama pull <model>`。
- **api_key**：僅 OpenAI 需要。

### 🌐 支援的 API 類型 / Supported API Types

| API 類型 | 範例 URL | 需要 API Key |
|----------|----------|--------------|
| OpenAI   | `https://api.openai.com/v1/chat/completions` | 是 |
| Ollama   | `http://localhost:11434/v1/chat/completions` | 否 |
| 其他 OpenAI 相容服務 (如 Groq, DeepInfra) | 自訂 | 視服務而定 |

### 🦙 使用地端 Ollama / Using Local Ollama

1. 安裝 Ollama：`curl -fsSL https://ollama.com/install.sh | sh`
2. 下載模型：`ollama pull llama3` 或 `ollama pull mistral`
3. 確認服務運行：`ollama serve` （預設監聽 11434 port）
4. 在 `dataset.php` 設定中：
   - API 類型：Ollama
   - API URL：`http://localhost:11434/v1/chat/completions`
   - 模型：輸入您下載的模型名稱，如 `llama3`
   - API Key：留空

---

## 📚 知識庫管理 (dataset.php)

此頁面提供完整的文章生命週期管理：

- **新增文章**：貼上純文字或上傳 `.txt` 檔案，LLM 將自動生成標題、摘要（100 字內）與主題標籤（JSON 陣列）。若文章提及日期，會強制添加 `yyyyMMdd` 格式標籤以便查詢。
- **附件**：可一併上傳多個檔案，儲存在 `data/` 目錄，並於文章列表與對話結果中提供下載。
- **列表功能**：表格顯示所有文章，含分頁（每頁 30 筆）、關鍵字搜尋（搜尋標題、摘要、標籤、內文）。
- **操作按鈕**：
  - 📝 編輯內文：可修改文章全文並上傳新附件（不會覆蓋原有附件）。
  - 🏷️ 編輯標籤：直接輸入逗號分隔的標籤。
  - ✨ LLM 重寫：呼叫 LLM 對文章進行重點整理與細節補充。
  - 🗑️ 刪除：刪除文章及相關附件檔案。

---

## 💬 RAG 對話介面 (index.php)

- **聊天室風格**：訊息氣泡區分使用者與 AI，AI 回答支援 Markdown 呈現（標題、列表、程式碼區塊等）。
- **記憶機制**：基於 Session 保留對話脈絡，可點擊「清除記憶」重置。
- **參考文件區塊**：每則 AI 回答下方自動列出檢索到的文件標題與附件。點擊標題彈出 Modal 檢視完整內文，附件另開下載。
- **語言切換**：右上角下拉選單可隨時切換繁中 / 英文介面。
- **RAG 觸發**：每次發送訊息，後端自動進行關鍵字萃取 → 資料庫檢索 → 權重計分 → Context 組合 → LLM 回答。

---

## 🧪 RAG 運作原理詳解 / How RAG Works

整個 RAG 流程發生在 `index.php` 的 POST 請求處理中，包含以下階段：

1. **關鍵字萃取 (Keyword Extraction)**
   - 使用者提問 → 呼叫 LLM 萃取成逗號分隔的關鍵字（如 `AI,機器學習,2024`）。
   - 為避免 JSON 格式錯誤，採用純文字列表而非 JSON 陣列，大幅提高穩定性。

2. **資料庫全文檢索 (Database Retrieval)**
   - 將所有文章的 `title`, `summary`, `tags` 欄位與每個關鍵字進行**大小寫無關**比對。
   - 若任一關鍵字命中，該文章進入計分階段。

3. **權重計分與時間補償 (Scoring & Time Bonus)**
   - 基礎分：標籤完全相等 +15，標題包含 +10，摘要包含 +5。
   - 時間補償：先依照建立時間排序，最新文章可獲得最高 +10 的時間獎勵分數，越舊遞減。
   - 最終分數 = 基礎分 + 時間獎勵分，依此重新排序。

4. **Context 組合 (Context Assembly)**
   - 從最高分文章開始，擷取 `標題` + `完整內文`，合併成單一字串。
   - 控制總字數在 **6000 字元**以內（可於程式碼中修改 `$charLimit`），避免超出 LLM 上下文限制。

5. **LLM 回答生成 (LLM Generation)**
   - 系統提示詞要求 LLM 嚴格依據提供的參考資料回答，並強制使用使用者提問的語言。
   - 將最近幾輪對話歷史（最多 4000 字元）與當前問題一併傳送，保持上下文連貫。
   - 呼叫 LLM API，取得最終回答。

6. **前端渲染與參考來源 (Frontend Rendering)**
   - AI 回答以 Markdown 解析後顯示。
   - 下方自動附加「📖 參考文件」清單，包含文件標題（可點擊查看全文）與附件下載連結。
   - 這些參考資訊會存入 Session，重新整理頁面也不會消失。

---

## ⚖️ 權重計分邏輯 / Scoring Algorithm

| 比對位置 / Match Location | 分數 / Score |
|---------------------------|--------------|
| 標籤完全相等 (Tag exact match) | +15 |
| 標題包含關鍵字 (Title contains) | +10 |
| 摘要包含關鍵字 (Summary contains) | +5 |
| 時間獎勵 (Time bonus) | 最新文章 +10，往後每篇遞減 1，最低 0 |

總分 = 基礎分總和 + 時間獎勵。  
此設計確保「標籤精準匹配」權重最高，同時「新文章」有輕微加分，避免過時資訊主導。

---

## 🛠️ 客製化調整 / Customization

您可以直接編輯 PHP 原始碼來調整系統行為：

- **修改 Context 長度上限**：  
  在 `index.php` 中搜尋 `$charLimit = 6000;`，調整數字即可。
- **修改歷史對話保留長度**：  
  搜尋 `$historyCharCount > 4000`，變更上限。
- **調整分頁筆數**：  
  `dataset.php` 中 `$limit = 30;`。
- **更換前端 Markdown 渲染器**：  
  使用 CDN 的 `marked.js`，可更換版本或改用其他函式庫。
- **變更 LLM 溫度參數**：  
  在 `callLLM` 函數中，`$temperature` 預設為 0.3（關鍵字萃取）與 0.5（回答生成），可自行調整。
- **自訂系統提示詞**：  
  搜尋 `$sysPrompt`，修改 LLM 的行為指令。

---

## 📁 檔案結構 / File Structure

```text
Pure-PHP-RAG-Engine/
├── index.php          # 前端聊天介面 + 後端 RAG 邏輯 (單檔整合)
├── dataset.php        # 知識庫管理後台
├── articles.db        # SQLite 資料庫 (自動產生)
├── settings.json      # LLM API 設定 (自動產生)
├── data/              # 附件存放目錄 (自動產生)
└── README.md          # 本說明文件
```

---


---

## ❓ 常見問題 / FAQ

**Q: 為什麼我上傳文章後，對話時卻找不到相關資訊？**  
A: 請檢查以下項目：
- LLM 萃取出的關鍵字是否與文章標籤/標題相符？可觀察 `index.php` 的網路回應中的 `references` 是否為空。
- 文章內文過長可能超過 Context 字數限制（6000 字），導致部分文章被截斷。可調高 `$charLimit`。
- 確保 `settings.json` 設定正確且 LLM 服務正常。

**Q: 可以使用自己的 OpenAI API Key 嗎？**  
A: 當然，請在 `dataset.php` 設定頁面中輸入您的金鑰。系統不會將金鑰上傳至任何第三方。

**Q: 如何讓 LLM 回答只使用我上傳的資料，不依賴模型自身知識？**  
A: 可修改 `index.php` 中的系統提示詞 (`$sysPrompt`)，加入更嚴格的指示，例如「如果參考資料沒有答案，請回答『我不知道』，不要使用外部知識」。

**Q: 附件支援哪些格式？**  
A: 任何檔案皆可上傳，但僅作為下載用途，系統**不會**解析附件內容（例如 PDF、圖片），僅將它們與文章關聯。

**Q: 可以部署到共享主機 (shared hosting) 嗎？**  
A: 可以，只要主機支援 PHP 7.4+ 和 SQLite3，且擁有寫入權限即可。

**🔒 安全性提醒 / Security Notice**  
- 本系統**未內建任何使用者認證機制**（無登入、無權限控制），因此**不建議直接暴露於公開網際網路**。  
- 適合的部署場景為**內部網路（區網）**，例如作為公司、團隊或個人的私有 Wiki／知識庫 RAG 系統。  
- 若您需要對外開放使用，**請務必自行增加登入系統**（例如 HTTP Basic Auth、IP 限制，或整合既有帳號驗證），並妥善保護 `dataset.php` 管理後台，避免未授權存取與資料外洩。  
- 另外，請確保 `data/` 目錄及 `settings.json` 檔案權限設定正確（例如禁止直接透過網址存取），以保護您的 API 金鑰與上傳檔案。

---

## 🤝 貢獻 / Contributing

歡迎提交 Pull Requests 或回報 Issues！  
如果您有更好的 Prompt 設計、權重演算法優化、或想新增功能（例如串接更多 API、支援 embedding 搜尋），都非常歡迎。

---

## 📜 授權 / License

本專案採用 [MIT License](LICENSE) 授權。您可以自由使用、修改與商業運用。  
This project is licensed under the [MIT License](LICENSE).

