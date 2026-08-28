<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chính sách quyền riêng tư | MMTB Toàn Thắng</title>
    <style>
        :root { color-scheme: light; --blue:#2563eb; --ink:#0f172a; --muted:#64748b; --line:#dbe3ef; --panel:#fff; --bg:#f4f7fb; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.65 system-ui,-apple-system,"Segoe UI",sans-serif; }
        header { background:linear-gradient(135deg,#1d4ed8,#2563eb); color:#fff; padding:42px 20px; }
        header div, main { width:min(900px,calc(100% - 32px)); margin:auto; }
        h1 { margin:0 0 6px; font-size:clamp(28px,5vw,40px); line-height:1.2; }
        header p { margin:0; opacity:.9; }
        main { background:var(--panel); margin-top:28px; margin-bottom:40px; padding:clamp(22px,5vw,48px); border:1px solid var(--line); border-radius:18px; box-shadow:0 12px 36px rgba(15,23,42,.07); }
        h2 { margin:30px 0 8px; font-size:21px; }
        h2:first-child { margin-top:0; }
        p, li { color:#334155; }
        ul { padding-left:22px; }
        a { color:var(--blue); }
        .notice { padding:16px 18px; background:#eff6ff; border-left:4px solid var(--blue); border-radius:8px; }
        footer { margin-top:34px; padding-top:20px; border-top:1px solid var(--line); color:var(--muted); font-size:14px; }
    </style>
</head>
<body>
<header>
    <div>
        <h1>Chính sách quyền riêng tư</h1>
        <p>MMTB Toàn Thắng · Gmail Intake Agent</p>
    </div>
</header>
<main>
    <h2>1. Phạm vi và mục đích</h2>
    <p>MMTB Toàn Thắng sử dụng Gmail Intake Agent để hỗ trợ tiếp nhận phản hồi cấp mã tài sản cho hồ sơ máy, đối chiếu phản hồi với hồ sơ có mã tham chiếu <strong>TN-</strong> và trình người quản trị xác nhận trước khi tạo máy.</p>

    <div class="notice"><strong>Quyền Gmail được sử dụng:</strong> <code>gmail.readonly</code>. Ứng dụng chỉ đọc dữ liệu cần thiết, không gửi, sửa hoặc xóa thư trong Gmail.</div>

    <h2>2. Dữ liệu được xử lý</h2>
    <ul>
        <li>Mã thư và luồng thư, người gửi, tiêu đề, thời gian và nội dung văn bản của thư phù hợp truy vấn cấu hình.</li>
        <li>Tệp đính kèm liên quan đến việc cấp mã tài sản, bao gồm ảnh dùng để đọc mã khi cần.</li>
        <li>Mã hồ sơ tiếp nhận, số khung, số máy và mã tài sản cần đối chiếu.</li>
    </ul>

    <h2>3. Cách sử dụng dữ liệu</h2>
    <p>Agent chạy trên máy tính do chủ tài khoản quản lý, chỉ chuyển phản hồi có liên quan về hệ thống MMTB. Ảnh đính kèm phù hợp có thể được xử lý bằng dịch vụ nhận dạng hình ảnh để đề xuất mã tài sản. Mọi đề xuất phải được người dùng xác nhận; hệ thống không tự tạo máy chỉ từ nội dung email.</p>

    <h2>4. Lưu trữ và bảo mật</h2>
    <p>Thông tin phản hồi đã khớp, bằng chứng liên quan và lịch sử thao tác được lưu trong hệ thống để phục vụ hồ sơ thiết bị và truy vết. Thông tin OAuth và token Gmail chỉ được lưu trên máy tính của chủ tài khoản, không lưu mật khẩu Gmail. Quyền truy cập được giới hạn bằng xác thực, phân quyền và token riêng cho worker.</p>

    <h2>5. Chia sẻ dữ liệu</h2>
    <p>Dữ liệu không được bán, dùng cho quảng cáo hoặc xây dựng hồ sơ quảng cáo. Dữ liệu chỉ được chuyển cho các nhà cung cấp dịch vụ cần thiết để vận hành chức năng đã mô tả (như lưu trữ hệ thống, nhận dạng hình ảnh và thông báo vận hành), hoặc khi pháp luật yêu cầu.</p>

    <h2>6. Dữ liệu người dùng Google</h2>
    <p>Việc sử dụng và chuyển dữ liệu nhận từ Google APIs tuân thủ <a href="https://developers.google.com/terms/api-services-user-data-policy" rel="noopener noreferrer">Google API Services User Data Policy</a>, bao gồm các yêu cầu về Limited Use.</p>

    <h2>7. Thời hạn lưu giữ và yêu cầu xóa</h2>
    <p>Dữ liệu được giữ trong thời gian cần thiết cho hồ sơ thiết bị, kiểm toán và nghĩa vụ pháp lý. Chủ tài khoản có thể yêu cầu xem hoặc xóa dữ liệu bằng email bên dưới, hoặc thu hồi quyền Gmail tại <a href="https://myaccount.google.com/permissions" rel="noopener noreferrer">trang quyền truy cập Tài khoản Google</a>.</p>

    <h2>8. Liên hệ</h2>
    <p>Email: <a href="mailto:hoanghung.fb2@gmail.com">hoanghung.fb2@gmail.com</a></p>

    <footer>Có hiệu lực từ ngày 28/08/2026. Chính sách sẽ được cập nhật tại chính địa chỉ này khi phạm vi xử lý dữ liệu thay đổi.</footer>
</main>
</body>
</html>
