# Agent Sandbox

Repo này là "sandbox" cho AI Agent web. **Giữ repo ở chế độ Private.**

Cách hoạt động:

- Website (shared hosting PHP) gọi GitHub API để tạo nhánh `session/<id>` và kích hoạt
  workflow `.github/workflows/session.yml` trên nhánh đó.
- Workflow chạy `runner.py` — một agent tự chủ (gọi LLM bằng secret `LLM_API_KEY`).
- Agent làm việc theo từng bước, sau mỗi bước commit tiến độ + dữ liệu vào
  `sessions/<id>/state.json` và `sessions/<id>/files/` trên chính nhánh session.
- Website đọc `state.json` để hiển thị tiến độ gần realtime cho người dùng.
- Khi người dùng/admin xóa session: website hủy + xóa các run Actions của nhánh,
  sau đó xóa nhánh `session/<id>` → toàn bộ dữ liệu của session biến mất.

Cần thiết lập (xem file HUONG-DAN.md đi kèm khi upload lên hosting):

1. Repo **private**.
2. Secret `LLM_API_KEY` — API key của LLM (OpenAI-compatible).
3. Fine-grained PAT với quyền Contents: Read/Write + Actions: Read/Write cho riêng repo này
   (PAT này đặt trên hosting, KHÔNG đặt trong repo).
