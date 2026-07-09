# Pure PHP RAG Engine 🚀

*(English version follows the Chinese description below)*

一個輕量、極簡、**零依賴 (Zero Dependencies)** 的檢索增強生成 (RAG) 系統。完全使用純 PHP 與 SQLite 打造，無需安裝 Composer 或任何複雜的後端框架，只需將檔案放入 PHP 伺服器即可運作！

A lightweight, minimalist, and **Zero Dependencies** Retrieval-Augmented Generation (RAG) system. Built entirely with pure PHP and SQLite, requiring no Composer or complex backend frameworks. Just drop the files into your PHP server and run!

---

## ✨ 核心特色 / Features

*   **🪶 絕對輕量 (Zero Dependencies)**
    *   **ZH**: 無須 Composer，環境只需要基本的 PHP (內建 cURL 與 PDO_SQLite) 即可運行。
    *   **EN**: No Composer needed. Requires only basic PHP (with built-in cURL and PDO_SQLite) to run.
*   **🤖 靈活的 LLM 支援 (Flexible LLM Support)**
    *   **ZH**: 支援雲端 OpenAI API (gpt-4o, gpt-3.5) 以及地端 Ollama 模型。
    *   **EN**: Supports cloud-based OpenAI API (gpt-4o, gpt-3.5) and local Ollama models.
*   **🧠 獨家 RAG 權重演算法 (Exclusive RAG Scoring Algorithm)**
    *   **ZH**: 內建精準的檢索計分機制：標籤命中 (+15)、標題命中 (+10)、簡介命中 (+5)、時間衰減補償 (最新文章最高 +10)。
    *   **EN**: Built-in precise retrieval scoring: Tag hit (+15), Title hit (+10), Summary hit (+5), and Time decay compensation (up to +10 for the newest articles).
*   **💬 現代化對話介面 (Modern Chat Interface)**
    *   **ZH**: 類似 ChatGPT 的順暢體驗，支援 Markdown 即時渲染，並自動在回答下方附上「參考文件」與「附件下載連結」。
    *   **EN**: Smooth ChatGPT-like experience with real-time Markdown rendering, automatically appending "References" and "Attachment Links" below the answers.
*   **🌍 智慧多語系 (Smart Multilingual)**
    *   **ZH**: 繁/英介面一鍵切換。LLM 嚴格跟隨使用者的提問語言作答（用中文問回中文，用英文問回英文）。
    *   **EN**: One-click UI toggle (ZH/EN). The LLM strictly follows the user's input language for both keyword extraction and final responses.
*   **📂 強大的資料集管理 (Powerful Dataset Management)**
    *   **ZH**: 內建 `dataset.php`，支援 TXT 文本與實體附件上傳，並利用 LLM 自動生成文章標題、摘要與 Tags。
    *   **EN**: Built-in `dataset.php` supporting TXT and physical attachment uploads, utilizing the LLM to auto-generate titles, summaries, and tags.

---

## 🛠️ 快速安裝與使用 / Quick Start

1.  **下載專案 / Download**
    *   **ZH**: 將本專案所有檔案複製或 `git clone` 到您的 PHP Web Server 目錄下（例如 Apache, Nginx, XAMPP）。
    *   **EN**: Clone or copy all files to your PHP Web Server directory (e.g., Apache, Nginx, XAMPP).
2.  **確認環境權限 / Check Permissions**
    *   **ZH**: 確保該目錄允許 PHP 具備 **寫入權限**，因為系統會自動建立 `articles.db`、`settings.json` 以及 `data/` 資料夾。
    *   **EN**: Ensure the directory has **write permissions** so PHP can auto-generate the database (`articles.db`), config file (`settings.json`), and the `data/` folder for attachments.
3.  **設定 LLM API / Setup API**
    *   **ZH**: 打開瀏覽器進入 `dataset.php`，點擊右上角的「⚙️」設定按鈕，填入您的 API 類型、URL、Model 以及 API Key，然後點擊儲存。
    *   **EN**: Access `dataset.php` in your browser, click the top-right "⚙️" icon, fill in your API Type, URL, Model, and API Key, then click save.
4.  **建立知識庫 / Build Knowledge Base**
    *   **ZH**: 在 `dataset.php` 中新增或上傳您的文章與附件。
    *   **EN**: Upload or paste your articles and attachments directly in `dataset.php`.
5.  **開始對話 / Start Chatting**
    *   **ZH**: 訪問 `index.php`，開始體驗專屬於您的 RAG 知識庫對話！
    *   **EN**: Navigate to `index.php` and start your personalized RAG conversation!

---

## 📁 檔案結構 / File Structure

```text
Pure-PHP-RAG-Engine/
├── index.php         # RAG 智能對話介面 / RAG Chat Interface (Frontend)
├── dataset.php       # 知識庫管理系統 / Knowledge Base Management (Backend)
├── articles.db       # SQLite 資料庫 / SQLite DB (Auto-generated)
├── settings.json     # LLM 參數設定檔 / API Config (Auto-generated)
└── data/             # 附件儲存目錄 / Attachments folder (Auto-generated)

```

---

## ⚙️ RAG 運作原理 / How it Works

本引擎的 RAG 對話流程採用以下 6 步驟確保回答精準度並節省 Token：
Our RAG logic utilizes a 6-step process to ensure accuracy and prevent Token overflow:

1. **關鍵字萃取 (Keyword Extraction)**：LLM 將使用者的問題轉為對應語言的關鍵字標籤 (JSON Array)。 / *LLM translates the user's prompt into keyword tags.*
2. **資料庫檢索 (Database Retrieval)**：將標籤與 SQLite 資料庫中的 Title, Summary, Tags 進行比對。 / *Matches tags against the database.*
3. **權重計分 (Scoring)**：依照命中位置與文章新舊程度計算總分，過濾無關文章。 / *Calculates score based on hit locations and recency.*
4. **Context 組合 (Context Assembly)**：將最高分的文章內容組合，並嚴格限制字數 (約 6000 字元內)。 / *Combines top-scored articles while strictly limiting character count.*
5. **LLM 生成 (LLM Generation)**：將 Context 與歷史紀錄一併交由 LLM 產出最終解答。 / *Generates the final answer using Context and chat history.*
6. **前端渲染 (Frontend Rendering)**：動態轉換 Markdown，並掛載參考來源與附件。 / *Dynamically parses Markdown and appends reference links.*

---

## 🤝 貢獻 / Contributing

**ZH**: 歡迎提交 Pull Requests 或是建立 Issues！如果您有更好的 prompt 想法、權重算法優化，或是想加入新功能，都非常歡迎。
**EN**: Pull requests and Issues are welcome! If you have better prompt engineering ideas, scoring algorithm optimizations, or new features, feel free to contribute.

## 📜 授權 / License

**ZH**: 本專案採用 [MIT License](https://www.google.com/search?q=LICENSE) 授權。您可以自由使用、修改與商業運用。
**EN**: This project is licensed under the [MIT License](https://www.google.com/search?q=LICENSE). You are free to use, modify, and distribute it commercially.

```

```
