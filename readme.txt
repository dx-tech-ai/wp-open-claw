=== WP Open Claw ===
Contributors: dxtechai
Tags: ai, agent, automation, woocommerce, chatbot
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Autonomous AI Agent for WordPress — executes real actions instead of just generating text.

== Description ==

**WP Open Claw** is an AI Agent plugin for WordPress, powered by a ReAct (Reason + Act) loop. The Agent doesn't just answer questions — it **executes actions directly** on your WordPress site.

= Key Features =

* 🤖 **Command Palette** — Open with `Ctrl+G`, modern glassmorphism chat interface
* 🧠 **ReAct Loop** — Agent reasons, selects tools, executes, observes results, and continues
* ✅ **Confirm Before Executing** — Data-changing actions require user confirmation
* 🔗 **Chain Actions** — Automatically performs sequential actions (e.g., create category then create multiple products)
* 🔌 **Multi LLM Provider** — Supports OpenAI (GPT-4o), Google Gemini (2.5 Flash/Pro), Anthropic Claude (Sonnet 4)
* 🛒 **WooCommerce Ready** — Auto-detects WooCommerce and activates product, order, and customer management tools
* 💾 **Session Persistence** — Saves session state to resume after action confirmation
* 🔍 **Web Research** — Built-in web search via DuckDuckGo (free) or Google Custom Search

= 11 Built-in Tools =

**WordPress Core (8 tools):**

1. **Content Manager** (`wp_content_manager`) — Create/update posts with categories, tags, and HTML content
2. **System Inspector** (`wp_system_inspector`) — View site info, active plugins, categories, tags, post types
3. **Web Research** (`web_research_tool`) — Search the web via DuckDuckGo (free) or Google Custom Search
4. **Taxonomy Manager** (`wp_taxonomy_manager`) — Create/update/delete categories & tags
5. **Media Manager** (`wp_media_manager`) — Upload images from URL, set featured images, list/delete media
6. **Page Manager** (`wp_page_manager`) — Create/update/delete/list pages, supports templates & sub-pages
7. **User Inspector** (`wp_user_inspector`) — List users, view details, count by role
8. **Analytics Reader** (`wp_analytics_reader`) — Post stats by status, comment stats, content summary

**WooCommerce (3 tools — auto-activated when WooCommerce is active):**

9. **Product Manager** (`woo_product_manager`) — Full product CRUD (name, price, SKU, stock, images), manage product categories
10. **Order Inspector** (`woo_order_inspector`) — List/view orders, update status, revenue statistics
11. **Customer Inspector** (`woo_customer_inspector`) — List/search customers, customer stats, top customers

= Architecture =

* **ReAct Loop Engine** (`Kernel`) — Reason→Act→Observe loop with configurable max iterations
* **Dynamic Confirmation** — Mixed read/write tools only require confirmation for write actions
* **Context Provider** — Auto-injects site snapshot (categories, post types, user) into LLM context
* **Auto-Discovery** — Automatically discovers and registers tools from the `Actions/` directory
* **REST API** — Two endpoints: `/agent/chat` and `/agent/confirm` with session management

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

== Installation ==

**Method 1: ZIP Upload (Recommended)**
1. Download the latest ZIP from [GitHub Releases](https://github.com/dx-tech-ai/wp-open-claw/releases)
2. In WP Admin → Plugins → Add New → Upload Plugin
3. Choose the downloaded ZIP file and click Install Now
4. Activate the plugin
5. Go to **Open Claw** in the admin menu → configure your API key
6. Press `Ctrl+G` on any admin page to start using the agent

**Method 2: Manual Upload**
1. Download and extract the ZIP to `/wp-content/plugins/`
2. Activate the plugin in WP Admin → Plugins
3. Configure your API key in the Open Claw settings page

== Configuration ==

= LLM Provider =
Choose one of three providers:

* **Google Gemini (AI Studio)** — Free tier available at [aistudio.google.com](https://aistudio.google.com/apikey)
  * Models: Gemini 2.5 Flash (Free), Gemini 2.5 Flash Lite (Free), Gemini 2.5 Pro Preview, Gemini 2.0 Flash Lite
* **OpenAI** — API key required from [platform.openai.com](https://platform.openai.com)
  * Models: GPT-4o, GPT-4o Mini, GPT-4 Turbo
* **Anthropic** — API key required from [console.anthropic.com](https://console.anthropic.com)
  * Models: Claude Sonnet 4, Claude 3.5 Haiku

= Web Search =
* Default: **DuckDuckGo** (free, no API key required)
* Optional: Google Custom Search (requires API key + Search Engine ID)

= Agent Settings =
* **Max Iterations** — Maximum ReAct loop iterations (1–20, default: 10)

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

== Screenshots ==

1. Command Palette with glassmorphism interface
2. Agent executing chain actions with thinking steps
3. LLM Provider and Agent Settings configuration page
4. Action confirmation dialog before execution (Approve/Reject)

== Changelog ==

= 1.0.0 =
* Initial release
* 11 built-in tools (8 WordPress core + 3 WooCommerce)
* Support for OpenAI (GPT-4o), Gemini (2.5 Flash/Pro), Anthropic (Claude Sonnet 4)
* Command Palette UI with Ctrl+G shortcut
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
