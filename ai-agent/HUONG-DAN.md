# HƯỚNG DẪN CÀI ĐẶT AI AGENT TỪ A ĐẾN Z

Hệ thống gồm 2 phần bạn sẽ cài đặt:

| Phần | Cài ở đâu | Vai trò |
|---|---|---|
| `repo-github/` | GitHub repo **private** | Sandbox: agent chạy code thật trong GitHub Actions |
| `hosting/` | Shared hosting (cPanel) | Trang web: chat + tạo/xóa/theo dõi session |

**Bạn cần chuẩn bị trước khi bắt đầu:**
- Tài khoản GitHub (miễn phí): https://github.com/signup
- Một API key LLM bất kỳ tương thích OpenAI (OpenAI, Groq, GLM, OpenRouter...)
- Đăng nhập được vào cPanel của hosting

> ⚠️ QUY TẮC AN TOÀN: PAT và API key chỉ dán vào 2 nơi duy nhất —
> `config.php` trên hosting và Secrets trên GitHub. **Không gửi cho ai qua chat/email.**

---

## PHẦN 1 — TẠO REPO GITHUB (5 phút)

### Bước 1.1: Tạo repo
1. Vào https://github.com/new
2. Repository name: `agent-sandbox`
3. Chọn **Private** (bắt buộc — dữ liệu session nằm trong repo)
4. Tích ✅ "Add a README file"
5. Bấm **Create repository**

### Bước 1.2: Upload file workflow
Vào trang repo vừa tạo:
1. Bấm nút **Add file → Create new file**
2. Ở ô tên file, gõ chính xác: `.github/workflows/session.yml`
   (gõ dấu `/` hệ thống tự tạo thư mục — GitHub sẽ gợi ý "workflow" khi gõ)
3. Mở file `repo-github/.github/workflows/session.yml` trong bộ code tôi đưa,
   copy toàn bộ nội dung, dán vào ô soạn thảo trên web
4. Bấm **Commit changes**

### Bước 1.3: Upload runner.py
1. Bấm **Add file → Create new file**
2. Tên file: `runner.py`
3. Copy toàn bộ nội dung file `repo-github/runner.py` dán vào
4. Bấm **Commit changes**

### Bước 1.4: Upload requirements.txt
Làm giống bước 1.3 với tên file `requirements.txt`
(nội dung trong `repo-github/requirements.txt`).

### Bước 1.5: Tạo Secret chứa API key LLM
1. Trong repo, vào **Settings → Secrets and variables → Actions**
2. Bấm **New repository secret**
3. Name: `LLM_API_KEY`
4. Secret: dán API key LLM của bạn (ví dụ `sk-...`)
5. Bấm **Add secret**

✅ Xong phần GitHub. Repo giờ có:
`.github/workflows/session.yml`, `runner.py`, `requirements.txt`, `README.md`, secret `LLM_API_KEY`.

---

## PHẦN 2 — TẠO PAT (CHÌA KHÓA CHO WEBSITE) (3 phút)

1. Vào https://github.com/settings/personal-access-tokens/new
   (đường đầy đủ: Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token)
2. **Token name**: `agent-web`
3. **Expiration**: chọn 90 ngày hoặc 1 năm (hết hạn phải tạo lại)
4. **Resource owner**: chính tài khoản bạn
5. **Repository access**: chọn "Only select repositories" → chọn `agent-sandbox`
6. Mục **Permissions → Repository permissions**:
   - **Contents**: `Read and write`
   - **Actions**: `Read and write`
   - (Metadata sẽ tự thành Read-only — để nguyên)
7. Bấm **Generate token** và **COPY MÃ PAT** (`github_pat_...`) —
   chỉ hiện 1 lần, nếu mất thì tạo lại.

---

## PHẦN 3 — CÀI LÊN HOSTING (5 phút)

### Bước 3.1: Upload code
1. Đăng nhập cPanel → **File Manager**
2. Vào thư mục `public_html`
3. Tạo thư mục con tên `agent` và mở vào đó
4. Upload toàn bộ file trong thư mục `hosting/` của bộ code
   (`index.html`, `app.js`, `style.css`, `api.php`, `config.php`, thư mục `data/`)
   *(Cách upload: nút Upload ở trên File Manager, kéo thả file vào)*

### Bước 3.2: Điền config.php — BƯỚC QUAN TRỌNG NHẤT
Trong File Manager, click phải `config.php` → **Edit** và điền:

| Dòng | Điền gì |
|---|---|
| `github_pat` | PAT `github_pat_...` vừa tạo ở Phần 2 |
| `github_repo` | `tên-tài-khoản/agent-sandbox` (xem đúng format ở trang repo) |
| `llm_api_key` | API key LLM (cùng key với bước 1.5) |
| `llm_base_url` | Đổi nếu không dùng OpenAI (ghi chú sẵn trong file) |
| `llm_model` | Đổi nếu cần (ghi chú sẵn trong file) |
| `user_password` | Mật khẩu để bạn đăng nhập trang web — tự đặt, khó đoán |
| `admin_key` | Mật khẩu admin — khác mật khẩu thường |

