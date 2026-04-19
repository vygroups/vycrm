import os
import ftplib
from pathlib import Path

# FTP Credentials
HOST = "147.93.99.228"
USER = "u495954467.vycrm"
PASS = "Tn02aps2391*"
PORT = 21
REMOTE_PATH = "/"

# Files and directories to exclude
EXCLUDE_DIRS = {".git", ".vscode", ".idea", "scripts", "__pycache__", "node_modules"}
EXCLUDE_FILES = {".DS_Store", "deploy.py"}

def upload_dir(ftp, local_dir, remote_dir):
    print(f"Syncing folder: {local_dir} -> {remote_dir}")
    
    # Ensure remote directory exists
    try:
        ftp.mkd(remote_dir)
    except:
        pass # Directory likely already exists

    for item in os.listdir(local_dir):
        local_path = os.path.join(local_dir, item)
        remote_path = os.path.join(remote_dir, item)

        if os.path.isdir(local_path):
            if item in EXCLUDE_DIRS:
                continue
            upload_dir(ftp, local_path, remote_path)
        else:
            if item in EXCLUDE_FILES or item.startswith("."):
                continue
            
            print(f"Uploading: {local_path} -> {remote_path}")
            with open(local_path, "rb") as f:
                ftp.storbinary(f"STOR {remote_path}", f)

def main():
    try:
        ftp = ftplib.FTP()
        ftp.connect(HOST, PORT)
        ftp.login(USER, PASS)
        ftp.cwd(REMOTE_PATH)
        
        upload_dir(ftp, ".", ".")
        
        ftp.quit()
        print("Deployment successful!")
    except Exception as e:
        print(f"Deployment failed: {e}")

if __name__ == "__main__":
    main()
