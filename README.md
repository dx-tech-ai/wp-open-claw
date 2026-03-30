# WP Open Claw

🌐 [English](README-en.md) | **Tiếng Việt**

> AI Agent tự trị cho WordPress — thực thi hành động thay vì chỉ trả lời văn bản.

[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**WP Open Claw** là một AI Agent plugin cho WordPress, hoạt động dựa trên vòng lặp **ReAct** (Reason + Act). Agent không chỉ trả lời câu hỏi mà còn **thực thi hành động trực tiếp** trên site WordPress của bạn.

## ✨ Tính năng chính

| Tính năng | Mô tả |
|-----------|-------|
| 🤖 **Command Palette** | Mở bằng `Ctrl+I`, `Ctrl+G` hoặc `Ctrl+Shift+K`, giao diện chat hiện đại phong cách glassmorphism |
| 🧠 **ReAct Loop** | Agent tự suy luận, chọn tool, thực thi, quan sát kết quả và tiếp tục |
| ✅ **Xác nhận trước khi thực thi** | Hành động thay đổi dữ liệu cần xác nhận từ người dùng |
| 🔗 **Chain Actions** | Tự động thực hiện chuỗi hành động liên tiếp |
| 🔌 **Đa LLM Provider** | OpenAI (GPT-4o) · Google Gemini (2.5 Flash/Pro) · Anthropic Claude (Sonnet 4) |
| 🛒 **WooCommerce Ready** | Tự động kích hoạt tool quản lý sản phẩm, đơn hàng, khách hàng |
| 💾 **Session Persistence** | Lưu trạng thái phiên làm việc qua WordPress transients |
| 🔍 **Web Research** | Tìm kiếm web trực tiếp (DuckDuckGo miễn phí hoặc Google CSE) |
| 📱 **Telegram Bot** | Điều khiển WordPress qua Telegram với xác nhận bằng inline keyboard |

## 🛠️ 12 Tools tích hợp

### WordPress Core (9 tools)

| # | Tool | Tên hàm | Chức năng |
|---|------|---------|-----------|
| 1 | **Content Manager** | `wp_content_manager` | Tạo/cập nhật bài viết với categories, tags, HTML |
| 2 | **System Inspector** | `wp_system_inspector` | Xem thông tin site, plugins, categories, tags, post types |
| 3 | **Web Research** | `web_research_tool` | Tìm kiếm web qua DuckDuckGo hoặc Google CSE |
| 4 | **Taxonomy Manager** | `wp_taxonomy_manager` | Tạo/sửa/xóa categories & tags |
| 5 | **Media Manager** | `wp_media_manager` | Upload ảnh từ URL, set featured image, liệt kê/xóa media |
| 6 | **Page Manager** | `wp_page_manager` | Tạo/sửa/xóa/liệt kê Pages, hỗ trợ template & sub-pages |
| 7 | **User Inspector** | `wp_user_inspector` | Xem danh sách users, chi tiết, thống kê theo role |
| 8 | **Analytics Reader** | `wp_analytics_reader` | Thống kê bài viết, comments, tổng quan nội dung |
| 9 | **Report & Analytics** | `wp_report` | Dashboard tổng quan, báo cáo đơn hàng, sản phẩm, nội dung |

### WooCommerce (3 tools — tự động kích hoạt)

| # | Tool | Tên hàm | Chức năng |
|---|------|---------|-----------|
| 10 | **Product Manager** | `woo_product_manager` | CRUD sản phẩm (tên, giá, SKU, kho, ảnh), quản lý categories |
| 11 | **Order Inspector** | `woo_order_inspector` | Xem đơn hàng, cập nhật trạng thái, thống kê doanh thu |
| 12 | **Customer Inspector** | `woo_customer_inspector` | Xem/tìm kiếm khách hàng, thống kê, top customers |

## 🏗️ Kiến trúc

```
wp-open-claw/
├── wp-open-claw.php          # Entry point, constants, hooks
├── src/
│   ├── Actions/              # 12 Tool implementations
│   │   ├── ContentTool.php
│   │   ├── SystemTool.php
│   │   ├── ResearchTool.php
│   │   ├── TaxonomyTool.php
│   │   ├── MediaTool.php
│   │   ├── PageTool.php
│   │   ├── UserInspector.php
│   │   ├── AnalyticsReader.php
│   │   ├── ReportTool.php
│   │   ├── ProductTool.php     # WooCommerce
│   │   ├── OrderTool.php       # WooCommerce
│   │   └── CustomerTool.php    # WooCommerce
│   ├── Agent/
│   │   ├── Kernel.php          # ReAct loop engine
│   │   └── ContextProvider.php # Auto-inject site context
│   ├── LLM/
│   │   ├── ClientInterface.php
│   │   ├── OpenAIClient.php
│   │   ├── GeminiClient.php
│   │   └── AnthropicClient.php
│   ├── REST/
│   │   └── AgentController.php # REST API endpoints
│   ├── Admin/
│   │   ├── Settings.php        # Settings page
│   │   └── Dashboard.php       # Admin dashboard
│   └── Tools/
│       ├── ToolInterface.php
│       ├── DynamicConfirmInterface.php
│       └── Manager.php         # Tool registry & dispatcher
│   ├── Telegram/
│   │   ├── TelegramController.php  # Webhook handler
│   │   ├── TelegramClient.php      # Telegram Bot API client
│   │   └── StepFormatter.php       # Format agent steps for Telegram
├── assets/
│   ├── css/
│   └── js/
└── vendor/                     # Composer dependencies
```

### Luồng hoạt động

```
User Input → Kernel.handle()
    ↓
System Prompt (+ ContextProvider snapshot)
    ↓
LLM Chat (OpenAI / Gemini / Anthropic)
    ↓
┌─ Text Response → Return to user
│
└─ Tool Call → Manager.dispatch()
       ↓
   ┌─ Read-only → Execute immediately → Observation → Continue loop
   │
   └─ Write action → Pending Confirmation
          ↓
      User Approve → Kernel.confirmAction() → Execute → Resume loop
      User Reject  → Kernel.rejectAction()  → Stop
```

## 📦 Cài đặt

**Cách 1: Upload ZIP (Khuyến nghị)**
1. Tải ZIP mới nhất từ [GitHub Releases](https://github.com/dx-tech-ai/wp-open-claw/releases)
2. Vào WP Admin → Plugins → Add New → Upload Plugin
3. Chọn file ZIP vừa tải → Install Now → Activate
4. Vào **Open Claw** → cấu hình API key
5. Nhấn `Ctrl+I` hoặc `Ctrl+G` để bắt đầu!

**Cách 2: Clone từ GitHub**
```bash
cd /path/to/wp-content/plugins/
git clone https://github.com/dx-tech-ai/wp-open-claw.git

# Kích hoạt plugin trong WP Admin → Plugins
# Vào Open Claw → cấu hình API key
# Nhấn Ctrl+I hoặc Ctrl+G để bắt đầu!
```

## ⚙️ Cấu hình

### LLM Provider

| Provider | Models | Free Tier | Lấy API Key |
|----------|--------|-----------|-------------|
| **Google Gemini** | Gemini 2.5 Flash, Flash Lite, 2.5 Pro Preview, 2.0 Flash Lite | ✅ Có | [aistudio.google.com](https://aistudio.google.com/apikey) |
| **OpenAI** | GPT-4o, GPT-4o Mini, GPT-4 Turbo | ❌ Không | [platform.openai.com](https://platform.openai.com) |
| **Anthropic** | Claude Sonnet 4, Claude 3.5 Haiku | ❌ Không | [console.anthropic.com](https://console.anthropic.com) |

### Web Search

- **Mặc định**: DuckDuckGo (miễn phí, không cần API key)
- **Tùy chọn**: Google Custom Search (cần API key + Search Engine ID)

### Agent Settings

- **Max Iterations**: Số vòng lặp ReAct tối đa (1–20, mặc định: 10)

### Telegram Bot

1. Tạo bot từ [@BotFather](https://t.me/BotFather) trên Telegram
2. Sao chép Bot Token vào **Open Claw → Telegram → Bot Token**
3. Thêm Chat ID của bạn vào **Allowed Chat IDs**
4. Nhấn **Register Webhook** để kết nối
5. Gửi tin nhắn cho bot — AI agent sẽ phản hồi!

**Tính năng Telegram:**
- Gửi lệnh bằng ngôn ngữ tự nhiên để điều khiển WordPress
- Nút Approve/Reject trực tiếp trên Telegram (inline keyboard)
- Session riêng cho từng chat — hỗ trợ hội thoại nhiều lượt
- Tự động fallback nếu Markdown không hợp lệ
- Bảo mật với secret token và whitelist chat ID

**Lệnh:**
- `/start` — Hiển trợ giúp
- `/reset` — Xóa phiên làm việc hiện tại

## 💡 Ví dụ sử dụng

### WordPress

```
"Tạo category Công Nghệ"
"Viết bài về AI trends 2025, lưu draft"
"Tìm kiếm về WordPress performance và viết bài tổng hợp"
"Cho tôi xem thống kê bài viết trên site"
"Tạo page About Us với nội dung giới thiệu công ty"
"Upload ảnh từ URL và set làm featured image cho bài viết"
```

### WooCommerce

```
"Tạo 3 sản phẩm áo thun với giá 250.000đ"
"Tạo danh mục sản phẩm Thời Trang, rồi thêm 5 sản phẩm vào đó"
"Cho tôi xem doanh thu tháng này"
"Cập nhật đơn hàng #123 sang trạng thái completed"
"Tìm khách hàng có email chứa 'gmail'"
"Cho tôi xem top 5 khách hàng chi tiêu nhiều nhất"
```

### Báo cáo & Thống kê

```
"Cho tôi xem tổng quan dashboard"
"Thống kê đơn hàng tháng này"
"Sản phẩm bán chạy nhất"
"Báo cáo bài viết và trang"
```

## ❓ FAQ

<details>
<summary><strong>Plugin có miễn phí không?</strong></summary>
Plugin hoàn toàn miễn phí và mã nguồn mở. Bạn cần API key từ LLM provider (Gemini có free tier).
</details>

<details>
<summary><strong>Cần PHP version nào?</strong></summary>
Yêu cầu PHP 7.4 trở lên.
</details>

<details>
<summary><strong>Agent có thể xóa dữ liệu không?</strong></summary>
Tất cả hành động ghi (tạo/sửa/xóa) đều yêu cầu <strong>xác nhận</strong> từ người dùng. Tool hỗn hợp sử dụng Dynamic Confirmation — chỉ yêu cầu xác nhận cho hành động ghi, hành động đọc thực thi ngay.
</details>

<details>
<summary><strong>Có hỗ trợ WooCommerce không?</strong></summary>
Có! Plugin tự động phát hiện WooCommerce và kích hoạt 3 tool: Product Manager, Order Inspector, Customer Inspector.
</details>

<details>
<summary><strong>Agent có thể thực hiện nhiều hành động liên tiếp?</strong></summary>
Có! Agent hỗ trợ Chain Actions — sau khi xác nhận một hành động, agent tự động tiếp tục ReAct loop để thực hiện các hành động tiếp theo.
</details>

<details>
<summary><strong>Có thể điều khiển agent qua Telegram không?</strong></summary>
Có! Bật Telegram trong cài đặt, thêm bot token và chat ID, rồi đăng ký webhook. Gửi tin nhắn cho bot và nó sẽ điều khiển WordPress, bao gồm xác nhận hành động qua nút inline keyboard.
</details>

## 📋 Changelog

### 1.0.0

- Initial release
- 12 built-in tools (9 WordPress core + 3 WooCommerce)
- Support for OpenAI (GPT-4o), Gemini (2.5 Flash/Pro), Anthropic (Claude Sonnet 4)
- Command Palette UI with `Ctrl+I` or `Ctrl+G` shortcuts
- Telegram Bot integration with inline keyboard confirmations
- Report & Analytics tool (dashboard, order/product/content reports)
- Tabbed settings UI (AI Provider, Web Research, Agent, Telegram)
- ReAct Loop engine with configurable max iterations
- DuckDuckGo web search (free, no API key needed)
- Dynamic Confirmation for mixed read/write tools
- Chain action execution — agent resumes loop after confirmation
- Session persistence via WordPress transients
- WooCommerce auto-detection and tool activation
- Context Provider with auto-injected site snapshot
- REST API with session management

## 📄 License

GPLv2 or later — [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

---

**Made with ❤️ by [DX Tech AI](https://github.com/dx-tech-ai)**
