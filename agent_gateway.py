import subprocess
import uuid
import time
import os
import sqlite3
from typing import List, Optional
from fastapi import FastAPI, HTTPException, Query, Depends
from pydantic import BaseModel

app = FastAPI()

# SECURITY & CONFIGURATION
# Reads the token injected by Docker Compose, defaulting to a backup string if missing [1]
API_TOKEN = os.environ.get("API_TOKEN", "fallback_token_if_env_fails")
CONTAINER_NAME = "tinypeople-secure-workspace"
CHUNK_SIZE_BYTES = 80 * 1024  # 80KB payload max response buffer
DB_FILE = "/tmp/gateway_state.db"

# --- PERSISTENT STATE INITIALISATION (SQLITE) ---
def init_db():
    with sqlite3.connect(DB_FILE) as conn:
        cursor = conn.cursor()
        # Path locks persistence table
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS path_locks (
                normalized_path TEXT PRIMARY KEY,
                agent_id TEXT,
                expires_at REAL
            )
        """)
        # Output pagination session persistence table
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS output_chunks (
                session_id TEXT,
                chunk_index INTEGER,
                total_chunks INTEGER,
                chunk_data TEXT,
                PRIMARY KEY (session_id, chunk_index)
            )
        """)
        conn.commit()

init_db()

# --- PYDANTIC MODEL SCHEMAS FOR POST REQUEST BODIES ---
class WriteFilePayload(BaseModel):
    path: str
    content: str

class PatchFilePayload(BaseModel):
    patch_file: str
    target_file: str
    agent_id: str

class CommitPayload(BaseModel):
    message: str
    files: List[str]  # Explicit file list constraint enforced here

# --- VALIDATION ENGINE ---
def verify_token(token: str = Query(..., description="API Access Token")):
    if token != API_TOKEN:
        if API_TOKEN == "fallback_token_if_env_fails":
            raise HTTPException(status_code=503, detail="Can't load local security token.")
        else:
            raise HTTPException(status_code=403, detail="Invalid security token.")
    return token

def execute_in_container(cmd_array: list, timeout: int = 30, stdin_data: Optional[str] = None) -> tuple[int, str]:
    """
    Executes commands using array boundaries.
    If stdin_data is supplied, it is piped cleanly via standard OS streams 
    instead of running through messy interpolation strings.
    """
    full_cmd = ["docker", "exec", "-i", "-w", "/home/agent/workspace", CONTAINER_NAME] + cmd_array
    try:
        result = subprocess.run(
            full_cmd, 
            input=stdin_data,
            stdout=subprocess.PIPE, 
            stderr=subprocess.STDOUT, 
            text=True, 
            timeout=timeout
        )
        return result.returncode, result.stdout if result.stdout else ""
    except subprocess.TimeoutExpired:
        return -1, f"Error: Command timed out after {timeout} seconds."
    except Exception as e:
        return -1, f"Host Error: {str(e)}"

def paginate_output(raw_text: str):
    """Splits text out safely into SQLite to insulate data against server restarts."""
    if not raw_text:
        return {"status": "success", "has_more": False, "total_chunks": 0, "chunk_size_bytes": CHUNK_SIZE_BYTES, "data": ""}

    chunks = [raw_text[i:i+CHUNK_SIZE_BYTES] for i in range(0, len(raw_text), CHUNK_SIZE_BYTES)]
    total_chunks = len(chunks)
    
    if total_chunks == 1:
        return {"status": "success", "has_more": False, "total_chunks": 1, "chunk_size_bytes": CHUNK_SIZE_BYTES, "data": chunks}
    
    session_id = str(uuid.uuid4())[:8]
    
    with sqlite3.connect(DB_FILE) as conn:
        cursor = conn.cursor()
        for idx, chunk in enumerate(chunks):
            cursor.execute(
                "INSERT INTO output_chunks VALUES (?, ?, ?, ?)",
                (session_id, idx, total_chunks, chunk)
            )
        conn.commit()
    
    return {
        "status": "success",
        "has_more": True,
        "session_id": session_id,
        "next_chunk_index": 1,
        "total_chunks": total_chunks,
        "chunk_size_bytes": CHUNK_SIZE_BYTES,
        "data": chunks
    }

# --- ATOMIC LOCK MANAGEMENT ---

