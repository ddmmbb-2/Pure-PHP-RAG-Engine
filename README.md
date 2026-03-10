# Lightweight PHP RAG Engine

A pure PHP and SQLite implementation of a Retrieval-Augmented Generation (RAG) system. This project is designed to be highly accessible and pragmatic, requiring no complex vector databases, Python environments, or Composer dependencies. 

Instead of relying on naive vector similarity—which often suffers from semantic blurring and hallucinates connections—this engine uses a **Hybrid LLM-SQL Pipeline**. It leverages the LLM for intelligent tagging, uses multi-tag SQL aggregation for rapid pre-filtering, and employs LLM reranking to guarantee high-accuracy context retrieval.

---

## 🧠 How the RAG Pipeline Works (The "Secret Sauce")

This system achieves high precision by breaking the RAG process into distinct, manageable steps, using the LLM exactly where it excels:

### 1. Intelligent Ingestion (Indexing Phase)
When a document is uploaded (`index.php`), the engine doesn't just blindly save the text. It immediately prompts the LLM to analyze the content and generate three crucial pieces of metadata:
* **A Contextual Title:** To clearly identify the document.
* **A Concise Description:** A 50-word summary for quick reference.
* **Targeted Tags (JSON Array):** The LLM extracts the most relevant keywords. This essentially acts as our "vector equivalent," condensing the semantic meaning into highly searchable, concrete text strings.

### 2. Smart Query Translation (Retrieval Phase A)
When a user asks a question (`dokuwiki.php`), the system first asks the LLM to extract the core intent and keywords from the natural language query, returning them as a JSON array of tags.

### 3. Multi-Tag SQL Aggregation (Retrieval Phase B)
Using the extracted top 3 tags, the engine performs a fast, traditional SQLite `LIKE` search across titles, descriptions, and tags. It applies an **Aggregation Scoring System**:
* Base score for hitting a tag (+10).
* Overlap bonus if a document hits multiple tags (+15).
* Title match bonus (+5).
This quickly narrows down a massive database to the Top 10 most statistically relevant candidates without the overhead of vector math.

### 4. LLM Reranking & Time Calibration (Retrieval Phase C)
This is where the accuracy skyrockets. The system feeds the Top 10 candidate titles/IDs back to the LLM. Acting as a strict relevance judge, the LLM is provided with the **current physical system time** and asked to score each document (0-100) based on how well it answers the specific query. Documents scoring below a strict threshold are discarded.

### 5. Dynamic Context Windowing (Retrieval Phase D)
To prevent "attention dilution" (a common issue when feeding huge documents into an LLM), the system locates the exact position of the matched tags within the winning documents. It then extracts a precise 800-character window surrounding the keywords. 

### 6. Grounded Generation (Generation Phase)
Finally, the system feeds the extracted, highly relevant snippets into the LLM with a strict prompt forcing it to:
* Acknowledge the current system time.
* Show step-by-step reasoning for calculations or date comparisons.
* Base its final answer *only* on the provided context database.

---

## ⚡ Core Features

* **Zero Dependencies:** Runs on any standard PHP server with the SQLite PDO extension enabled. Drop the files into your server and it works.
* **Time-Aware Reasoning:** Injects real-time server timestamps into the LLM prompt, allowing for accurate answers regarding "current," "past," or "upcoming" events.
* **LLM Auto-Rewriting:** Includes an admin feature to have the LLM condense and rewrite messy, unformatted text into clean bullet points before saving it to the database.
* **Database Stability:** Implements SQLite WAL (Write-Ahead Logging) mode, busy timeouts, and atomic updates to completely prevent database malformation and locking during long AI API processing times.
* **Source Citations:** The chat interface directly links to the specific documents used to generate the answer, providing a modal to read the full original text.

---

## 🎯 Ideal Use Cases & Limitations

To keep this engine as lightweight and accessible as possible, it makes specific architectural trade-offs. It is highly optimized for certain environments while purposefully ignoring others.

* **The Sweet Spot: Many Small Documents:** This system shines when managing hundreds of smaller, discrete text entries (e.g., wiki pages, company policies, daily notes, or FAQ snippets). 
* **Limitation with Massive Single Files:** It is *not* currently designed to ingest a single massive file (like a 100-page book) as one database row. Because the dynamic windowing extracts an 800-character snippet around the first keyword match, relying on a single massive text blob will severely limit the RAG's ability to cross-reference multiple sections of that same document.
* **Plain Text Only:** The engine currently only processes raw text (pasted text or `.txt` uploads). There is no built-in implementation for parsing PDFs, Word documents, or performing OCR on images.
* **Optimized for Local, Open-Source LLMs:** You do not need expensive, paid API keys (like OpenAI or Anthropic) to run this. The prompt engineering and reranking logic are specifically tuned to work beautifully with local Ollama instances running smaller models, such as `gemma3:12b` or `llama3`. It is built for a 100% free, private, and localized workflow.
* **Network & Deployment Constraints:** This system is meant to be an internal tool. If you are deploying this on a Local Area Network (LAN) for multiple devices to access, remember that you must configure your host machine's Ollama instance to accept external incoming connections (e.g., by setting the environment variable `OLLAMA_HOST=0.0.0.0`). However, exposing this entire setup directly to the public internet (WAN) is **highly discouraged** due to the complexities of securing the API and the lack of built-in authentication.

---

## 🚀 Setup Instructions

1. Place `index.php`, `dokuwiki.php`, and `settings.php` into your web server's public directory.
2. Ensure the folder has write permissions so the application can automatically create `documents.db` and the necessary log files.
3. Open `settings.php` in your browser to configure your OpenAI-compatible API endpoint (such as your local Ollama URL) and the model name you wish to use.
4. Navigate to `index.php` to start uploading documents. The system will automatically call the LLM to generate titles, summaries, and searchable tags for each document.
5. Open `dokuwiki.php` to interact with your knowledge base!
6. **Prompt Tuning:** If search results are poor or the generated tags during upload aren't accurate, you can adjust the internal system prompts to better match your chosen LLM. Different models respond differently to specific phrasing; once you've fine-tuned the prompts for your specific model, the RAG pipeline will operate smoothly.

---

## 📂 File Overview

* **`index.php`**: The document management dashboard. Handles file uploads, manual edits, deletions, and triggers AI processing for ingestion (summarization and tagging).
* **`dokuwiki.php`**: The user-facing chat interface. Manages the entire Retrieval and Generation pipeline (Query -> Tag -> SQL Search -> AI Rerank -> Snippet Extraction -> Final Response).
* **`settings.php`**: Global configuration panel for the API endpoint and model selection. Settings are saved directly to the SQLite database.
