=== Open Claw ===
Contributors: dxtechai
Tags: ai, agent, automation, woocommerce, chatbot
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Autonomous AI Agent for WordPress — executes real actions instead of just generating text.

== Description ==

**WP Open Claw** is an AI Agent plugin for WordPress, powered by a ReAct (Reason + Act) loop. The Agent doesn't just answer questions — it **executes actions directly** on your WordPress site.

= Key Features =

* 🤖 **Command Palette** — Open with `Ctrl+I`, `Ctrl+G` or `Ctrl+Shift+K`, modern glassmorphism chat interface
* 🧠 **ReAct Loop** — Agent reasons, selects tools, executes, observes results, and continues
* ✅ **Confirm Before Executing** — Data-changing actions require user confirmation
* 🔗 **Chain Actions** — Automatically performs sequential actions (e.g., create category then create multiple products)
* 🔌 **Multi LLM Provider** — Supports OpenAI (GPT-4o), Google Gemini (2.5 Flash/Pro), Anthropic Claude (Sonnet 4), Cloudflare Workers AI (Free)
* 🛒 **WooCommerce Ready** — Auto-detects WooCommerce and activates product, order, and customer management tools
* 💾 **Session Persistence** — Saves session state to resume after action confirmation
* 🔍 **Web Research** — Built-in web search via DuckDuckGo (free) or Google Custom Search
* 📱 **Telegram Bot** — Control your WordPress site via Telegram with inline keyboard confirmations

= 12 Built-in Tools =

**WordPress Core (9 tools):**

1. **Content Manager** (`wp_content_manager`) — Create/update posts with categories, tags, and HTML content
2. **System Inspector** (`wp_system_inspector`) — View site info, active plugins, categories, tags, post types
3. **Web Research** (`web_research_tool`) — Search the web via DuckDuckGo (free) or Google Custom Search
4. **Taxonomy Manager** (`wp_taxonomy_manager`) — Create/update/delete categories & tags
5. **Media Manager** (`wp_media_manager`) — Upload images from URL, set featured images, list/delete media
6. **Page Manager** (`wp_page_manager`) — Create/update/delete/list pages, supports templates & sub-pages
7. **User Inspector** (`wp_user_inspector`) — List users, view details, count by role
8. **Analytics Reader** (`wp_analytics_reader`) — Post stats by status, comment stats, content summary
9. **Report & Analytics** (`wp_report`) — Dashboard overview, order reports, product reports, content reports

**WooCommerce (3 tools — auto-activated when WooCommerce is active):**

10. **Product Manager** (`woo_product_manager`) — Full product CRUD (name, price, SKU, stock, images), manage product categories
11. **Order Inspector** (`woo_order_inspector`) — List/view orders, update status, revenue statistics
12. **Customer Inspector** (`woo_customer_inspector`) — List/search customers, customer stats, top customers

= Architecture =

* **ReAct Loop Engine** (`Kernel`) — Reason→Act→Observe loop with configurable max iterations
* **Dynamic Confirmation** — Mixed read/write tools only require confirmation for write actions
* **Context Provider** — Auto-injects site snapshot (categories, post types, user) into LLM context
* **Auto-Discovery** — Automatically discovers and registers tools from the `Actions/` directory
* **REST API** — Two endpoints: `/agent/chat` and `/agent/confirm` with session management
* **Telegram Controller** — Webhook-based bot with inline keyboard confirmations and session per chat

= Usage Examples =

**WordPress:**
* "Create a Technology category"
* "Write a blog post about AI trends 2025, save as draft"
* "Search the web for WordPress performance tips and write a summary post"
* "Show me site post statistics"
* "Create an About Us page with company introduction content"
* "Upload an image from URL and set it as featured image for the post"

**WooCommerce:**
* "Create 3 T-shirt products priced at 250,000"
* "Create a Fashion product category, then add 5 products to it"
* "Show me this month's revenue"
* "Update order #123 status to completed"
* "Find customers with 'gmail' in their email"
* "Show me the top 5 customers by total spending"

**Reports & Analytics:**
* "Show me dashboard overview"
* "Thống kê đơn hàng tháng này"
* "Sản phẩm bán chạy nhất"
* "Báo cáo bài viết và trang"

== Installation ==

