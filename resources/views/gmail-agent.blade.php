<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="MMTB Gmail Agent hỗ trợ đọc phản hồi cấp mã tài sản từ Gmail và đối chiếu với hồ sơ tiếp nhận máy MMTB.">
    <title>MMTB Gmail Agent</title>
    <style>
        :root { color-scheme:light; --blue:#2563eb; --dark:#0f172a; --text:#334155; --muted:#64748b; --line:#dbe3ef; --bg:#f4f7fb; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--dark); font:16px/1.65 system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:var(--blue); }
        .hero { padding:64px 20px; background:linear-gradient(135deg,#1d4ed8,#2563eb); color:#fff; }
        .wrap { width:min(980px,calc(100% - 32px)); margin:auto; }
        .eyebrow { margin:0 0 8px; font-size:13px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; opacity:.85; }
        h1 { margin:0 0 14px; font-size:clamp(34px,6vw,54px); line-height:1.1; }
        .lead { max-width:720px; margin:0; font-size:18px; opacity:.94; }
        main { margin-top:-24px; margin-bottom:48px; }
        .panel { background:#fff; border:1px solid var(--line); border-radius:18px; padding:clamp(24px,5vw,46px); box-shadow:0 14px 40px rgba(15,23,42,.08); }
        h2 { margin:30px 0 10px; font-size:22px; }
        h2:first-child { margin-top:0; }
        p, li { color:var(--text); }
        ul { padding-left:22px; }
        .scope { margin:24px 0; padding:17px 19px; border-left:4px solid var(--blue); border-radius:9px; background:#eff6ff; color:var(--text); }
        .steps { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin:18px 0; }
        .step { padding:18px; border:1px solid var(--line); border-radius:12px; }
        .step strong { display:block; margin-bottom:5px; color:var(--dark); }
        .step p { margin:0; font-size:14px; }
        .actions { display:flex; flex-wrap:wrap; gap:12px; margin-top:28px; }
        .button { display:inline-block; padding:11px 17px; border-radius:9px; text-decoration:none; font-weight:700; }
        .primary { background:var(--blue); color:#fff; }
        .secondary { border:1px solid var(--line); color:var(--dark); }
        footer { margin-top:32px; padding-top:20px; border-top:1px solid var(--line); color:var(--muted); font-size:14px; }
        @media (max-width:700px) { .steps { grid-template-columns:1fr; } .hero { padding:46px 20px; } }
    </style>
</head>
<body>
<header class="hero">
    <div class="wrap">
        <p class="eyebrow">MMTB Toàn Thắng</p>
        <h1>MMTB Gmail Agent</h1>
        <p class="lead">Công cụ hỗ trợ tiếp nhận phản hồi cấp mã tài sản từ Gmail, đối chiếu với hồ sơ máy và trình người quản trị xác nhận.</p>
    </div>
</header>

<main class="wrap">
    <section class="panel">
        <h2>Mục đích ứng dụng</h2>
        <p>MMTB Gmail Agent phục vụ quy trình tiếp nhận máy của hệ thống MMTB Toàn Thắng. Ứng dụng tìm các email phản hồi liên quan đến hồ sơ có mã tham chiếu <strong>TN-</strong>, trích xuất mã tài sản được cấp và ghép đúng phản hồi với hồ sơ đang chờ.</p>

        <div class="scope"><strong>Quyền truy cập:</strong> ứng dụng chỉ yêu cầu <code>https://www.googleapis.com/auth/gmail.readonly</code>. Ứng dụng không gửi, sửa hoặc xóa email.</div>

        <h2>Quy trình hoạt động</h2>
        <div class="steps">
            <div class="step"><strong>1. Đọc phản hồi</strong><p>Chỉ tìm email phù hợp điều kiện cấu hình và mã hồ sơ tiếp nhận.</p></div>
            <div class="step"><strong>2. Đề xuất mã</strong><p>Đối chiếu nội dung hoặc ảnh đính kèm để đề xuất mã tài sản.</p></div>
            <div class="step"><strong>3. Người dùng xác nhận</strong><p>Máy chỉ được tạo sau khi người quản trị kiểm tra và xác nhận chính xác.</p></div>
        </div>

        <h2>Dữ liệu và quyền kiểm soát</h2>
        <ul>
            <li>Chỉ xử lý thư và tệp đính kèm cần thiết cho nghiệp vụ cấp mã tài sản.</li>
            <li>Không bán dữ liệu, không sử dụng cho quảng cáo và không đọc email cho mục đích khác.</li>
            <li>Chủ tài khoản có thể thu hồi quyền truy cập Google bất kỳ lúc nào.</li>
        </ul>

        <div class="actions">
            <a class="button primary" href="{{ route('privacy') }}">Chính sách quyền riêng tư</a>
            <a class="button secondary" href="{{ route('login') }}">Đăng nhập hệ thống</a>
        </div>

        <footer>
            Đơn vị vận hành: MMTB Toàn Thắng · Hỗ trợ: <a href="mailto:hoanghung.fb2@gmail.com">hoanghung.fb2@gmail.com</a>
        </footer>
    </section>
</main>
</body>
</html>
