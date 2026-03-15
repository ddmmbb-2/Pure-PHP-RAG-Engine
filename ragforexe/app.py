import streamlit as st
import sqlite3
import json
import requests
import pandas as pd
from datetime import datetime
import re

# --- 1. 初始化與配置 ---
def load_config():
    with open('config.json', 'r', encoding='utf-8') as f:
        return json.load(f)

def save_config(config_data):
    with open('config.json', 'w', encoding='utf-8') as f:
        json.dump(config_data, f, indent=4, ensure_ascii=False)

# --- 2. 資料庫操作 ---
def init_db():
    conn = sqlite3.connect('docs_vault.db')
    c = conn.cursor()
    c.execute('''CREATE TABLE IF NOT EXISTS documents 
                 (id INTEGER PRIMARY KEY AUTOINCREMENT, 
                  filename TEXT, content TEXT, title TEXT, 
                  summary TEXT, tags TEXT, 
                  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)''')
    conn.commit()
    conn.close()

def add_document(filename, content, title, summary, tags):
    conn = sqlite3.connect('docs_vault.db')
    c = conn.cursor()
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    c.execute("INSERT INTO documents (filename, content, title, summary, tags, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
              (filename, content, title, summary, tags, now, now))
    conn.commit()
    conn.close()

def update_document(doc_id, title, summary, tags, content):
    conn = sqlite3.connect('docs_vault.db')
    c = conn.cursor()
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    c.execute('''UPDATE documents 
                 SET title = ?, summary = ?, tags = ?, content = ?, updated_at = ? 
                 WHERE id = ?''', 
              (title, summary, tags, content, now, doc_id))
    conn.commit()
    conn.close()

def get_all_docs():
    conn = sqlite3.connect('docs_vault.db')
    conn.row_factory = sqlite3.Row
    c = conn.cursor()
    c.execute("SELECT * FROM documents ORDER BY updated_at DESC")
    docs = [dict(row) for row in c.fetchall()]
    conn.close()
    return docs

def get_doc_by_id(doc_id):
    conn = sqlite3.connect('docs_vault.db')
    conn.row_factory = sqlite3.Row
    c = conn.cursor()
    c.execute("SELECT * FROM documents WHERE id = ?", (doc_id,))
    row = c.fetchone()
    conn.close()
    return dict(row) if row else None

# --- 3. Ollama 核心呼叫函數 ---
def call_ollama(prompt, config, as_json=False):
    payload = {
        "model": config['model'],
        "prompt": prompt,
        "stream": False
    }
    if as_json:
        payload["format"] = "json"
    
    res = requests.post(config['ollama_url'], json=payload, timeout=60)
    res.raise_for_status()
    result = res.json()['response']
    
    if as_json:
        try:
            return json.loads(result)
        except json.JSONDecodeError:
            match = re.search(r'\{.*\}|\[.*\]', result, re.DOTALL)
            if match:
                return json.loads(match.group(0))
            return {}
    return result

# --- 4. RAG 核心邏輯 ---
def score_and_sort_docs(keywords):
    docs = get_all_docs()
    scored_docs = []
    
    safe_keywords = [str(kw).lower() for kw in keywords if kw]
    
    for doc in docs:
        score = 0
        doc_tags = str(doc['tags']).lower()
        doc_title = str(doc['title']).lower()
        doc_summary = str(doc['summary']).lower()
        doc_content = str(doc['content']).lower()
        
        for kw in safe_keywords:
            if kw in doc_tags: score += 30
            if kw in doc_title: score += 20
            if kw in doc_summary: score += 20
            if kw in doc_content: score += 10
        
        if score > 0:
            scored_docs.append({'score': score, 'doc': doc})
            
    scored_docs.sort(key=lambda x: (x['score'], x['doc']['updated_at']), reverse=True)
    return [x['doc'] for x in scored_docs[:10]] 

# --- 5. Streamlit UI ---
st.set_page_config(page_title="私人助理 & 文件管理", layout="wide")
init_db()
config = load_config()

if "messages" not in st.session_state:
    st.session_state.messages = []

with st.sidebar:
    st.title("控制面板")
    if st.button("🗑️ 清除對話紀錄", use_container_width=True):
        st.session_state.messages = []
        st.rerun()

st.title("🤖 智慧文檔庫與 RAG 助理")

tab1, tab2, tab3 = st.tabs(["💬 智能對話", "📁 文件管理與編輯", "⚙️ 系統設定"])

