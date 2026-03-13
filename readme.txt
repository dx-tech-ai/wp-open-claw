=== WP Open Claw ===
Contributors: DIGITAL X-SOLUTION TECHNOLOGY
Tags: ai, agent, automation, gemini, openai, chatgpt, wordpress-ai
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI Agent tự trị cho WordPress — thực thi hành động thay vì chỉ trả lời văn bản.

== Description ==

**WP Open Claw** là một AI Agent plugin cho WordPress, hoạt động dựa trên vòng lặp ReAct (Reason + Act). Agent không chỉ trả lời câu hỏi mà còn **thực thi hành động trực tiếp** trên site WordPress của bạn.

= Tính năng chính =

* 🤖 **Command Palette** — Mở bằng `Ctrl+G`, giao diện chat hiện đại
* 🧠 **ReAct Loop** — Agent tự suy luận và chọn tool phù hợp
* ✅ **Confirm trước khi thực thi** — Các hành động thay đổi dữ liệu cần xác nhận
* 🔌 **Đa LLM Provider** — Hỗ trợ OpenAI, Google Gemini, Anthropic Claude

= 8 Tools tích hợp =

1. **Content Manager** — Tạo/cập nhật bài viết
2. **System Inspector** — Xem thông tin site, plugins, categories
3. **Web Research** — Tìm kiếm web qua DuckDuckGo/Google
4. **Taxonomy Manager** — Tạo/sửa/xóa categories & tags
5. **Media Manager** — Upload ảnh từ URL, set featured image
6. **Page Manager** — Tạo/sửa/xóa Pages
7. **User Inspector** — Xem thông tin users
8. **Analytics Reader** — Thống kê bài viết, comments, tổng quan site

= Ví dụ sử dụng =

* "Tạo category Công Nghệ"
* "Viết bài về AI trends 2025, lưu draft"
* "Tìm kiếm về WordPress performance và viết bài tổng hợp"
* "Cho tôi xem thống kê bài viết trên site"
* "Tạo page About Us với nội dung giới thiệu công ty"

== Installation ==

1. Upload thư mục `wp-open-claw` vào `/wp-content/plugins/`
2. Chạy `composer install` trong thư mục plugin
3. Kích hoạt plugin trong WP Admin → Plugins
4. Vào **Open Claw** trong menu admin → cấu hình API key
5. Nhấn `Ctrl+G` trên bất kỳ trang admin nào để bắt đầu

== Configuration ==

= LLM Provider =
Chọn một trong ba provider:

* **Google Gemini (AI Studio)** — Miễn phí tại [aistudio.google.com](https://aistudio.google.com/apikey)
* **OpenAI** — Cần API key từ [platform.openai.com](https://platform.openai.com)
* **Anthropic** — Cần API key từ [console.anthropic.com](https://console.anthropic.com)

= Web Search =
* Mặc định dùng **DuckDuckGo** (miễn phí, không cần key)
* Tùy chọn: Google Custom Search (cần API key + CX)

== Frequently Asked Questions ==

= Plugin có miễn phí không? =
Plugin hoàn toàn miễn phí và mã nguồn mở. Tuy nhiên bạn cần API key từ LLM provider (Gemini có free tier).

= Cần PHP version nào? =
Yêu cầu PHP 8.1 trở lên.

= Agent có thể xóa dữ liệu không? =
Tất cả hành động thay đổi dữ liệu (tạo/sửa/xóa) đều yêu cầu **xác nhận** từ người dùng trước khi thực thi.

= Có hỗ trợ Custom Post Type không? =
Hiện tại hỗ trợ Posts và Pages. Custom Post Type sẽ được bổ sung trong phiên bản tương lai.

== Screenshots ==

1. Command Palette với giao diện glassmorphism
2. Agent thực thi hành động với thinking steps
3. Trang cấu hình LLM Provider

== Changelog ==

= 1.0.0 =
* Initial release
* 8 built-in tools
* Support for OpenAI, Gemini, Anthropic
* Command Palette UI with Ctrl+G shortcut
* DuckDuckGo web search (free, no API key needed)
* Action confirmation system

== Upgrade Notice ==

= 1.0.0 =
First release — install and configure your preferred AI provider to get started.
