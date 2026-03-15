import sys
import os
import importlib.metadata

# --- 終極防護：攔截並騙過 Streamlit 的版本檢查 ---
orig_version = importlib.metadata.version
def safe_version(package_name):
    if package_name == 'streamlit':
        return '1.99.0'
    return orig_version(package_name)
importlib.metadata.version = safe_version
# ------------------------------------------------

import streamlit.web.cli as stcli

if __name__ == "__main__":
    if getattr(sys, 'frozen', False):
        curr_dir = os.path.dirname(sys.executable)
    else:
        curr_dir = os.path.dirname(os.path.abspath(__file__))
        
    app_path = os.path.join(curr_dir, "app.py")
    
    # 強制設定為正式生產模式，關閉開發者模式，並固定 Port 為 8501
    sys.argv = [
        "streamlit", 
        "run", 
        app_path, 
        "--global.developmentMode=false",
        "--server.headless=true",
        "--server.port=8501"
    ]
    sys.exit(stcli.main())