# ===== Tab 3: 系統設定 =====
with tab3:
    st.header("⚙️ 系統與 Prompt 設定")
    with st.form("config_form"):
        col1, col2 = st.columns(2)
        with col1:
            new_url = st.text_input("Ollama API 位址", config['ollama_url'])
        with col2:
            new_model = st.text_input("模型名稱", config['model'])
            
        st.subheader("📝 提示詞 (Prompt) 調整")
        new_p_doc = st.text_area("1. 建立/重寫 文件標籤與簡述", config['prompts']['doc_processing'], height=150)
        new_p_tag = st.text_area("2. 萃取使用者問題關鍵字", config['prompts']['question_tags'], height=100)
        new_p_sel = st.text_area("3. RAG 篩選最適文件", config['prompts']['doc_selection'], height=150)
        new_p_sum = st.text_area("4. 總結歷史對話", config['prompts']['chat_summary'], height=100)
        new_p_fin = st.text_area("5. 最終回答組合", config['prompts']['final_answer'], height=250)
        
        if st.form_submit_button("💾 儲存所有設定"):
            config['ollama_url'] = new_url
            config['model'] = new_model
            config['prompts']['doc_processing'] = new_p_doc
            config['prompts']['question_tags'] = new_p_tag
            config['prompts']['doc_selection'] = new_p_sel
            config['prompts']['chat_summary'] = new_p_sum
            config['prompts']['final_answer'] = new_p_fin
            save_config(config)
            st.success("✅ 設定已成功儲存並更新！")

# ===== Tab 2: 文件管理與編輯 =====
with tab2:
    manage_tab1, manage_tab2, manage_tab3 = st.tabs(["📤 上傳新文件", "✏️ 編輯文件", "📊 資料庫總覽"])
    
    with manage_tab1:
        st.subheader("新增文件")
        uploaded_file = st.file_uploader("上傳文字檔 (.txt)", type=['txt'])
        manual_text = st.text_area("或直接輸入內容", height=150)
        
        if st.button("上傳並讓 AI 建檔"):
            content = ""
            fname = "手動輸入"
            if uploaded_file:
                content = uploaded_file.read().decode('utf-8')
                fname = uploaded_file.name
            elif manual_text:
                content = manual_text
                
            if content:
                with st.spinner("AI 正在解析並建檔..."):
                    prompt = config['prompts']['doc_processing'] + content[:3000]
                    analysis = call_ollama(prompt, config, as_json=True)
                    add_document(fname, content, analysis.get('title', '未命名'), analysis.get('summary', '無摘要'), analysis.get('tags', '無標籤'))
                    st.success("✅ 文件已成功建檔！可至「編輯文件」微調。")
            else:
                st.warning("請提供內容！")

    with manage_tab2:
        st.subheader("編輯與優化現有文件")
        all_docs_list = get_all_docs()
        if not all_docs_list:
            st.info("目前資料庫沒有文件。")
        else:
            doc_options = {d['id']: f"[{d['id']}] {d['title']}" for d in all_docs_list}
            selected_edit_id = st.selectbox("請選擇要編輯的文件", options=list(doc_options.keys()), format_func=lambda x: doc_options[x])
            
            if selected_edit_id:
                current_doc = get_doc_by_id(selected_edit_id)
                st.divider()
                st.markdown("##### 🤖 AI 輔助功能")
                if st.button("✨ 讓 AI 重新評估並覆寫 標題/簡述/標籤"):
                    with st.spinner("AI 正在重新閱讀並生成中..."):
                        prompt = config['prompts']['doc_processing'] + current_doc['content'][:3000]
                        new_meta = call_ollama(prompt, config, as_json=True)
                        update_document(current_doc['id'], new_meta.get('title', current_doc['title']), new_meta.get('summary', current_doc['summary']), new_meta.get('tags', current_doc['tags']), current_doc['content'])
                        st.success("✅ AI 已重新生成並儲存！")
                        st.rerun() 
                
                st.markdown("##### ✍️ 手動修改模式")
                with st.form("manual_edit_form"):
                    edit_title = st.text_input("📝 標題", value=current_doc['title'])
                    edit_tags = st.text_input("🏷️ 標籤 (逗號分隔)", value=current_doc['tags'])
                    edit_summary = st.text_area("📋 簡述", value=current_doc['summary'], height=100)
                    edit_content = st.text_area("📄 內文", value=current_doc['content'], height=300)
                    
                    if st.form_submit_button("💾 儲存手動修改"):
                        update_document(current_doc['id'], edit_title, edit_summary, edit_tags, edit_content)
                        st.success("✅ 修改已成功儲存！")
                        st.rerun()

    with manage_tab3:
        st.subheader("資料庫總覽")
        if all_docs_list:
            df = pd.DataFrame(all_docs_list)[['id', 'title', 'summary', 'tags', 'updated_at']]
            st.dataframe(df, use_container_width=True, hide_index=True)

