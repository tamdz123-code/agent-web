#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Agent tự chủ chạy bên trong GitHub Actions sandbox.

Vòng lặp: suy nghĩ (gọi LLM) -> hành động (chạy lệnh / ghi file)
-> ghi tiến độ vào sessions/<id>/state.json -> commit lên nhánh session.

state.json là "bộ nhớ" chung:
  - website đọc file này để hiển thị tiến độ cho người dùng
  - các run sau của cùng session đọc lại để tiếp tục, không làm lại việc cũ
"""
import json
import os
import re
import subprocess
import time
import urllib.request

# ------------------------------------------------------------------ cấu hình

SESSION_ID = os.environ.get('SESSION_ID', '')
TASK = os.environ.get('TASK', '').strip()
HISTORY = os.environ.get('HISTORY', '').strip()
MODE = os.environ.get('MODE', 'new')          # new = session mới, ask = tiếp tục
QUESTION = os.environ.get('QUESTION', '').strip()
LLM_BASE_URL = os.environ.get('LLM_BASE_URL', 'https://api.openai.com/v1').rstrip('/')
LLM_MODEL = os.environ.get('LLM_MODEL', 'gpt-4o-mini')
LLM_API_KEY = os.environ.get('LLM_API_KEY', '')

TIME_BUDGET = float(os.environ.get('TIME_BUDGET', '420'))   # giây, dành ~3' cuối để ghi state
MAX_STEPS = int(os.environ.get('MAX_STEPS', '15'))
CMD_TIMEOUT = int(os.environ.get('CMD_TIMEOUT', '30'))
OUTPUT_LIMIT = 4000            # ký tự output giữ lại trong state mỗi bước
MEMORY_LIMIT = 3000            # ký tự kết quả gửi lại cho LLM mỗi bước

SESSION_DIR = os.path.join('sessions', SESSION_ID)
FILES_DIR = os.path.join(SESSION_DIR, 'files')
STATE_FILE = os.path.join(SESSION_DIR, 'state.json')

BLOCKED = ['rm -rf /', 'mkfs', 'shutdown', 'reboot', 'dd if=', ':(){', 'chmod -R 777 /']

SYSTEM_PROMPT = """Bạn là agent tự chủ chạy trong sandbox Linux (GitHub Actions, ubuntu-latest, ~2 CPU, 7GB RAM).
Có sẵn: python3, pip, node, npm, git, curl, jq. Có thể pip install (nhanh), tránh apt trừ khi thật cần.

Nhiệm vụ được cung cấp bên dưới. Làm việc TỪNG BƯỚC. Mỗi lượt trả về DUY NHẤT một JSON object
(không markdown fence, không chữ nào ngoài JSON) theo một trong ba dạng:
{"thought": "suy nghĩ ngắn về bước này", "action": "run", "command": "lệnh shell cần chạy"}
{"thought": "...", "action": "write", "path": "ten_file.py", "content": "nội dung file"}
{"thought": "...", "action": "finish", "answer": "câu trả lời cuối cùng đầy đủ cho người dùng, dùng markdown"}

