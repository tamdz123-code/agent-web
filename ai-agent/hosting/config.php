<?php
/**
 * CẤU HÌNH CHÍNH — bạn chỉ cần sửa file này sau khi upload lên hosting.
 * Giữ file này bí mật, đừng chia sẻ cho ai.
 */
return [
    // ---- GitHub ------------------------------------------------------------
    // PAT (Personal Access Token) loại fine-grained, chỉ cấp cho đúng 1 repo,
    // quyền: Contents Read/Write + Actions Read/Write. Tạo tại:
    // https://github.com/settings/personal-access-tokenstesters/new
    'github_pat'    => 'DAN_PAT_CUA_BAN_VAO_DAY',

    // Repo theo định dạng "tên-tài-khoản/tên-repo", ví dụ "nguyenvan/agent-sandbox"
    'github_repo'   => 'TAI_KHOAN/agent-sandbox',

    // Tên workflow file trong repo (mặc định session.yml)
    'workflow_file' => 'session.yml',

    // Nhánh chính của repo ("main" hoặc "master")
    'main_branch'   => 'main',

    // ---- LLM chạy trên hosting (chat đơn giản, trả lời ngay) ---------------
    // API key LLM bất kỳ tương thích OpenAI: OpenAI, Groq, GLM, OpenRouter...
    'llm_api_key'   => 'LLM_API_KEY_CUA_BAN',

    // Ví dụ: https://api.openai.com/v1 | https://api.groq.com/openai/v1
    //        | https://open.bigmodel.cn/api/paas/v4
    'llm_base_url'  => 'https://api.openai.com/v1',

    // Tên model, ví dụ: gpt-4o-mini | llama-3.1-8b-instant | glm-4-flash
    'llm_model'     => 'gpt-4o-mini',

    // ---- Bảo mật website ----------------------------------------------------
    // Mật khẩu để dùng trang web (bạn tự đặt, nên dài và khó đoán)
    'user_password' => 'dat-mat-khau-cua-ban-o-day',

    // Key của admin (quyền cao hơn: quản lý/xóa mọi session). Khác mật khẩu thường.
    'admin_key'     => 'dat-admin-key-khac-o-day',

    // Số session tối đa lưu cùng lúc (đạt giới hạn thì phải xóa bớt)
    'max_sessions'  => 30,
];
