# Giữ file này ngay cạnh index.html trên hosting — không cần đổi gì.

File README này chỉ để bạn nhớ cấu trúc:

    index.html   -> giao diện trang web
    app.js       -> logic trình duyệt (chat, theo dõi session)
    style.css    -> giao diện
    api.php      -> API trung gian gọi GitHub + LLM
    config.php   -> BẠN PHẢI SỬA FILE NÀY (PAT, repo, API key, mật khẩu)
    data/        -> hệ thống tự tạo, lưu danh sách session (đã chặn truy cập)