Quy tắc:
- Mỗi lệnh shell tối đa 30 giây. Việc dài giờ hãy chia nhỏ hoặc viết thành script rồi chạy.
- Ưu tiên write file code rồi run, thay vì nhồi code vào một lệnh.
- Nếu có danh sách các bước đã làm trước đó: KHÔNG làm lại việc đã xong.
- Khi đã đủ thông tin để trả lời -> dùng action "finish".
- Cấm lệnh phá hệ thống. Mọi file tạo ra nằm trong thư mục làm việc hiện tại."""


# ------------------------------------------------------------------ tiện ích

def now_iso():
    return time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())


def llm_call(messages):
    last_err = None
    for _ in range(3):
        try:
            payload = json.dumps({
                'model': LLM_MODEL,
                'messages': messages,
                'temperature': 0.2,
            }, ensure_ascii=False).encode('utf-8')
            req = urllib.request.Request(
                LLM_BASE_URL + '/chat/completions',
                data=payload,
                headers={
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + LLM_API_KEY,
                },
            )
            with urllib.request.urlopen(req, timeout=120) as r:
                data = json.loads(r.read().decode('utf-8'))
            return data['choices'][0]['message']['content']
        except Exception as e:  # noqa: BLE001
            last_err = e
            time.sleep(2)
    raise last_err


def parse_json(text):
    t = (text or '').strip()
    t = re.sub(r'^```(?:json)?\s*', '', t)
    t = re.sub(r'\s*```$', '', t)
    a, b = t.find('{'), t.rfind('}')
    if a >= 0 and b > a:
        t = t[a:b + 1]
    return json.loads(t)


def safe_path(name):
    name = (name or 'file.txt').replace('\\', '/')
    parts = [p for p in name.split('/') if p not in ('', '.', '..')]
    if not parts:
        return os.path.join(FILES_DIR, 'file.txt')
    return os.path.join(FILES_DIR, *parts)


def run_cmd(cmd):
    low = cmd.lower()
    for b in BLOCKED:
        if b in low:
            return '[BỊ CHẶN] Lệnh không được phép vì lý do an toàn.'
    try:
        r = subprocess.run(
            cmd, shell=True, capture_output=True, text=True,
            timeout=CMD_TIMEOUT, cwd=FILES_DIR,
        )
        out = (r.stdout or '')
        if r.stderr:
            out += '\n[stderr]\n' + r.stderr
        return (out or '[không có output]').strip()
    except subprocess.TimeoutExpired:
        return '[HẾT THỜI GIAN] Lệnh chạy quá %ds và đã bị dừng.' % CMD_TIMEOUT
    except Exception as e:  # noqa: BLE001
        return '[LỖI] %s' % e


def list_files():
    fs = []
    for root, _dirs, names in os.walk(FILES_DIR):
        for n in names:
            if n == '.gitkeep':
                continue
            rel = os.path.relpath(os.path.join(root, n), FILES_DIR).replace('\\', '/')
            fs.append(rel)
    return sorted(fs)[:100]


def git(*args):
    return subprocess.run(['git'] + list(args), capture_output=True, text=True)


def commit_state():
    state['updated_at'] = now_iso()
    try:
        os.makedirs(SESSION_DIR, exist_ok=True)
        with open(STATE_FILE, 'w', encoding='utf-8') as f:
            json.dump(state, f, ensure_ascii=False, indent=1)
        git('add', 'sessions')
        git('-c', 'user.name=agent-bot',
            '-c', 'user.email=agent-bot@users.noreply.github.com',
            'commit', '-m', 'session %s: %s, buoc %d' % (SESSION_ID, state['status'], len(state['steps'])),
            '--no-verify')
        git('push', 'origin', 'HEAD')
    except Exception as e:  # noqa: BLE001
        print('commit error:', e)


# ------------------------------------------------------------------ trạng thái

state = {
    'session_id': SESSION_ID,
    'title': TASK[:90],
    'task': TASK,
    'status': 'running',
    'created_at': now_iso(),
    'updated_at': now_iso(),
    'question': QUESTION,
    'steps': [],
    'files': [],
    'final_answer': None,
    'error': None,
}

if os.path.isfile(STATE_FILE):
    try:
        with open(STATE_FILE, encoding='utf-8') as f:
            prev = json.load(f)
        steps = prev.get('steps', [])
        state.update(prev)
        state['steps'] = steps
        state['status'] = 'running'
        state['question'] = QUESTION or prev.get('question', '')
        state['error'] = None
    except Exception as e:  # noqa: BLE001
        print('không đọc được state cũ:', e)

os.makedirs(FILES_DIR, exist_ok=True)

# ------------------------------------------------------------------ hội thoại

messages = [{'role': 'system', 'content': SYSTEM_PROMPT}]

if state['steps']:
    lines = []
    for s in state['steps'][-20:]:
        out = (s.get('output') or '')[:600]
        lines.append('Bước %s [%s]: %s\n  làm: %s\n  kết quả: %s' % (
            s.get('n'), s.get('kind'), s.get('thought', ''), s.get('detail', ''), out))
    resume = 'Các bước ĐÃ LÀM trước đó (tuyệt đối không làm lại):\n' + '\n'.join(lines)
    resume += '\nFile hiện có: ' + (', '.join(list_files()) or '(không có)')
else:
    resume = ''

if MODE == 'ask' and QUESTION:
    user_msg = (
        'NHIỆM VỤ GỐC: %s\n\n'
        'NGỮ CẢNH hội thoại với người dùng: %s\n\n'
        '%s\n\n'
        'YÊU CẦU MỚI của người dùng: %s\n'
        'Hãy tiếp tục công việc dựa trên những gì đã có rồi trả lời/hoàn thành yêu cầu mới.'
        % (TASK, HISTORY, resume, QUESTION)
    )
else:
    user_msg = (
        'NHIỆM VỤ: %s\n\n'
        'NGỮ CẢNH hội thoại với người dùng: %s\n\n'
        '%s\n'
        'Bắt đầu thực hiện.' % (TASK, HISTORY, resume)
    )

messages.append({'role': 'user', 'content': user_msg})

# ------------------------------------------------------------------ vòng lặp chính

print('=== Session %s | mode=%s | task=%s ===' % (SESSION_ID, MODE, TASK[:120]))
commit_state()  # ghi state "running" ngay để web thấy session đã bắt đầu
started = time.time()

try:
    while len(state['steps']) < MAX_STEPS and (time.time() - started) < TIME_BUDGET:
        raw = llm_call(messages)
        print('LLM:', raw[:300])

        try:
            step = parse_json(raw)
        except Exception:  # noqa: BLE001
            messages.append({'role': 'assistant', 'content': raw[:2000]})
            messages.append({'role': 'user', 'content':
                             'Định dạng sai. Chỉ trả về MỘT JSON hợp lệ đúng một trong ba dạng đã mô tả.'})
            continue

        action = str(step.get('action', '')).lower().strip()
        thought = str(step.get('thought', ''))[:500]
        n = len(state['steps']) + 1

        if action == 'run':
            cmd = str(step.get('command', ''))
            out = run_cmd(cmd)
            state['steps'].append({
                'n': n, 'time': now_iso(), 'thought': thought, 'kind': 'run',
                'detail': cmd[:500], 'output': out[:OUTPUT_LIMIT],
            })
            messages.append({'role': 'assistant', 'content': json.dumps(step, ensure_ascii=False)[:1500]})
            messages.append({'role': 'user', 'content': 'KẾT QUẢ lệnh (có thể bị cắt ngắn):\n' + out[:MEMORY_LIMIT]})

        elif action == 'write':
            path = safe_path(str(step.get('path', '')))
            content = str(step.get('content', ''))
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with open(path, 'w', encoding='utf-8') as f:
                f.write(content)
            state['steps'].append({
                'n': n, 'time': now_iso(), 'thought': thought, 'kind': 'write',
                'detail': os.path.relpath(path, FILES_DIR), 'output': '[đã ghi file, %d ký tự]' % len(content),
            })
            messages.append({'role': 'assistant', 'content': json.dumps(step, ensure_ascii=False)[:1500]})
            messages.append({'role': 'user', 'content': 'Đã ghi file %s. Ghi nhận.' % path})

        elif action in ('finish', 'answer'):
            state['final_answer'] = str(step.get('answer', ''))
            state['status'] = 'done'
            state['files'] = list_files()
            commit_state()
            print('=== HOÀN THÀNH session %s ===' % SESSION_ID)
            break

        else:
            messages.append({'role': 'assistant', 'content': raw[:1000]})
            messages.append({'role': 'user', 'content':
                             'action không hợp lệ ("%s"). Dùng run / write / finish.' % action})
            continue

        state['files'] = list_files()
        commit_state()

    else:
        # hết bước hoặc hết giờ mà chưa finish -> tạm dừng, có thể tiếp tục bằng câu hỏi mới
        if state['status'] == 'running':
            state['status'] = 'paused'
            state['error'] = 'Run này đã dùng hết thời lượng/bước. Gửi yêu cầu tiếp để agent làm tiếp phần còn lại.'
            commit_state()

except Exception as e:  # noqa: BLE001
    state['status'] = 'error'
    state['error'] = str(e)[:500]
    commit_state()
    raise