Lưu file. **Kiểm tra nhánh chính của repo**: vào trang repo, nếu nhánh tên
`master` (không phải `main`) thì đổi `'main_branch' => 'master'`.

### Bước 3.3: Kiểm tra PHP
Hầu hết shared hosting có sẵn PHP ≥ 7.4. Nếu cPanel có mục **Select PHP Version**,
chọn 7.4 trở lên và đảm bảo phần mở rộng **cURL** được bật.

---

## PHẦN 4 — CHẠY THỬ

1. Mở trình duyệt: `https://tên-miền-của-bạn/agent/`
2. Nhập mật khẩu (`user_password`) → Vào trang
   - Nếu báo lỗi cấu hình, làm theo thông báo (thường là PAT/repo sai)
3. **Test chat đơn giản**: gõ "Xin chào, bạn là ai?" → trả lời tức thì.
4. **Test sandbox**: gõ:
   > viết chương trình python kiểm tra số nguyên tố nhỏ hơn 100, chạy và cho tôi danh sách
   - Trợ lý sẽ báo tạo session → panel session mở ra
   - Trạng thái "Đang chờ" 20–60 giây (Actions khởi động máy ảo)
   - Sau đó bạn thấy **từng bước** agent làm: 💭 suy nghĩ, lệnh chạy, output
   - Xong: khối "✅ Kết quả cuối cùng"
5. **Test tiếp tục session**: trong panel session, gõ yêu cầu thêm vào ô dưới cùng.
6. **Test xóa**: bấm 🗑 Xóa session → data + Actions run bị dọn sạch
   (nếu còn run "đang chờ hủy", bấm Xóa lần nữa).

Vậy là xong! 🎉

---

## CÁCH HỆ THỐNG HOẠT ĐỘNG (để bạn hiểu khi cần xử lý sự cố)

```
Câu hỏi đơn giản ──► PHP gọi LLM trực tiếp ──► trả lời ngay (streaming)

Cần chạy code ──► LLM phát hiệu hiệu lệnh tạo session
            ──► PHP tạo nhánh session/<id> + kích hoạt Actions trên nhánh đó
            ──► Actions chạy runner.py (agent tự chủ trong máy ảo Linux)
            ──► Mỗi bước agent commit sessions/<id>/state.json + files/
            ──► Trình duyệt poll state.json mỗi 3s → hiển thị tiến độ

Xóa session ──► PHP hủy + xóa mọi run Actions của nhánh
           ──► PHP xóa nhánh session/<id> → TOÀN BỘ dữ liệu session biến mất
```

- "AI như được cài trong Actions": mỗi lần Actions chạy, agent đọc lại
  `state.json` (các bước đã làm, file đã có) nên tiếp tục đúng chỗ cũ.
- Trên web, khi bạn hỏi về session, dữ liệu tiến độ cũng được nạp lại vào
  ngữ cảnh chat.

## GIỚI HẠN CẦN BIẾT

- **Streaming là "giả realtime"** (cập nhật mỗi 3 giây) — GitHub Actions không
  stream trực tiếp được; đây là giới hạn của phương án, không phải lỗi.
- **Miễn phí GitHub**: ~2000 phút Actions/tháng (private repo). Mỗi run agent
  tối đa 10 phút, thường xong trong 2–4 phút.
- **Khởi động chậm**: mỗi run mất 20–60 giây để máy ảo Actions sẵn sàng.
- **Session "paused"**: khi agent hết thời lượng run nhưng chưa xong việc —
  chỉ cần gửi yêu cầu tiếp, agent sẽ làm tiếp từ chỗ dừng.
- **Xóa run**: run đang chạy phải hủy trước, mất ~5 giây mới xóa được —
  bấm Xóa lần 2 nếu còn run tồn.

## XỬ LÝ SỰ CỐ NHANH

| Hiện tượng | Nguyên nhân / cách xử lý |
|---|---|
| Đăng nhập xong báo "LỖI 404/401..." ở bước health | PAT sai hoặc chưa cấp đúng repo. Tạo lại PAT đúng Phần 2 |
| "Không thấy workflow" | File `session.yml` chưa upload đúng đường dẫn `.github/workflows/` |
| Session treo "Đang chờ" > 2 phút | Vào repo → tab Actions xem run lỗi gì (thường thiếu secret `LLM_API_KEY`) |
| Chat không trả lời | `llm_api_key`/`llm_base_url`/`llm_model` trong config.php chưa khớp nhau |
| "Không kích hoạt được Actions" | PAT thiếu quyền Actions Read/Write, hoặc tên repo sai |
| PAT hết hạn (90 ngày/1 năm) | Tạo PAT mới, thay vào config.php |

## BẢO MẬT (đọc một lần)

1. Repo **phải** Private — ai cũng đọc được dữ liệu session nếu Public.
2. `config.php` chứa PAT + API key: đừng chia sẻ, đừng commit lên GitHub.
3. Mật khẩu web nên dài ≥ 12 ký tự; admin key khác mật khẩu thường.
4.PAT hết hạn là cơ chế tốt — hẹn lịch tạo lại.
5. Người khác không có mật khẩu thì không dùng được agent của bạn.