@app.get("/lock/acquire")
def acquire_lock(path: str, agent_id: str, token: str = Depends(verify_token)):
    norm_path = os.path.normpath(path)
    now = time.time()
    
    with sqlite3.connect(DB_FILE) as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT agent_id, expires_at FROM path_locks WHERE normalized_path = ?", (norm_path,))
        row = cursor.fetchone()
        
        if row:
            locked_by, expires_at = row
            if expires_at > now and locked_by != agent_id:
                raise HTTPException(status_code=423, detail=f"Path locked by another agent ({locked_by}).")
        
        # Insert or update lock (5-minute lease time window)
        cursor.execute(
            "INSERT OR REPLACE INTO path_locks VALUES (?, ?, ?)",
            (norm_path, agent_id, now + 300)
        )
        conn.commit()
        
    return {"status": "success", "message": f"Lock verified for {norm_path}", "expires_in_seconds": 300}

@app.get("/lock/heartbeat")
def lock_heartbeat(path: str, agent_id: str, token: str = Depends(verify_token)):
    """Allows active long-running processes to extend lock leases incrementally."""
    norm_path = os.path.normpath(path)
    now = time.time()
    
    with sqlite3.connect(DB_FILE) as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT agent_id FROM path_locks WHERE normalized_path = ?", (norm_path,))
        row = cursor.fetchone()
        
        if not row or row[0] != agent_id:
            raise HTTPException(status_code=403, detail="Lock lost or owned by another user. Heartbeat rejected.")
            
        cursor.execute("UPDATE path_locks SET expires_at = ? WHERE normalized_path = ?", (now + 300, norm_path))
        conn.commit()
        
    return {"status": "success", "message": f"Lock extended for {norm_path}"}

@app.get("/lock/release")
def release_lock(path: str, agent_id: str, token: str = Depends(verify_token)):
    norm_path = os.path.normpath(path)
    with sqlite3.connect(DB_FILE) as conn:
        cursor = conn.cursor()
        cursor.execute("DELETE FROM path_locks WHERE normalized_path = ? AND agent_id = ?", (norm_path, agent_id))
        if conn.total_changes == 0:
            raise HTTPException(status_code=403, detail="Could not release path lock (either unowned or untracked).")
        conn.commit()
    return {"status": "success", "message": f"Lock dropped for {norm_path}"}

# --- DATA MUTATION ENDPOINTS (POST WITH REQUEST BODIES) ---

@app.post("/file-write")
def run_file_write(payload: WriteFilePayload, token: str = Depends(verify_token)):
    """Writes content into a file securely over raw container stdin pipelines."""
    code, out = execute_in_container(["tee", payload.path], stdin_data=payload.content)
    if code != 0:
        raise HTTPException(status_code=500, detail=f"Failed writing raw buffer to target: {out}")
    return {"status": "success", "message": f"Safely wrote out file content stream to {payload.path}"}

@app.post("/patch")
def run_patch(payload: PatchFilePayload, token: str = Depends(verify_token)):
    norm_path = os.path.normpath(payload.target_file)
    now = time.time()
    
    with sqlite3.connect(DB_FILE) as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT agent_id, expires_at FROM path_locks WHERE normalized_path = ?", (norm_path,))
        row = cursor.fetchone()
        if not row or row[1] < now or row[0] != payload.agent_id:
            raise HTTPException(status_code=423, detail="Active, explicit path lock ownership required before modifying code.")

    # Validate patch state securely via dry run
    code, dry_run = execute_in_container(["patch", "--dry-run", "--fuzz=0", "-p1", "-i", payload.patch_file])
    if "Reversed (or previously applied) patch detected" in dry_run or "Skipping patch" in dry_run:
        raise HTTPException(status_code=409, detail="Idempotency Exception: This patch modification sequence has already been applied.")
    if code != 0 or "FAILED" in dry_run:
        raise HTTPException(status_code=422, detail=f"Patch Verification Failed (Structure mismatch or drift): {dry_run}")

    # Commit execution natively
    code, real_out = execute_in_container(["patch", "--fuzz=0", "-p1", "-i", payload.patch_file])
    if code != 0:
         raise HTTPException(status_code=500, detail=f"Patch modification runtime error: {real_out}")

    _, verified_hunk = execute_in_container(["git", "diff", payload.target_file])
    return {"status": "success", "patch_output": real_out, "verified_hunk": verified_hunk}