**Method 1: ZIP Upload (Recommended)**
1. Download the latest ZIP from [GitHub Releases](https://github.com/dx-tech-ai/wp-open-claw/releases)
2. In WP Admin → Plugins → Add New → Upload Plugin
3. Choose the downloaded ZIP file and click Install Now
4. Activate the plugin
5. Go to **Open Claw** in the admin menu → configure your API key
6. Press `Ctrl+I` or `Ctrl+G` on any admin page to start using the agent

**Method 2: Manual Upload**
1. Download and extract the ZIP to `/wp-content/plugins/`
2. Activate the plugin in WP Admin → Plugins
3. Configure your API key in the Open Claw settings page

== Configuration ==

= LLM Provider =
Choose one of four providers:

* **Google Gemini (AI Studio)** — Free tier available at [aistudio.google.com](https://aistudio.google.com/apikey)
  * Models: Gemini 2.5 Flash (Free), Gemini 2.5 Flash Lite (Free), Gemini 2.5 Pro Preview, Gemini 2.0 Flash Lite
* **OpenAI** — API key required from [platform.openai.com](https://platform.openai.com)
  * Models: GPT-4o, GPT-4o Mini, GPT-4 Turbo
* **Anthropic** — API key required from [console.anthropic.com](https://console.anthropic.com)
  * Models: Claude Sonnet 4, Claude 3.5 Haiku
* **Cloudflare Workers AI** — API token required from [dash.cloudflare.com](https://dash.cloudflare.com)
  * Models: Qwen 2.5 72B (Best Vietnamese), Gemma 3 12B, DeepSeek R1 32B

= Web Search =
* Default: **DuckDuckGo** (free, no API key required)
* Optional: Google Custom Search (requires API key + Search Engine ID)

= Agent Settings =
* **Max Iterations** — Maximum ReAct loop iterations (1–20, default: 10)

= Telegram Integration =
Control the AI agent directly from Telegram:

1. Create a bot via [@BotFather](https://t.me/BotFather) on Telegram
2. Copy the Bot Token to **Open Claw → Telegram → Bot Token**
3. Add your Telegram Chat ID to **Allowed Chat IDs** (send a message to your bot, then use the Telegram Bot API `getUpdates` method to find your chat ID)
4. Click **Register Webhook** to connect your site to Telegram
5. Send messages to your bot — the AI agent will respond!

**Telegram Features:**
* Send natural language messages to control WordPress
* Inline keyboard buttons for action confirmations (Approve/Reject)
* Session persistence per chat — multi-turn conversations
* Automatic Markdown fallback if formatting fails
* Secure with secret token verification and chat ID whitelist

**Commands:**
* `/start` — Show help message
* `/reset` — Clear current session

== External Services ==

This plugin connects to third-party AI and search services to provide its core functionality. **No data is sent to any external service until the user explicitly configures an API key and initiates a request.**

= OpenAI API =
When OpenAI is selected as the AI provider, user prompts and WordPress site context are sent to the OpenAI API for processing.
* Service URL: [https://api.openai.com](https://api.openai.com)
* Terms of Use: [https://openai.com/terms](https://openai.com/terms)
* Privacy Policy: [https://openai.com/privacy](https://openai.com/privacy)

= Google Gemini (AI Studio) =
When Gemini is selected as the AI provider, user prompts and WordPress site context are sent to the Google Generative AI API.
* Service URL: [https://generativelanguage.googleapis.com](https://generativelanguage.googleapis.com)
* Terms of Use: [https://ai.google.dev/terms](https://ai.google.dev/terms)
* Privacy Policy: [https://policies.google.com/privacy](https://policies.google.com/privacy)

= Anthropic (Claude) =
When Anthropic is selected as the AI provider, user prompts and WordPress site context are sent to the Anthropic API.
* Service URL: [https://api.anthropic.com](https://api.anthropic.com)
* Terms of Use: [https://www.anthropic.com/terms](https://www.anthropic.com/terms)
* Privacy Policy: [https://www.anthropic.com/privacy](https://www.anthropic.com/privacy)

= Cloudflare Workers AI =
When Cloudflare is selected as the AI provider, user prompts and WordPress site context are sent to the Cloudflare API.
* Service URL: [https://api.cloudflare.com](https://api.cloudflare.com)
* Terms of Use: [https://www.cloudflare.com/website-terms/](https://www.cloudflare.com/website-terms/)
* Privacy Policy: [https://www.cloudflare.com/privacypolicy/](https://www.cloudflare.com/privacypolicy/)

= DuckDuckGo Search =
The web research tool uses DuckDuckGo's HTML search as the default search provider. Search queries are sent when the AI agent decides to perform web research.
* Service URL: [https://html.duckduckgo.com](https://html.duckduckgo.com)
* Terms of Use: [https://duckduckgo.com/terms](https://duckduckgo.com/terms)
* Privacy Policy: [https://duckduckgo.com/privacy](https://duckduckgo.com/privacy)

= Google Custom Search =
When configured, the web research tool can use Google Custom Search API instead of DuckDuckGo. Search queries are sent to the Google API.
* Service URL: [https://www.googleapis.com/customsearch](https://www.googleapis.com/customsearch)
* Terms of Use: [https://developers.google.com/terms](https://developers.google.com/terms)
* Privacy Policy: [https://policies.google.com/privacy](https://policies.google.com/privacy)

= Pexels API =
When Pexels API key is configured, the agent may search and download free stock photos from Pexels for blog post thumbnails. Search queries (based on your post titles or keywords) are sent to the Pexels API.
* Service URL: [https://api.pexels.com](https://api.pexels.com)
* Terms of Use: [https://www.pexels.com/terms-of-service/](https://www.pexels.com/terms-of-service/)
* Privacy Policy: [https://www.pexels.com/privacy-policy/](https://www.pexels.com/privacy-policy/)

= Unsplash API =
When Unsplash API key is configured, the agent may search and download free stock photos from Unsplash for blog post thumbnails. Search queries (based on your post titles or keywords) are sent to the Unsplash API.
* Service URL: [https://api.unsplash.com](https://api.unsplash.com)
* Terms of Use: [https://unsplash.com/terms](https://unsplash.com/terms)
* Privacy Policy: [https://unsplash.com/privacy](https://unsplash.com/privacy)

= Telegram Bot API =
When Telegram integration is enabled, the plugin sends messages to the Telegram Bot API to deliver responses and inline keyboards to the configured bot.
* Service URL: [https://api.telegram.org](https://api.telegram.org)
* Terms of Use: [https://telegram.org/tos](https://telegram.org/tos)
* Privacy Policy: [https://telegram.org/privacy](https://telegram.org/privacy)

== Frequently Asked Questions ==

= Is the plugin free? =
The plugin is completely free and open source. However, you need an API key from an LLM provider (Gemini offers a free tier).

= What PHP version is required? =
PHP 7.4 or higher is required.

= Can the agent delete data? =
All data-changing actions (create/update/delete) require **user confirmation** before execution. Mixed read/write tools use Dynamic Confirmation — only write actions need confirmation, while read actions execute immediately.

= Does it support WooCommerce? =
Yes! The plugin auto-detects WooCommerce and activates 3 additional tools: Product Manager, Order Inspector, and Customer Inspector.

= Can the agent perform multiple consecutive actions? =
Yes! The agent supports Chain Actions — after confirming an action, the agent automatically resumes the ReAct loop to perform subsequent actions (e.g., create category → create 3 products).

= Does it support Custom Post Types? =
Currently supports Posts, Pages, and WooCommerce Products. Custom Post Type support will be added in a future release.

= Can I control the agent from Telegram? =
Yes! Enable Telegram integration in the settings, add your bot token and chat ID, then register the webhook. You can send natural language messages to the bot and it will control your WordPress site, including action confirmations via inline keyboard buttons.

== Screenshots ==

1. Command Palette with glassmorphism interface
2. Agent executing chain actions with thinking steps
3. LLM Provider and Agent Settings configuration page
4. Action confirmation dialog before execution (Approve/Reject)

== Changelog ==

= 1.0.0 =
* Initial release
* 12 built-in tools (9 WordPress core + 3 WooCommerce)
* Support for OpenAI (GPT-4o), Gemini (2.5 Flash/Pro), Anthropic (Claude Sonnet 4), Cloudflare Workers AI
* Gemini multi-key rotation and Cloudflare failover mode
* Command Palette UI with Ctrl+I / Ctrl+G shortcuts
* Telegram Bot integration with inline keyboard confirmations
* Report & Analytics tool (dashboard, order/product/content reports)
* Tabbed settings UI (AI Provider, Web Research, Agent, Telegram)
* ReAct Loop engine with configurable max iterations
* DuckDuckGo web search (free, no API key needed)
* Action confirmation system with Dynamic Confirmation for mixed read/write tools
* Chain action execution — agent resumes loop after confirmation
* Session persistence via WordPress transients
* WooCommerce auto-detection and tool activation
* Context Provider with auto-injected site snapshot
* REST API with session management (`/agent/chat`, `/agent/confirm`)

== Upgrade Notice ==

= 1.0.0 =
First release — install and configure your preferred AI provider to get started. Gemini offers a free tier!