# ===== Tab 1: 智能對話 (RAG) =====
with tab1:
    # 🌟 重點更新：設定 height 參數，創造一個固定高度且可內部滾動的視窗
    # 你可以把 600 改成 500 或 700，取決於你的螢幕大小
    chat_container = st.container(height=600)
    
    with chat_container:
        for msg in st.session_state.messages:
            with st.chat_message(msg["role"]):
                if msg["role"] == "assistant" and "keywords" in msg:
                    st.caption(f"🎯 **觸發搜尋關鍵字**: `{', '.join([str(k) for k in msg['keywords']])}`")
                
                st.markdown(msg["content"])
                
                if "refs" in msg and msg["refs"]:
                    for doc in msg["refs"]:
                        with st.expander(f"📄 參考來源: {doc['title']}"):
                            st.caption(f"🏷️ 標籤: {doc['tags']} | 🕒 更新時間: {doc['updated_at']}")
                            st.markdown(f"**摘要**: {doc['summary']}")
                            st.text_area("內文", doc['content'], height=150, disabled=True, key=f"ref_{msg['content'][:5]}_{doc['id']}")

    # st.chat_input 預設就會貼齊在瀏覽器視窗的最下方
    if prompt := st.chat_input("請輸入您的問題..."):
        st.session_state.messages.append({"role": "user", "content": prompt})
        
        # 使用者輸入的內容也要丟進滾動區塊裡
        with chat_container:
            with st.chat_message("user"):
                st.markdown(prompt)

            with st.chat_message("assistant"):
                status_text = st.empty()
                current_time_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                
                status_text.info("🔍 步驟 1/4：萃取問題關鍵字...")
                raw_keyword_result = call_ollama(config['prompts']['question_tags'] + prompt, config, as_json=True)
                keywords = []
                if isinstance(raw_keyword_result, dict) and "keywords" in raw_keyword_result:
                    keywords = raw_keyword_result["keywords"]
                elif isinstance(raw_keyword_result, list):
                    keywords = raw_keyword_result
                else:
                    keywords = [prompt]
                keywords = [str(k) for k in keywords if k]
                
                status_text.info("🧮 步驟 2/4：搜尋並計算關聯度...")
                top_10_docs = score_and_sort_docs(keywords)
                
                rag_content = "無相關文件。"
                selected_docs_list = [] 
                
                if top_10_docs:
                    status_text.info("📑 步驟 3/4：LLM 篩選精確文件...")
                    doc_list_str = "\n".join([f"序號:{d['id']}, 標題:{d['title']}, 簡述:{d['summary']}, 標籤:{d['tags']}" for d in top_10_docs])
                    
                    selection_prompt = config['prompts']['doc_selection']\
                        .replace('{current_time}', current_time_str)\
                        .replace('{question}', prompt)\
                        .replace('{doc_list}', doc_list_str)
                    
                    raw_selected_ids = call_ollama(selection_prompt, config, as_json=True)
                    selected_ids = []
                    if isinstance(raw_selected_ids, dict) and "selected_ids" in raw_selected_ids:
                        selected_ids = raw_selected_ids["selected_ids"]
                    elif isinstance(raw_selected_ids, list):
                        selected_ids = raw_selected_ids
                    
                    try:
                        valid_ids = [int(i) for i in selected_ids]
                    except:
                        valid_ids = []

                    if valid_ids:
                        selected_docs_list = [d for d in top_10_docs if d['id'] in valid_ids]
                        if selected_docs_list:
                            rag_content = "\n\n---\n\n".join([f"文件標題: {d['title']}\n時間標籤: {d['tags']}\n內容:\n{d['content']}" for d in selected_docs_list])

                chat_summary = "無歷史對話。"
                recent_msgs = st.session_state.messages[-11:-1]
                if recent_msgs:
                    status_text.info("📝 步驟 4/4：摘要歷史對話...")
                    history_str = "\n".join([f"{m['role']}: {m['content']}" for m in recent_msgs])
                    
                    chat_summary_prompt = config['prompts']['chat_summary'].replace('{history}', history_str)
                    chat_summary = call_ollama(chat_summary_prompt, config)

                status_text.info("✨ 正在生成最終回答...")
                
                final_prompt = config['prompts']['final_answer']\
                    .replace('{current_time}', current_time_str)\
                    .replace('{rag_docs}', rag_content)\
                    .replace('{open_doc_section}', "")\
                    .replace('{chat_summary}', chat_summary)\
                    .replace('{question}', prompt)

                final_answer = call_ollama(final_prompt, config)
                status_text.empty()
                
                st.caption(f"🎯 **觸發搜尋關鍵字**: `{', '.join([str(k) for k in keywords])}`")
                st.markdown(final_answer)
                
                if selected_docs_list:
                    for doc in selected_docs_list:
                        with st.expander(f"📄 參考來源: {doc['title']}"):
                            st.caption(f"🏷️ 標籤: {doc['tags']} | 🕒 更新時間: {doc['updated_at']}")
                            st.markdown(f"**摘要**: {doc['summary']}")
                            st.text_area("內文", doc['content'], height=150, disabled=True)
                
                st.session_state.messages.append({
                    "role": "assistant", 
                    "content": final_answer,
                    "refs": selected_docs_list,
                    "keywords": keywords
                })