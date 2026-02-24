# Lightweight PHP RAG Engine

A pure PHP and SQLite implementation of a Retrieval-Augmented Generation (RAG) system. This project is designed to be highly accessible, requiring no complex vector databases, Python environments, or Composer dependencies. It relies on a hybrid approach of tag-based SQL aggregation and LLM reranking to provide highly accurate answers for small to medium-sized document collections.

## Core Features

* Zero Dependencies: Runs on any standard PHP server with the SQLite PDO extension enabled. Drop the files into your server and it works.
* High Accuracy Retrieval: Avoids the semantic blurring of naive vector search by using multi-tag aggregation scoring in SQLite.
* AI Reranking: Uses the configured LLM to score and filter search results based on a strict criteria before generating the final answer, eliminating irrelevant context.
* Dynamic Context Extraction: Automatically locates keywords within long documents and extracts the surrounding text to optimize the LLM context window and prevent attention dilution.
* Database Stability: Implements SQLite WAL (Write-Ahead Logging) mode, busy timeouts, and atomic updates to prevent database malformation and locking during long AI API processing times.

## Setup Instructions

1. Place `index.php`, `dokuwiki.php`, and `settings.php` into your web server's public directory.
2. Ensure the folder has write permissions so the application can automatically create `documents.db` and the necessary log files.
3. Open `settings.php` in your browser to configure your OpenAI-compatible API endpoint (such as a local Ollama instance or OpenAI's API) and the model name you wish to use.
4. Navigate to `index.php` to start uploading documents. The system will automatically call the LLM to generate titles, concise descriptions, and searchable tags for each document.
5. Open `dokuwiki.php` to interact with your knowledge base. The chat interface provides source citations and a modal to read the full original text.

## File Overview

* index.php: The document management dashboard. Handles file uploads, manual edits, deletions, and triggers AI processing for document summarization and tagging.
* dokuwiki.php: The user-facing chat interface. Manages user queries, executes the tag-based retrieval, performs AI reranking, dynamic snippet extraction, and generates the final grounded response.
* settings.php: Global configuration panel for the API endpoint and model selection. Settings are saved directly to the SQLite database.