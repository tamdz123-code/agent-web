<?php
/**
 * AI Agent API — cầu nối giữa website và GitHub Actions sandbox.
 *
 * Các action:
 *   health         : kiểm tra cấu hình (PAT, repo, LLM)
 *   chat           : chat thường với LLM (SSE streaming) + phát hiện yêu cầu tạo session
 *   create_session : tạo session mới (nhánh session/<id> + kích hoạt Actions)
 *   ask_session    : gửi yêu cầu tiếp theo cho session đang có
 *   session_state  : đọc state.json (tiến độ) của session
 *   list_sessions  : danh sách session
 *   delete_session : xóa session (hủy run, xóa run + log, xóa nhánh + dữ liệu)
 *   admin_list     : danh sách toàn bộ session (cần admin key)
 *
 * Mọi request phải kèm header X-Auth-Token (mật khẩu user hoặc admin key).
 */
error_reporting(0);
@ini_set('display_errors', '0');

$config = require __DIR__ . '/config.php';

/* ---------------------------------------------------------------- tiện ích */

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_body() {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function auth_required($admin = false) {
    global $config;
    $token = isset($_SERVER['HTTP_X_AUTH_TOKEN']) ? $_SERVER['HTTP_X_AUTH_TOKEN'] : '';
    if ($token === '' && isset($_GET['auth'])) $token = $_GET['auth'];
    if ($token === '') json_out(['ok' => false, 'error' => 'Chưa đăng nhập'], 401);
    if (!hash_equals($config['user_password'], $token)
        && !hash_equals($config['admin_key'], $token)) {
        json_out(['ok' => false, 'error' => 'Sai mật khẩu'], 401);
    }
    if ($admin && !hash_equals($config['admin_key'], $token)) {
        json_out(['ok' => false, 'error' => 'Cần admin key cho thao tác này'], 403);
    }
}

function gh($method, $path, $body = null) {
    global $config;
    $ch = curl_init('https://api.github.com' . $path);
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $config['github_pat'],
        'User-Agent: ai-agent-web',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($res === false) return ['code' => 0, 'data' => ['message' => 'Lỗi kết nối GitHub: ' . $err]];
    $data = json_decode($res, true);
    return ['code' => $code, 'data' => is_array($data) ? $data : ['raw' => $res]];
}

function repo_api($path) {
    global $repo;
    return '/repos/' . $repo . $path;
}

/* -------- index session lưu tại hosting (danh sách nhanh cho sidebar) ---- */

function load_sessions() {
    $f = __DIR__ . '/data/sessions.json';
    if (!is_file($f)) return [];
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : [];
}

function save_sessions($sessions) {
    if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0755, true);
    file_put_contents(__DIR__ . '/data/sessions.json',
        json_encode(array_values($sessions), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function find_session_index(&$sessions, $id) {
    foreach ($sessions as $k => $s) {
        if (isset($s['id']) && $s['id'] === $id) return $k;
    }
    return null;
}

function session_branch($id) { return 'session/' . $id; }

function clean_id($raw) {
    $id = preg_replace('/[^a-f0-9\-]/i', '', (string)$raw);
    return (strlen($id) >= 8 && strlen($id) <= 40) ? $id : '';
}

/* ------------------------------------------------------------------ chạy */

$repo   = $config['github_repo'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {

/* ---------------------------------------------------------- health */
case 'health':
    auth_required();
    $out = ['ok' => true, 'checks' => []];
    $r = gh('GET', repo_api(''));
    if ($r['code'] === 200) {
        $out['checks']['github'] = 'OK — repo ' . $r['data']['full_name']
            . ($r['data']['private'] ? ' (private ✔)' : ' (⚠ ĐANG PUBLIC — nên để private!)');
    } else {
        $out['checks']['github'] = 'LỖI ' . $r['code'] . ': ' . (isset($r['data']['message']) ? $r['data']['message'] : '?');
        $out['ok'] = false;
    }
    $out['checks']['llm'] = !empty($config['llm_api_key']) && strpos($config['llm_api_key'], 'VAO_DAY') === false
        ? 'Đã cấu hình (model ' . $config['llm_model'] . ' @ ' . $config['llm_base_url'] . ')'
        : 'Chưa điền llm_api_key trong config.php';
    $w = gh('GET', repo_api('/actions/workflows'));
    $out['checks']['workflow'] = ($w['code'] === 200 && !empty($w['data']['workflows']))
        ? 'OK — ' . count($w['data']['workflows']) . ' workflow'
        : 'Không thấy workflow (file .github/workflows/session.yml đã upload chưa?)';
    json_out($out);

/* ------------------------------------------------------- chat (SSE) */
case 'chat':
    auth_required();
    $body = read_body();
    $in = isset($body['messages']) && is_array($body['messages']) ? $body['messages'] : [];
    $messages = [];
    foreach ($in as $m) {
        $role    = (isset($m['role']) && $m['role'] === 'assistant') ? 'assistant' : 'user';
        $content = isset($m['content']) ? (string)$m['content'] : '';
        if ($content !== '') $messages[] = ['role' => $role, 'content' => mb_substr($content, 0, 8000)];
    }
    if (!$messages) json_out(['ok' => false, 'error' => 'Không có nội dung'], 400);

    $system = "Bạn là trợ lý AI trên web của người dùng, trả lời bằng tiếng Việt (trừ khi người dùng dùng ngôn ngữ khác).\n"
        . "Trả lời trực tiếp các câu hỏi thông thường một cách ngắn gọn, hữu ích.\n\n"
        . "Bạn có một sandbox Linux (GitHub Actions) để chạy code / phân tích dữ liệu / làm việc nhiều bước.\n"
        . "CHỈ KHI người dùng cần chạy code thật, xử lý file/dữ liệu, hoặc công việc nhiều bước phức tạp: "
        . "trả lời ngắn gọn rằng bạn sẽ tạo môi trường chạy, rồi kết thúc câu trả lời bằng MỘT dòng đúng định dạng:\n"
        . "<<TOOL:create_session>> {\"task\":\"Mô tả chi tiết nhiệm vụ bằng tiếng Việt để một agent tự chủ thực hiện độc lập: mục tiêu, các bước gợi ý, định dạng kết quả mong muốn\"}\n"
        . "Không dùng tool cho câu hỏi thường thức. Đừng lặp dòng tool hai lần.";

    array_unshift($messages, ['role' => 'system', 'content' => $system]);

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    @set_time_limit(180);
    while (ob_get_level() > 0) { @ob_end_flush(); }

    $text = ''; // toàn bộ câu trả lời của LLM (để dò tool)

    $ch = curl_init(rtrim($config['llm_base_url'], '/') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['llm_api_key'],
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => $config['llm_model'],
            'messages'    => $messages,
            'stream'      => true,
            'temperature' => 0.4,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 150,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$text) {
            foreach (explode("\n", $chunk) as $line) {
                $line = trim($line);
                if (strncmp($line, 'data:', 5) !== 0) continue;
                $payload = trim(substr($line, 5));
                if ($payload === '' || $payload === '[DONE]') continue;
                $j = json_decode($payload, true);
                if (!is_array($j) || !isset($j['choices'][0]['delta']['content'])) continue;
                $delta = (string)$j['choices'][0]['delta']['content'];
                if ($delta !== '') {
                    $text .= $delta;
                    echo 'data: ' . json_encode(['type' => 'delta', 'text' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n";
                }
            }
            flush();
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($text === '') {
        $msg = 'LLM không trả về nội dung';
        if ($curl_err !== '')   $msg .= ' — lỗi kết nối: ' . $curl_err;
        elseif ($http >= 400)   $msg .= ' — HTTP ' . $http . ' (kiểm tra llm_api_key / llm_model trong config.php)';
        echo 'data: ' . json_encode(['type' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    // dò yêu cầu tạo session trong câu trả lời
    if (preg_match('/<<TOOL:create_session>>\s*(\{.+\})/s', $text, $mm)) {
        $args = json_decode($mm[1], true);
        $task = is_array($args) && isset($args['task']) ? (string)$args['task'] : '';
        if ($task !== '') {
            echo 'data: ' . json_encode(['type' => 'tool', 'name' => 'create_session', 'task' => mb_substr($task, 0, 60000)], JSON_UNESCAPED_UNICODE) . "\n\n";
        }
    }

    echo 'data: ' . json_encode(['type' => 'done'], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
    exit;

/* --------------------------------------------------- tạo session mới */
case 'create_session':
    auth_required();
    $body    = read_body();
    $task    = trim(isset($body['task']) ? (string)$body['task'] : '');
    $history = isset($body['history']) ? (string)$body['history'] : '';
    if ($task === '') json_out(['ok' => false, 'error' => 'Thiếu task'], 400);
    $task    = mb_substr($task, 0, 60000);
    $history = mb_substr($history, 0, 20000);

    $sessions = load_sessions();
    if (count($sessions) >= (int)$config['max_sessions']) {
        json_out(['ok' => false, 'error' => 'Đã đạt giới hạn ' . $config['max_sessions'] . ' session. Hãy xóa bớt session cũ.'], 400);
    }

    // sinh UUID v4
    $d   = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    $hex = bin2hex($d);
    $id  = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    $branch = session_branch($id);

    // 1) tạo nhánh session từ nhánh chính
    $main = gh('GET', repo_api('/git/ref/heads/' . rawurlencode($config['main_branch'])));
    if ($main['code'] !== 200) {
        json_out(['ok' => false, 'error' => 'Không đọc được nhánh "' . $config['main_branch'] . '": '
            . (isset($main['data']['message']) ? $main['data']['message'] : 'lỗi không rõ')], 502);
    }
    $sha = $main['data']['object']['sha'];
    $cr  = gh('POST', repo_api('/git/refs'), ['ref' => 'refs/heads/' . $branch, 'sha' => $sha]);
    if ($cr['code'] !== 201) {
        json_out(['ok' => false, 'error' => 'Không tạo được nhánh session: '
            . (isset($cr['data']['message']) ? $cr['data']['message'] : 'lỗi không rõ')], 502);
    }

    // 2) kích hoạt workflow trên nhánh session
    $disp = gh('POST', repo_api('/actions/workflows/' . rawurlencode($config['workflow_file']) . '/dispatches'), [
        'ref'    => $branch,
        'inputs' => [
            'session_id'   => $id,
            'task'         => $task,
            'history'      => $history,
            'mode'         => 'new',
            'question'     => '',
            'llm_base_url' => rtrim($config['llm_base_url'], '/'),
            'llm_model'    => $config['llm_model'],
        ],
    ]);
    if ($disp['code'] !== 204) {
        gh('DELETE', repo_api('/git/refs/' . rawurlencode('heads/' . $branch))); // dọn nhánh lỗi
        $msg = isset($disp['data']['message']) ? $disp['data']['message'] : json_encode($disp['data']);
        json_out(['ok' => false, 'error' => 'Không kích hoạt được Actions (kiểm tra PAT có quyền Actions Write + file workflow đã tồn tại): ' . $msg], 502);
    }

    $entry = [
        'id'         => $id,
        'title'      => mb_substr($task, 0, 90),
        'status'     => 'running',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ];
    array_unshift($sessions, $entry);
    save_sessions($sessions);
    json_out(['ok' => true, 'session' => $entry]);

/* --------------------------------------------- hỏi/tiếp tục session */
case 'ask_session':
    auth_required();
    $body     = read_body();
    $id       = clean_id(isset($body['session_id']) ? $body['session_id'] : '');
    $question = trim(isset($body['question']) ? (string)$body['question'] : '');
    if ($id === '')  json_out(['ok' => false, 'error' => 'session_id không hợp lệ'], 400);
    if ($question === '') json_out(['ok' => false, 'error' => 'Thiếu câu hỏi'], 400);

    $sessions = load_sessions();
    $k = find_session_index($sessions, $id);
    if ($k === null) json_out(['ok' => false, 'error' => 'Không tìm thấy session'], 404);
    $task = isset($sessions[$k]['title']) ? $sessions[$k]['title'] : '';

    // nếu state thật còn trong repo thì lấy task gốc từ đó (chính xác hơn)
    $branch = session_branch($id);
    $st = gh('GET', repo_api('/contents/sessions/' . $id . '/state.json?ref=' . rawurlencode($branch)));
    if ($st['code'] === 200) {
        $state = json_decode((string)base64_decode(str_replace("\n", '', isset($st['data']['content']) ? $st['data']['content'] : '')), true);
        if (is_array($state) && !empty($state['task'])) $task = (string)$state['task'];
    }

    $disp = gh('POST', repo_api('/actions/workflows/' . rawurlencode($config['workflow_file']) . '/dispatches'), [
        'ref'    => $branch,
        'inputs' => [
            'session_id'   => $id,
            'task'         => mb_substr($task, 0, 60000),
            'history'      => mb_substr(trim(isset($body['history']) ? (string)$body['history'] : ''), 0, 20000),
            'mode'         => 'ask',
            'question'     => mb_substr($question, 0, 20000),
            'llm_base_url' => rtrim($config['llm_base_url'], '/'),
            'llm_model'    => $config['llm_model'],
        ],
    ]);
    if ($disp['code'] !== 204) {
        $msg = isset($disp['data']['message']) ? $disp['data']['message'] : 'lỗi không rõ';
        json_out(['ok' => false, 'error' => 'Không kích hoạt được Actions: ' . $msg], 502);
    }

    $sessions[$k]['status']     = 'running';
    $sessions[$k]['updated_at'] = date('c');
    save_sessions($sessions);
    json_out(['ok' => true]);

/* ------------------------------------------------- đọc state session */
case 'session_state':
    auth_required();
    if (!isset($_GET['id'])) $_GET['id'] = read_body_id();
    $id = clean_id($_GET['id']);
    if ($id === '') json_out(['ok' => false, 'error' => 'id không hợp lệ'], 400);
    $branch = session_branch($id);

    $r = gh('GET', repo_api('/contents/sessions/' . $id . '/state.json?ref=' . rawurlencode($branch)));
    if ($r['code'] !== 200) {
        json_out(['ok' => true, 'state' => [
            'session_id' => $id,
            'status'     => 'pending',
            'message'    => 'Đang chờ Actions khởi động (thường 20–60 giây)...',
        ]]);
    }
    $content = isset($r['data']['content']) ? (string)$r['data']['content'] : '';
    $state   = json_decode((string)base64_decode(str_replace("\n", '', $content)), true);
    if (!is_array($state)) {
        json_out(['ok' => true, 'state' => ['session_id' => $id, 'status' => 'pending', 'message' => 'Đang chờ dữ liệu...']]);
    }

    // đồng bộ status về index local
    $sessions = load_sessions();
    $k = find_session_index($sessions, $id);
    if ($k !== null) {
        $sessions[$k]['status']     = isset($state['status']) ? $state['status'] : 'running';
        $sessions[$k]['updated_at'] = date('c');
        save_sessions($sessions);
    }
    json_out(['ok' => true, 'state' => $state]);

/* --------------------------------------------------- danh sách session */
case 'list_sessions':
case 'admin_list':
    if ($action === 'admin_list') auth_required(true); else auth_required();
    json_out(['ok' => true, 'sessions' => load_sessions(), 'max' => (int)$config['max_sessions']]);

/* ---------------------------------------------------- xóa session */
case 'delete_session':
    auth_required();
    $body = read_body();
    $id   = clean_id(isset($body['session_id']) ? $body['session_id'] : '');
    if ($id === '') json_out(['ok' => false, 'error' => 'session_id không hợp lệ'], 400);
    $branch = session_branch($id);

    // 1) hủy + xóa mọi Actions run của session (lọc theo nhánh session)
    $cancelled = 0; $deleted = 0; $pending = 0;
    $runs = gh('GET', repo_api('/actions/runs?branch=' . rawurlencode($branch) . '&per_page=100'));
    if ($runs['code'] === 200 && !empty($runs['data']['workflow_runs'])) {
        $ids = [];
        $need_cancel = [];
        foreach ($runs['data']['workflow_runs'] as $run) {
            $ids[] = (int)$run['id'];
            $st = isset($run['status']) ? $run['status'] : '';
            if ($st === 'in_progress' || $st === 'queued' || $st === 'waiting') $need_cancel[] = (int)$run['id'];
        }
        foreach ($need_cancel as $rid) {
            $c = gh('POST', repo_api('/actions/runs/' . $rid . '/cancel'));
            if ($c['code'] === 202) $cancelled++;
        }
        if ($cancelled > 0) usleep(2000000); // cho cancel có hiệu lực

        foreach ($ids as $rid) {
            $d = gh('DELETE', repo_api('/actions/runs/' . $rid));
            if ($d['code'] === 204) $deleted++; else $pending++;
        }
    }

    // 2) xóa nhánh session => toàn bộ dữ liệu (state, files) biến mất
    $del = gh('DELETE', repo_api('/git/refs/' . rawurlencode('heads/' . $branch)));
    $branch_ok = ($del['code'] === 204);

    // 3) xóa khỏi index local
    $sessions = load_sessions();
    $k = find_session_index($sessions, $id);
    if ($k !== null) { array_splice($sessions, $k, 1); save_sessions($sessions); }

    json_out([
        'ok'             => true,
        'branch_deleted' => $branch_ok,
        'runs_cancelled' => $cancelled,
        'runs_deleted'   => $deleted,
        'runs_pending'   => $pending,
        'note'           => $pending > 0 ? 'Vài run đang chờ hủy — bấm Xóa lần nữa để dọn nốt.' : '',
    ]);

/* ------------------------------------------------------------ mặc định */
default:
    json_out(['ok' => false, 'error' => 'Action không hợp lệ'], 400);
}

/* helper cho session_state (nhận id từ GET hoặc body) */
function read_body_id() {
    $b = read_body();
    return isset($b['session_id']) ? $b['session_id'] : (isset($b['id']) ? $b['id'] : '');
}
