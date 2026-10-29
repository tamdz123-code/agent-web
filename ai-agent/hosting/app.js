/* AI Agent — logic trình duyệt
 * Trò chuyện thường   : stream qua api.php?action=chat (SSE)
 * Tạo session sandbox : tự động khi LLM phát <<TOOL:create_session>>
 * Theo dõi session    : poll api.php?action=session_state mỗi 3 giây
 */
(function () {
  'use strict';

  var API = 'api.php';
  var token = localStorage.getItem('agent_token') || '';
  var adminToken = '';

  var chatHistory = [];      // [{role, content}] gửi cho LLM
  var sessions = [];         // index session từ server
  var currentSessionId = null;
  var pollTimer = null;
  var chatBusy = false;

  /* ------------------------------------------------ tiện ích DOM */

  function $(id) { return document.getElementById(id); }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* markdown rút gọn: code block, code inline, đậm, nghiêng, heading, bullet */
  function md(src) {
    var s = esc(src);
    s = s.replace(/```(\w*)\n?([\s\S]*?)```/g, function (m, lang, code) {
      return '<pre><code>' + code.replace(/\n$/, '') + '</code></pre>';
    });
    s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
    s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    s = s.replace(/^#### (.*)$/gm, '<h4>$1</h4>');
    s = s.replace(/^### (.*)$/gm, '<h4>$1</h4>');
    s = s.replace(/^## (.*)$/gm, '<h3>$1</h3>');
    s = s.replace(/^# (.*)$/gm, '<h2>$1</h2>');
    s = s.replace(/^[-*] (.*)$/gm, '• $1');
    s = s.replace(/<\/(h2|h3|h4|pre)>\n/g, '</$1>');
    s = s.replace(/\n/g, '<br>');
    return s;
  }

  function toast(msg, isErr) {
    var t = $('toast');
    t.textContent = msg;
    t.className = isErr ? 'err' : '';
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.className = 'hidden'; }, 3600);
  }

  /* ------------------------------------------------ gọi API */

  function api(action, opts) {
    opts = opts || {};
    return fetch(API + '?action=' + encodeURIComponent(action), {
      method: opts.method || 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Auth-Token': opts.admin ? adminToken : token,
      },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || data.ok === false) {
          throw new Error(data.error || ('Lỗi HTTP ' + res.status));
        }
        return data;
      });
    });
  }

  /* ------------------------------------------------ giao diện chính */

  function show(view) {
    $('view-login').classList.toggle('hidden', view !== 'login');
    $('app').classList.toggle('hidden', view === 'login');
    $('view-chat').classList.toggle('hidden', view !== 'chat');
    $('view-session').classList.toggle('hidden', view !== 'session');
    if (view !== 'session') stopPolling();
  }

  function startApp() {
    show('chat');
    refreshSessions();
    if (!chatHistory.length) {
      addMsg('assistant', 'Xin chào 👋 Tôi có thể trả lời trực tiếp, hoặc tạo **session sandbox** (môi trường Linux trên GitHub Actions) khi bạn cần chạy code thật, phân tích dữ liệu, hay làm việc nhiều bước.\n\nThử: `viết chương trình python vẽ bida bằng matplotlib rồi lưu ảnh`');
    }
  }

  /* ------------------------------------------------ đăng nhập */

  $('loginBtn').addEventListener('click', doLogin);
  $('loginPassword').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') doLogin();
  });
  function doLogin() {
    var pass = $('loginPassword').value.trim();
    if (!pass) return;
    localStorage.setItem('agent_token', pass);
    token = pass;
    api('health').then(function (r) {
      var g = r.checks.github || '';
      if (r.ok === false || g.indexOf('LỖI') === 0) {
        localStorage.removeItem('agent_token');
        $('loginError').textContent = 'Cấu hình chưa xong: ' + g;
        return;
      }
      startApp();
    }).catch(function (e) {
      localStorage.removeItem('agent_token');
      $('loginError').textContent = e.message;
    });
  }
  $('logoutBtn').addEventListener('click', function () {
    localStorage.removeItem('agent_token');
    location.reload();
  });

  /* ------------------------------------------------ chat thường */

  $('sendBtn').addEventListener('click', sendChat);
  $('chatInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); }
  });
  $('chatInput').addEventListener('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 140) + 'px';
  });

  function addMsg(role, text) {
    var div = el('div', 'msg ' + role);
    if (role === 'assistant') div.innerHTML = md(text);
    else if (role === 'user') div.textContent = text;
    else div.textContent = text;
    $('chatLog').appendChild(div);
    $('chatLog').scrollTop = $('chatLog').scrollHeight;
    return div;
  }

  function historySummary() {
    // tóm tắt hội thoại gần nhất gửi kèm khi tạo session
    var parts = [];
    var from = Math.max(0, chatHistory.length - 6);
    for (var i = from; i < chatHistory.length; i++) {
      var m = chatHistory[i];
      var who = m.role === 'user' ? 'Người dùng' : 'Trợ lý';
      parts.push(who + ': ' + String(m.content).slice(0, 500));
    }
    return parts.join('\n').slice(0, 2000);
  }

  function sendChat() {
    if (chatBusy) return;
    var input = $('chatInput');
    var text = input.value.trim();
    if (!text) return;
    input.value = '';
    input.style.height = 'auto';
    show('chat');

    addMsg('user', text);
    chatHistory.push({ role: 'user', content: text });

    var bubble = el('div', 'msg assistant cursor-blink');
    $('chatLog').appendChild(bubble);
    $('chatLog').scrollTop = $('chatLog').scrollHeight;

    chatBusy = true;
    $('sendBtn').disabled = true;
    var full = '';
    var toolTask = null;

    fetch(API + '?action=chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Auth-Token': token },
      body: JSON.stringify({ messages: chatHistory.slice(-16) }),
    }).then(function (res) {
      if (!res.ok || !res.body) throw new Error('Không kết nối được server (HTTP ' + res.status + ')');
      var reader = res.body.getReader();
      var dec = new TextDecoder();
      var buf = '';
      function pump() {
        return reader.read().then(function (r) {
          if (r.done) return;
          buf += dec.decode(r.value, { stream: true });
          var idx;
          while ((idx = buf.indexOf('\n\n')) >= 0) {
            var chunk = buf.slice(0, idx);
            buf = buf.slice(idx + 2);
            chunk.split('\n').forEach(function (line) {
              if (line.indexOf('data:') !== 0) return;
              var j;
              try { j = JSON.parse(line.slice(5).trim()); } catch (e) { return; }
              if (j.type === 'delta') {
                full += j.text;
                var cut = full.indexOf('<<TOOL:');
                bubble.innerHTML = md(cut >= 0 ? full.slice(0, cut) : full);
                $('chatLog').scrollTop = $('chatLog').scrollHeight;
              } else if (j.type === 'tool' && j.name === 'create_session') {
                toolTask = j.task;
              } else if (j.type === 'error') {
                toast(j.message, true);
              }
            });
          }
          return pump();
        });
      }
      return pump();
    }).catch(function (e) {
      bubble.classList.remove('cursor-blink');
      bubble.innerHTML += '<br><em style="color:#f2555a">Lỗi: ' + esc(e.message) + '</em>';
    }).then(function () {
      bubble.classList.remove('cursor-blink');
      chatHistory.push({ role: 'assistant', content: full });
      chatBusy = false;
      $('sendBtn').disabled = false;
      if (toolTask) {
        addMsg('system', '🔄 Đang tạo session sandbox trên GitHub Actions...');
        createSession(toolTask);
      }
    });
  }

  /* ------------------------------------------------ session */

  function createSession(task) {
    api('create_session', { body: { task: task, history: historySummary() } })
      .then(function (r) {
        sessions.unshift(r.session);
        renderSessionList();
        toast('✅ Đã tạo session — agent đang khởi động');
        openSession(r.session.id);
      })
      .catch(function (e) { toast('Tạo session lỗi: ' + e.message, true); });
  }

  function refreshSessions() {
    api('list_sessions', { method: 'POST', body: {} })
      .then(function (r) { sessions = r.sessions || []; renderSessionList(); })
      .catch(function () { /* im lặng: có thể chưa cấu hình */ });
  }

  function renderSessionList() {
    var list = $('sessionList');
    list.innerHTML = '';
    $('sessionCount').textContent = sessions.length ? '(' + sessions.length + ')' : '';
    if (!sessions.length) {
      list.appendChild(el('div', 'empty-note', 'Chưa có session nào'));
      return;
    }
    sessions.forEach(function (s) {
      var item = el('div', 'session-item' + (s.id === currentSessionId ? ' active' : ''));
      var title = el('div', 'si-title', s.title || s.id);
      var meta = el('div', 'si-meta');
      meta.appendChild(el('span', 'dot ' + (s.status || 'pending')));
      meta.appendChild(el('span', '', timeAgo(s.updated_at || s.created_at)));
      item.appendChild(title);
      item.appendChild(meta);
      item.addEventListener('click', function () { openSession(s.id); });
      list.appendChild(item);
    });
  }

  function timeAgo(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    if (isNaN(d)) return '';
    var s = Math.floor((Date.now() - d.getTime()) / 1000);
    if (s < 60) return 'vừa xong';
    if (s < 3600) return Math.floor(s / 60) + ' phút trước';
    if (s < 86400) return Math.floor(s / 3600) + ' giờ trước';
    return Math.floor(s / 86400) + ' ngày trước';
  }

  function openSession(id) {
    currentSessionId = id;
    show('session');
    renderSessionList();
    renderPending();
    startPolling();
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  function startPolling() {
    stopPolling();
    pollSession(); // gọi ngay 1 lần
    pollTimer = setInterval(pollSession, 3000);
  }

  function pollSession() {
    if (!currentSessionId) return stopPolling();
    fetch(API + '?action=session_state&id=' + encodeURIComponent(currentSessionId), {
      headers: { 'X-Auth-Token': token },
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data.ok === false) throw new Error(data.error);
      renderSessionState(data.state);
      var st = data.state.status;
      if (st === 'done' || st === 'error' || st === 'paused') stopPolling();
    }).catch(function (e) {
      // lỗi mạng tạm thời -> lần poll sau thử lại; lỗi auth thì dừng
      if (String(e.message).indexOf('mật khẩu') >= 0 || String(e.message).indexOf('đăng nhập') >= 0) {
        stopPolling();
        toast(e.message, true);
      }
    });
  }

  function renderPending() {
    $('sessionHeader').innerHTML = '';
    $('sessionBody').innerHTML = '';
    var inner = el('div', 'session-inner');
    inner.appendChild(el('div', 'empty-note', '⏳ Đang tải trạng thái session...'));
    $('sessionBody').appendChild(inner);
  }

  var STATUS_TEXT = {
    running: 'Đang chạy', done: 'Hoàn thành', error: 'Lỗi',
    paused: 'Tạm dừng', pending: 'Đang chờ',
  };

  function renderSessionState(st) {
    if (!st || st.session_id !== currentSessionId) return;

    // đồng bộ status về index local + sidebar
    var found = sessions.find(function (s) { return s.id === st.session_id; });
    if (found && found.status !== st.status) {
      found.status = st.status;
      found.updated_at = new Date().toISOString();
      renderSessionList();
    }

    /* header */
    var h = $('sessionHeader');
    h.innerHTML = '';
    var title = el('div', 'sh-title');
    title.appendChild(el('div', '', st.title || 'Session'));
    title.appendChild(el('div', 'sh-id', 'ID: ' + st.session_id));
    h.appendChild(title);
    h.appendChild(el('span', 'badge ' + st.status, STATUS_TEXT[st.status] || st.status));
    if (st.status === 'running' || st.status === 'pending') {
      var spin = el('span', 'badge running', 'cập nhật mỗi 3s');
      h.appendChild(spin);
    }
    var delBtn = el('button', 'btn danger small', '🗑 Xóa session');
    delBtn.addEventListener('click', function () { deleteSession(st.session_id); });
    h.appendChild(delBtn);

    /* body */
    var b = $('sessionBody');
    b.innerHTML = '';
    var inner = el('div', 'session-inner');

    if (st.message) { // trạng thái pending
      inner.appendChild(el('div', 'empty-note', st.message));
    }

    (st.steps || []).forEach(function (s) {
      var block = el('div', 'block ' + (s.kind === 'run' ? 'cmd' : 'thought'));
      var head = el('div', 'b-head');
      head.appendChild(el('span', 'step-num', 'BƯỚC ' + (s.n || '?')));
      head.appendChild(el('span', '', s.kind === 'run' ? 'chạy lệnh' : 'ghi file'));
      block.appendChild(head);
      if (s.thought) {
        var t = el('div', '', '');
        t.style.color = 'var(--muted)';
        t.style.fontSize = '13px';
        t.style.marginBottom = '6px';
        t.textContent = '💭 ' + s.thought;
        block.appendChild(t);
      }
      if (s.kind === 'run') {
        var pre = el('pre');
        var code = el('code', null, '$ ' + (s.detail || ''));
        pre.appendChild(code);
        block.appendChild(pre);
      } else {
        var fw = el('div', 'file-chip', '📄 ' + (s.detail || ''));
        block.appendChild(fw);
      }
      if (s.output) {
        var out = el('pre');
        out.textContent = s.output;
        block.appendChild(out);
      }
      inner.appendChild(block);
    });

    if (st.files && st.files.length) {
      var fb = el('div', 'block');
      fb.appendChild(el('div', 'b-label', 'File trong session'));
      var grid = el('div', 'files-grid');
      st.files.forEach(function (f) { grid.appendChild(el('span', 'file-chip', '📄 ' + f)); });
      fb.appendChild(grid);
      inner.appendChild(fb);
    }

    if (st.error) {
      var eb = el('div', 'block error');
      eb.appendChild(el('div', 'b-label', 'Lỗi / ghi chú'));
      eb.appendChild(el('div', '', st.error));
      inner.appendChild(eb);
    }

    if (st.final_answer) {
      var fin = el('div', 'block final');
      fin.appendChild(el('div', 'b-label', '✅ Kết quả cuối cùng'));
      var fd = el('div');
      fd.innerHTML = md(st.final_answer);
      fin.appendChild(fd);
      inner.appendChild(fin);
    }

    if ((st.status === 'done' || st.status === 'paused') && !st.final_answer && !(st.steps || []).length) {
      inner.appendChild(el('div', 'empty-note', 'Session trống.'));
    }

    b.appendChild(inner);
    b.scrollTop = b.scrollHeight;

    $('askBar').classList.remove('hidden');
  }

  $('askBtn').addEventListener('click', askSession);
  $('askInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') askSession();
  });
  function askSession() {
    var input = $('askInput');
    var q = input.value.trim();
    if (!q || !currentSessionId) return;
    input.value = '';
    api('ask_session', { body: { session_id: currentSessionId, question: q, history: historySummary() } })
      .then(function () {
        toast('📨 Đã gửi — agent đang tiếp tục việc cũ');
        var found = sessions.find(function (s) { return s.id === currentSessionId; });
        if (found) { found.status = 'running'; renderSessionList(); }
        startPolling();
      })
      .catch(function (e) { toast(e.message, true); });
  }

  function deleteSession(id) {
    if (!confirm('Xóa session này?\nToàn bộ dữ liệu, tiến độ và lịch sử Actions run của session sẽ bị xóa vĩnh viễn.')) return;
    api('delete_session', { body: { session_id: id } })
      .then(function (r) {
        sessions = sessions.filter(function (s) { return s.id !== id; });
        renderSessionList();
        toast('🗑 Đã xóa session (' + (r.runs_deleted || 0) + ' run, nhánh ' + (r.branch_deleted ? 'đã xóa' : 'lỗi') + ')' + (r.note ? ' — ' + r.note : ''), !r.branch_deleted);
        if (currentSessionId === id) { currentSessionId = null; show('chat'); }
        if (r.note) refreshSessions();
      })
      .catch(function (e) { toast('Xóa lỗi: ' + e.message, true); });
  }

  /* ------------------------------------------------ admin */

  $('newChatBtn').addEventListener('click', function () {
    chatHistory = [];
    $('chatLog').innerHTML = '';
    currentSessionId = null;
    show('chat');
    addMsg('assistant', 'Trò chuyện mới 👋 Bạn cần tôi giúp gì?');
  });

  $('adminBtn').addEventListener('click', function () { $('adminModal').classList.remove('hidden'); });
  $('adminCloseBtn').addEventListener('click', function () { $('adminModal').classList.add('hidden'); });

  $('adminLoadBtn').addEventListener('click', function () {
    adminToken = $('adminKeyInput').value.trim();
    if (!adminToken) return;
    api('admin_list', { method: 'POST', body: {}, admin: true })
      .then(function (r) {
        var all = r.sessions || [];
        var counts = { running: 0, done: 0, error: 0, paused: 0 };
        all.forEach(function (s) { if (counts[s.status] !== undefined) counts[s.status]++; });
        $('adminStats').textContent = 'Tổng: ' + all.length + '/' + (r.max || '?')
          + ' • đang chạy: ' + counts.running
          + ' • hoàn thành: ' + counts.done
          + ' • lỗi: ' + counts.error
          + ' • tạm dừng: ' + counts.paused;
        var list = $('adminList');
        list.innerHTML = '';
        if (!all.length) { list.appendChild(el('div', 'empty-note', 'Chưa có session nào')); return; }
        all.forEach(function (s) {
          var row = el('div', 'admin-item');
          var dot = el('span', 'dot ' + (s.status || 'pending'));
          row.appendChild(dot);
          row.appendChild(el('div', 'ai-title', (s.title || s.id) + ' — ' + (STATUS_TEXT[s.status] || s.status || '?')));
          var btn = el('button', 'btn danger small', 'Xóa');
          btn.addEventListener('click', function () {
            if (!confirm('Admin xóa session ' + s.id + '?')) return;
            // dùng token user thường cho delete (admin key cũng được server chấp nhận)
            api('delete_session', { body: { session_id: s.id }, admin: true })
              .then(function () { toast('Đã xóa ' + s.id); $('adminLoadBtn').click(); refreshSessions(); })
              .catch(function (e) { toast(e.message, true); });
          });
          row.appendChild(btn);
          list.appendChild(row);
        });
      })
      .catch(function (e) { toast('Admin: ' + e.message, true); });
  });

  /* ------------------------------------------------ khởi động */

  if (token) {
    // thử luôn, sai mật khẩu thì hiện màn đăng nhập
    api('health').then(startApp).catch(function () {
      localStorage.removeItem('agent_token');
      token = '';
      $('view-login').classList.remove('hidden');
    });
  } else {
    $('view-login').classList.remove('hidden');
  }
})();