@app.post("/git-commit")
def run_git_commit(payload: CommitPayload, token: str = Depends(verify_token)):
    """Stages specific listed files exclusively to prevent cross-agent context bleeding."""
    if not payload.files:
        raise HTTPException(status_code=400, detail="Explicit list of tracking files required. Global scoping blocked.")
    
    # Stage ONLY the defined array paths explicitly
    for target_file in payload.files:
        code, err = execute_in_container(["git", "add", target_file])
        if code != 0:
            raise HTTPException(status_code=400, detail=f"Failed to stage tracking asset [{target_file}]: {err}")
            
    # Run commit exclusively targeting the staged queue matrix components explicitly
    cmd = ["git", "commit", "-m", payload.message] + payload.files
    code, out = execute_in_container(cmd)
    return {"status": "success", "commit_output": out}

# --- SYSTEM READ & NAVIGATION UTILITIES ---

@app.get("/status")
def run_status(project: str = ".", token: str = Depends(verify_token)):
    """Provides git tree metadata and files, scoped explicitly to an individual project folder."""
    # Ensure the execution path dynamically steps inside the specific subproject folder
    target_dir = f"/home/agent/workspace/{project}".rstrip("/")
    
    _, branch_out = execute_in_container(["git", "-C", target_dir, "branch", "--show-current"])
    _, git_stat_out = execute_in_container(["git", "-C", target_dir, "status", "--short"])
    
    # Restrict file searches exclusively to this specific project directory
    _, raw_files = execute_in_container(["find", project, "-type", "f", "-not", "-path", "*/.*"])
    
    file_map = []
    if "fatal" not in branch_out:
        for f_line in raw_files.strip().split("\n"):
            if not f_line: continue
            clean_p = f_line.lstrip("./")
            _, size_out = execute_in_container(["wc", "-c", clean_p])
            try:
                size_bytes = int(size_out.strip().split()[0])
                file_map.append({"path": clean_p, "size_bytes": size_bytes, "context_safe": size_bytes < CHUNK_SIZE_BYTES})
            except:
                pass
                
    return {
        "scoped_project": project,
        "current_branch": branch_out.strip() if "fatal" not in branch_out else "Not a Git Repository Root",
        "uncommitted_changes": [line.strip() for line in git_stat_out.strip().split("\n") if line] if "fatal" not in git_stat_out else [],
        "workspace_files": file_map
    }


@app.get("/output-chunk")
def get_chunk(session_id: str, chunk_index: int, token: str = Depends(verify_token)):
    with sqlite3.connect(DB_FILE) as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT chunk_data, total_chunks FROM output_chunks WHERE session_id = ? AND chunk_index = ?", (session_id, chunk_index))
        row = cursor.fetchone()
        
    if not row:
        raise HTTPException(status_code=404, detail="Requested chunk record sequence not found in persistent store database.")
        
    chunk_data, total_chunks = row
    has_more = chunk_index < (total_chunks - 1)
    
    response_data = {
        "status": "success",
        "has_more": has_more,
        "session_id": session_id if has_more else None,
        "next_chunk_index": chunk_index + 1 if has_more else None,
        "total_chunks": total_chunks,
        "chunk_size_bytes": CHUNK_SIZE_BYTES,
        "data": chunk_data
    }
    
    if not has_more:
        with sqlite3.connect(DB_FILE) as conn:
            conn.cursor().execute("DELETE FROM output_chunks WHERE session_id = ?", (session_id,))
            conn.commit()
            
    return response_data

@app.get("/cat")
def run_cat(path: str, token: str = Depends(verify_token)):
    code, out = execute_in_container(["cat", path])
    if code != 0: raise HTTPException(status_code=500, detail=out)
    return paginate_output(out)

@app.get("/grep")
def run_grep(pattern: str, path: str = ".", token: str = Depends(verify_token)):
    code, out = execute_in_container(["grep", "-rn", pattern, path]); return paginate_output(out)

@app.get("/run-command")
def run_custom_command(cmd: str, timeout: int = 45, token: str = Depends(verify_token)):
    """Executes arbitrary tasks, including git branch switches or authenticated 'gh pr create' scripts."""
    code, out = execute_in_container(cmd.split(), timeout=timeout); return paginate_output(out)

if __name__ == "__main__":
    import uvicorn
    # Change host from 127.0.0.1 to 0.0.0.0 so Nginx can reach it internally
    uvicorn.run(app, host="0.0.0.0", port=8000)

