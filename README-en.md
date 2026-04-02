# WP Open Claw

🌐 **English** | [Tiếng Việt](README.md)

> Autonomous AI Agent for WordPress — executes actions instead of just answering text.

[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**WP Open Claw** is an AI Agent plugin for WordPress, powered by the **ReAct** (Reason + Act) loop. The agent doesn't just answer questions — it **directly executes actions** on your WordPress site.

## ✨ Key Features

| Feature | Description |
|---------|-------------|
| 🤖 **Command Palette** | Open with `Ctrl+I`, `Ctrl+G` or `Ctrl+Shift+K`, modern glassmorphism-style chat interface |
| 🧠 **ReAct Loop** | Agent reasons, selects tools, executes, observes results, and continues |
| ✅ **Confirm Before Executing** | Data-modifying actions require user confirmation |
| 🔗 **Chain Actions** | Automatically performs sequential chains of actions |
| 🔌 **Multi LLM Provider** | OpenAI (GPT-4o) · Google Gemini (2.5 Flash/Pro) · Anthropic Claude (Sonnet 4) · Cloudflare Workers AI (Free) |
| 🔄 **Multi-Key & Failover** | Gemini supports up to 5 API keys with round-robin rotation + auto failover to Cloudflare on quota exhaustion |
| 🛒 **WooCommerce Ready** | Auto-activates product, order, and customer management tools |
| 💾 **Session Persistence** | Saves session state via WordPress transients |
| 🔍 **Web Research** | Direct web search (free DuckDuckGo or Google CSE) |
| 💬 **Discord Bot** | Control WordPress from Discord slash commands with Approve/Reject buttons |

## 🛠️ 11 Built-in Tools

### WordPress Core (8 tools)

| # | Tool | Function Name | Capability |
|---|------|---------------|------------|
| 1 | **Content Manager** | `wp_content_manager` | Create/update posts with categories, tags, HTML |
| 2 | **System Inspector** | `wp_system_inspector` | View site info, plugins, categories, tags, post types |
| 3 | **Web Research** | `web_research_tool` | Search the web via DuckDuckGo or Google CSE |
| 4 | **Taxonomy Manager** | `wp_taxonomy_manager` | Create/edit/delete categories & tags |
| 5 | **Media Manager** | `wp_media_manager` | Upload images from URL, set featured image, list/delete media |
| 6 | **Page Manager** | `wp_page_manager` | Create/edit/delete/list Pages, supports templates & sub-pages |
| 7 | **User Inspector** | `wp_user_inspector` | View user list, details, statistics by role |
| 8 | **Analytics Reader** | `wp_analytics_reader` | Post statistics, comments, content overview |

### WooCommerce (3 tools — auto-activated)

| # | Tool | Function Name | Capability |
|---|------|---------------|------------|
| 9 | **Product Manager** | `woo_product_manager` | CRUD products (name, price, SKU, stock, images), manage categories |
| 10 | **Order Inspector** | `woo_order_inspector` | View orders, update status, revenue statistics |
| 11 | **Customer Inspector** | `woo_customer_inspector` | View/search customers, statistics, top customers |

## 🏗️ Architecture

```
wp-open-claw/
├── wp-open-claw.php          # Entry point, constants, hooks
├── src/
│   ├── Actions/              # 11 Tool implementations
│   │   ├── ContentTool.php
│   │   ├── SystemTool.php
│   │   ├── ResearchTool.php
│   │   ├── TaxonomyTool.php
│   │   ├── MediaTool.php
│   │   ├── PageTool.php
│   │   ├── UserInspector.php
│   │   ├── AnalyticsReader.php
│   │   ├── ProductTool.php     # WooCommerce
│   │   ├── OrderTool.php       # WooCommerce
│   │   └── CustomerTool.php    # WooCommerce
│   ├── Agent/
│   │   ├── Kernel.php          # ReAct loop engine
│   │   └── ContextProvider.php # Auto-inject site context
│   ├── LLM/
│   │   ├── ClientInterface.php
│   │   ├── OpenAIClient.php
│   │   ├── GeminiClient.php      # Multi-key rotation (5 keys)
│   │   ├── AnthropicClient.php
│   │   └── CloudflareClient.php  # Cloudflare Workers AI
│   ├── REST/
│   │   └── AgentController.php # REST API endpoints
│   ├── Admin/
│   │   ├── Settings.php        # Settings page
│   │   └── Dashboard.php       # Admin dashboard
│   └── Tools/
│       ├── ToolInterface.php
│       ├── DynamicConfirmInterface.php
│       └── Manager.php         # Tool registry & dispatcher
│   ├── Discord/
│   │   ├── DiscordController.php   # Discord interactions handler
│   │   ├── DiscordClient.php       # Discord REST API client
│   │   └── StepFormatter.php       # Format agent steps for Discord
├── assets/
│   ├── css/
│   └── js/
└── vendor/                     # Composer dependencies
```

### Workflow

```
User Input → Kernel.handle()
    ↓
System Prompt (+ ContextProvider snapshot)
    ↓
LLM Chat (OpenAI / Gemini / Anthropic / Cloudflare)
    ↓
┌─ Rate Limit (429) → Failover to Cloudflare (if configured)
│
├─ Text Response → Return to user
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

## 📦 Installation

**Option 1: Upload ZIP (Recommended)**
1. Download the latest ZIP from [GitHub Releases](https://github.com/dx-tech-ai/wp-open-claw/releases)
2. Go to WP Admin → Plugins → Add New → Upload Plugin
3. Select the ZIP file → Install Now → Activate
4. Go to **Open Claw** → configure your API key
5. Press `Ctrl+I` or `Ctrl+G` to get started!

**Option 2: Clone from GitHub**
```bash
cd /path/to/wp-content/plugins/
git clone https://github.com/dx-tech-ai/wp-open-claw.git

# Activate plugin in WP Admin → Plugins
# Go to Open Claw → configure API key
# Press Ctrl+I or Ctrl+G to get started!
```

## ⚙️ Configuration

### LLM Provider

| Provider | Models | Free Tier | Get API Key |
|----------|--------|-----------|-------------|
| **Google Gemini** | Gemini 2.5 Flash, Flash Lite, 2.5 Pro Preview | ✅ Yes (supports 5-key rotation) | [aistudio.google.com](https://aistudio.google.com/apikey) |
| **Cloudflare Workers AI** | Qwen 2.5 72B, Gemma 3 12B, DeepSeek R1 32B | ✅ Yes (generous free tier) | [dash.cloudflare.com](https://dash.cloudflare.com/profile/api-tokens) |
| **OpenAI** | GPT-4o, GPT-4o Mini, GPT-4 Turbo | ❌ No | [platform.openai.com](https://platform.openai.com) |
| **Anthropic** | Claude Sonnet 4, Claude 3.5 Haiku | ❌ No | [console.anthropic.com](https://console.anthropic.com) |

### Gemini Multi-Key Rotation

To avoid rate limits on the free tier, you can configure up to **5 API keys** from Google AI Studio. The plugin automatically rotates (round-robin) between keys and retries on 429 errors.

### Cloudflare Failover

If you configure Cloudflare Workers AI (Account ID + API Token), the plugin will **automatically switch to Cloudflare** when the primary provider (Gemini/OpenAI/Anthropic) hits rate limits. No need to select Cloudflare as primary — just fill in the credentials and failover works automatically.

### Web Search

- **Default**: DuckDuckGo (free, no API key required)
- **Optional**: Google Custom Search (requires API key + Search Engine ID)

### Agent Settings

- **Max Iterations**: Maximum ReAct loop iterations (1–20, default: 10)

### Discord Bot

1. Create an application in the Discord Developer Portal and add the bot to your server
2. Copy the `Bot Token`, `Application ID`, and `Public Key`
3. Open **Open Claw → Discord** in WordPress and fill in those values
4. Add `Allowed Channel IDs` and `Allowed User IDs` to restrict where and who can run the bot
5. Optionally add a `Guild ID` if you want faster slash command updates inside one Discord server
6. Expose your WordPress site over HTTPS, preferably with `ngrok` for local testing, and set:
   `https://your-domain/wp-json/open-claw/v1/discord/interactions`
   as the `Interactions Endpoint URL` in the Discord Developer Portal
7. Click **Register /openclaw Command**
8. Run `/openclaw run` in an allowed Discord channel

**Discord capabilities:**
- `/openclaw` reuses the same Kernel as the admin chatbox and Telegram
- Only users listed in `Allowed User IDs` can execute commands in approved channels
- Supports `Guild ID` for faster guild-scoped command updates during setup
- Discord receives a fast acknowledgement, then the bot posts results to the channel
- Write actions render **Approve** / **Reject** buttons
- Only the user who started the request can confirm it
- Sessions are persisted per `channel + Discord user`

## 💡 Usage Examples

### WordPress

```
"Create a category called Technology"
"Write a post about AI trends 2025, save as draft"
"Search the web about WordPress performance and write a summary post"
"Show me the post statistics on this site"
"Create an About Us page with company introduction content"
"Upload an image from URL and set it as featured image for the post"
```

### WooCommerce

```
"Create 3 T-shirt products priced at $25"
"Create a Fashion product category, then add 5 products to it"
"Show me this month's revenue"
"Update order #123 to completed status"
"Find customers with email containing 'gmail'"
"Show me the top 5 highest-spending customers"
```

### Discord

```
/openclaw run prompt: Show me site info
/openclaw run prompt: Create a category called Discord Test
/openclaw run prompt: Draft a post about WordPress performance
/openclaw reset
```

## ❓ FAQ

<details>
<summary><strong>Is the plugin free?</strong></summary>
The plugin is completely free and open source. You need an API key from an LLM provider (Gemini has a free tier).
</details>

<details>
<summary><strong>What PHP version is required?</strong></summary>
PHP 7.4 or higher is required.
</details>

<details>
<summary><strong>Can the agent delete data?</strong></summary>
All write actions (create/edit/delete) require <strong>user confirmation</strong>. Mixed tools use Dynamic Confirmation — only write actions require confirmation, read actions execute immediately.
</details>

<details>
<summary><strong>Does it support WooCommerce?</strong></summary>
Yes! The plugin automatically detects WooCommerce and activates 3 tools: Product Manager, Order Inspector, Customer Inspector.
</details>

<details>
<summary><strong>Can the agent perform multiple consecutive actions?</strong></summary>
Yes! The agent supports Chain Actions — after confirming one action, the agent automatically continues the ReAct loop to perform subsequent actions.
</details>

## 📋 Changelog

### 1.1.0

- **Gemini Multi-Key Rotation**: Support up to 5 API keys with round-robin rotation, auto-retry on 429 rate limit
- **Cloudflare Workers AI**: New free provider — Qwen 2.5 72B (best Vietnamese), Gemma 3 12B (fast), DeepSeek R1 32B (reasoning)
- **Auto Failover**: Automatically switch to Cloudflare when primary provider hits quota
- Fix: `render_text_field` supports provider toggle CSS class

### 1.0.0

- Initial release
- 11 built-in tools (8 WordPress core + 3 WooCommerce)
- Support for OpenAI (GPT-4o), Gemini (2.5 Flash/Pro), Anthropic (Claude Sonnet 4)
- Command Palette UI with `Ctrl+I` or `Ctrl+G` shortcuts
- Discord slash command integration with approval buttons
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
