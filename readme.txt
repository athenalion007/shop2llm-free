=== AI Visibility & LLMs.txt Generator — GEO, AEO & MCP for ChatGPT, Claude, Gemini & Perplexity ===
Contributors: athenalion
Tags: llms, ai-visibility, chatgpt, mcp, aeo
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.10.1
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your site readable for ChatGPT and AI search — auto-generated catalogs, visibility scores, and answers from your own content. Free, no account.

== Description ==

Activate the plugin and your site becomes readable to ChatGPT, Claude, Gemini and Perplexity. Shop2LLM automatically creates the catalog files AI assistants look for, lets them answer questions from your actual products and posts, and shows you a score for how visible you already are. Everything runs on your own server — no account, no API key.

= How it works =

1. **Activate** — your AI catalog (`yoursite.com/llms.txt`) is generated immediately. Open it in a browser and look.
2. **Connect** — click "Add to ChatGPT" or "Add to Claude": the button copies the right config and opens the right settings page. Paste, save, and ChatGPT can search your products, quote your return policy, and answer questions about your site. Claude and other AI tools work too.
3. **Improve** — every score item comes with a plain-English fix. Apply it and watch your score climb.

= What you get (free) =

* **Your site becomes readable to AI the moment you activate** — your complete AI catalog (products, posts, pages) is generated instantly, so ChatGPT and AI search can find and quote you.
* **ChatGPT answers buyers from your real content** — it searches your catalog, quotes your return policy, and recommends your products; Claude Desktop, Claude Code and Gemini CLI work the same way.
* **You know exactly what to improve** — four clear scores (whole site, per post, per product, AI-agent readiness) and every scored item shows a plain-English fix.
* **You watch AI send you visitors** — see which AI bots visited, what people asked AI about your site, and what AI sent back to you.
* **Your products carry accurate price and stock into AI answers** — WooCommerce stores also get a product feed for shopping AIs, a product score that catches fake discounts and stale out-of-stock listings, and an ACP Readiness score auditing what ChatGPT shopping reads.
* **AI coding agents read clean Markdown, not HTML soup** — every post, page and product is also available at its own .md URL, so Claude Code, Copilot and Cursor quote you accurately.
* **Your existing SEO stays untouched** — works alongside Yoast, Rank Math, AIOSEO and SEOPress without publishing the same tags or schema twice.
* **Every major AI bot is welcome at your door** — 51 named robots.txt invites, and Shop2LLM never blocks a crawler.

= Free vs Pro =

The free version includes everything above — the complete AI layer and all four scores.

Pro ($9/mo) adds cloud connections (Claude, Gemini, Copilot, Meta AI, Amazon Bedrock) so consumers can discover your products without any manual setup, the analytics dashboard, Checkout Kit, and GA4 data-layer integration.

= What Shop2LLM does not do =

* **No ranking guarantees.** No tool can force ChatGPT to recommend you. Shop2LLM makes your site readable and shows what to fix — the AI decides what to recommend.
* **No crawler blocking.** Shop2LLM invites good bots by name and never blocks them.

Shop2LLM works with any WordPress site, on localhost and staging too. Yoast SEO and Rank Math are trademarks of their respective owners; comparison based on publicly documented feature lists as of July 2026.

== Installation ==

1. Upload the `shop2llm` folder to `/wp-content/plugins/` (or install "Shop2LLM" directly via Plugins → Add New)
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → Shop2LLM
4. Click "Add to ChatGPT" — your AI connection URL is copied and the Connectors page opens
5. Paste and save

= Requirements =

* WordPress 6.0+
* PHP 8.0+
* WooCommerce 8.0+ (optional — only needed for product search features; posts and pages work on any site, multilingual sites included)

== Frequently Asked Questions ==

= Does Shop2LLM work as a WooCommerce MCP server? =

Yes. Shop2LLM exposes a full MCP (Model Context Protocol) server at `/wp-json/shop2llm/v1/mcp` with four JSON-RPC 2.0 tools: search_products, get_product, get_policies, and get_store_overview. ChatGPT connects via your site's AI connection URL and calls these tools like native tools. The MCP server works with WooCommerce product catalogs and — on sites without WooCommerce — with posts and pages.

= Is Shop2LLM an llms.txt generator for WordPress? =

Yes. Shop2LLM auto-generates `/llms.txt` (machine-readable content catalog), `/llms-full.txt` (complete content for agents), and `/agents.md` (structured Markdown dossier with your UCP score, MCP tools, and schema types) the moment you activate — no configuration, no cron, no external service. The catalog rebuilds automatically when you publish, update, or delete content. If another plugin writes a physical llms.txt file that shadows the endpoint, Shop2LLM detects the conflict and offers one-click remove or take-over.

= What is an llms txt file? =

An llms txt file (written "llms.txt") is a simple text file that tells AI assistants like ChatGPT, Claude and Perplexity what your site contains and where to read it. Shop2LLM creates and keeps it up to date for you automatically.

= How do I verify ChatGPT can read my llms.txt? =

Three checks, under a minute each:

1. Open `yoursite.com/llms.txt` in a browser — you should see your content catalog (works immediately after activation)
2. In ChatGPT, add your AI connection URL (Settings → Connectors) and ask "search my site for [a real product or post title]" — the answer should quote your actual content
3. Open Shop2LLM → AI Traffic and watch the AI Crawler Activity card — GPTBot, ClaudeBot, or PerplexityBot visits appear there in real time

No crawler visiting yet? Run the Activation Doctor (`wp eval-file wp-content/plugins/shop2llm/tools/activation-doctor.php`) — it prints your onboarding funnel and points at the bottleneck (most often Cloudflare's "AI Scrapers and Crawlers" block, or a firewall returning 403 to bot user agents).

= Where can I see which pages appear in AI answers? =

Google Search Console's official AI display data shows which of your pages appear in AI answers — free, globally available since August 2026. Shop2LLM's dashboard card links you straight to it and helps you read it: high AI impressions with few clicks → improve those pages with the AI Score card; pages that never appear → check they are listed in /llms.txt.

= Which AI crawler controls does Shop2LLM add to robots.txt? =

Shop2LLM appends 51 explicit `Allow: /` invite directives to robots.txt — GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, PerplexityBot, Google-Extended, Applebot-Extended, Amazonbot, Bytespider, meta-externalagent and 41 more. It never blocks bots — these are invitations. You can additionally declare a site-wide AI usage policy (Training / Summarization / Attribution) as an opt-in banner at the top of robots.txt, including the standard Content-Signal directive (contentsignals.org) with its search / ai-input / ai-train signals and the "use" preference field, off by default (Settings → Shop2LLM → AI Agents).

= Can Shop2LLM expose my members-only content to AI? =

No. Every AI-facing content read — /llms.txt, /llms-full.txt, per-page .md mirrors and every MCP tool — renders your content exactly the way a logged-out visitor sees it, so membership and paywall plugins keep hiding protected sections from AI agents just like they do from anonymous visitors. Password-protected posts and pages are excluded from AI outputs entirely.

= Does Shop2LLM create a ChatGPT product feed? =

Yes — three ways. (1) Product JSON-LD schema on every product page: name, price, availability, GTIN/MPN/brand, ratings. (2) A Google-Merchant-format XML feed at `/product-feed.xml` (WooCommerce stores; shopping AI platforms index it directly). (3) The MCP search_products tool, which lets ChatGPT query your live catalog at conversation time so answers use current prices and stock. An optional cloud Product Sync (off by default) additionally lists your products in the Shop2LLM AI search index.

= How do I get my products into ChatGPT shopping? =

ChatGPT's shopping results are built from structured product data, and merchants apply directly at chatgpt.com/merchants. Shop2LLM prepares what that application reads: product JSON-LD with price, availability and GTIN, the /product-feed.xml feed, and an ACP Readiness score on the dashboard that audits the five fields shopping platforms require (title, description, price, availability, image) across your catalog. No plugin can guarantee a listing — OpenAI reviews each merchant — but you control whether the data they review is complete.

= Does Shop2LLM serve Markdown versions of my pages to AI agents? =

Yes. Every published post, page and product is additionally readable at yoursite.com/your-slug.md, and any page returns clean Markdown when the client sends Accept: text/markdown — coding agents like Claude Code, Copilot workspace and Cursor send that header automatically. Markdown mirrors carry X-Robots-Tag: noindex, so search engines never index them as duplicate content.

= How does the AI visibility score work? =

Four scores, all computed on your server. The site-wide AI Visibility Score™ has 10 factors / 100 points: robots.txt invites, llms.txt health, llms-full.txt reachability, JSON-LD schema, MCP endpoint, product feed (WooCommerce), AI search queries, AI crawler activity, store policies, multilingual readiness. The post-editor Content Readiness audit has 25 items in five groups: Discoverability, Relevance, Content Quality, Entity Authority, Activity. WooCommerce products get an 11-dimension Product AI Score, and the UCP Readiness Score (5 dimensions, 100 points) measures agentic-commerce readiness (Catalog API, MCP & Agentic, llms.txt, Schema, robots.txt). Every item shows a fix action.

= What is the AI Visibility card, and what data does it send? =

The AI Visibility card (dashboard and wp-admin widget) shows whether AI assistants like ChatGPT or Perplexity mention your store for buyer-intent searches. It is part of the optional Shop2LLM analytics (off by default): when you enable analytics in Settings, your site URL and a set of search prompts generated for your store are sent to api.shop2llm.com, where automated checks look for mentions of your domain in AI answers. Results are stored under your site's hashed identifier; no customer data, order data, or post content is sent. No tool can force an AI assistant to recommend you — the card shows what the checks actually find.

= Can I add JSON-LD schema without an SEO plugin? =

Yes — 19 JSON-LD types are auto-detected and emitted with zero configuration: Product, Article, BlogPosting, FAQ, HowTo, Recipe, Event, Course, JobPosting, SoftwareApplication, Book, MusicRecording, VideoObject, Person, Dataset, Review, LocalBusiness, WebPage, BreadcrumbList. If Yoast, Rank Math, AIOSEO, or SEOPress is active, Shop2LLM detects it and disables overlapping schema modules so nothing is emitted twice.

= How is Shop2LLM different from RankReady and other AI visibility plugins? =

Tracking tools measure how you rank in AI answers; Shop2LLM builds the server-side layer those answers draw from — llms.txt, MCP server, JSON-LD schema, 51 crawler invites, and visibility scores, all running locally with no account. Because it coexists with your existing SEO plugin (auto-deduplicating overlapping modules), it can run alongside tracking tools rather than replacing them.

= Do I need to create an account or configure an API key? =

No. All core features (llms.txt, /agents.md, MCP tools, JSON-LD schema, crawler tracking, visibility scores) run entirely on your WordPress server and start working on activation — no account, no cloud signup, no API key. The optional Hero Test Search uses our cloud LLM proxy with 5 free queries per day, also without an account. All AI connections to your site use Bearer token authentication; the key is auto-generated and can be rotated anytime.

= Does this require WooCommerce? =

No. Shop2LLM works on any WordPress site out of the box — your posts and pages are fully included in the AI catalog (llms.txt, BlogPosting schema, MCP search). If WooCommerce 8.0+ (free) is active, product search, price and stock data, Product schema, and the /product-feed.xml XML feed join the catalog automatically.

= Is my content data sent to external servers? =

Content data (full product descriptions, customer data, orders) stays on your server. AI agents connect directly to your WordPress REST API for detailed product information. External transmission is opt-in and off by default:

* **Product Sync** (opt-in) sends lightweight product metadata to the Shop2LLM cloud search index — product ID, title, price, short description/excerpt, image URL, public product URL, categories, site name, and stock status. All of it is already publicly visible on your website; no customer, order, or credential data is sent. Refreshed daily; disable anytime in Settings → Shop2LLM → Product Sync or via `add_filter('shop2llm_disable_product_sync', '__return_true');`
* **Anonymous Usage Data** (opt-in) sends a bucketed, boolean-only onboarding summary (e.g. `first_search_done: true`, `dashboard_visits: "2-5"`) with a SHA-256 hashed site identifier — no PII, no exact counts, no URLs, no search terms
* **Crawler reports** (included with Anonymous Usage Data) send crawler type, page URL, and user agent when AI bots visit
* **Hero Test Search** sends your search query and lightweight product metadata (name, price, currency, stock, rating) to DeepSeek's API to generate the AI reply
* **Wikidata/Wikipedia lookups** (opt-in, off by default) send only your focus query text to those public APIs

Disabling the opt-ins stops all external transmission immediately. Local behavior milestones (activation funnel) stay in your own database and are removed on uninstall. Privacy policy: https://shop2llm.com/

= What's the difference between Free and Pro? =

Free works with ChatGPT plus any MCP-compatible client — Claude Desktop, Claude Code, Gemini CLI, and more — via your connection URL, with 4 AI tools (content search, detail lookup, store policies, store overview) plus everything listed above. Pro adds cloud connections (Claude, Gemini, Copilot, Meta AI, Amazon Bedrock) so consumers can discover your products without any manual connector setup, plus the analytics dashboard and Checkout Kit. Upgrade at https://shop2llm.com/

= What happens if I deactivate the plugin? =

Your site returns to normal. llms.txt, robots.txt AI rules, schema markup, and the product feed are removed. All settings are preserved if you reactivate later. No data is lost.

= Does this affect my site speed? =

No. Shop2LLM is independently tested and **A-rated on [MakeWPFast.com](https://makewpfast.com/plugins/shop2llm/)** — a third-party WordPress speed certification service. The benchmark report covers Home, Blog, and Product page contexts and rates overall plugin impact as "Low" (measured TTFB, memory delta, and DB query delta against a clean WordPress baseline). All AI features run on your server through the standard WordPress REST API; external calls are non-blocking with 1s timeouts — even if our cloud is unreachable, your site stays fast.

= Does llms.txt work with caching plugins? =

Yes. llms.txt and llms-full.txt are served with `Cache-Control: no-cache` headers, so caching plugins (LiteSpeed Cache, WP Rocket, W3 Total Cache) and edge CDNs like Cloudflare never serve a stale copy after your catalog changes. With default settings no configuration is needed; if you run an aggressive "cache everything" edge rule, add an exclusion for llms.txt.

= Does Shop2LLM work with Gemini and Claude, or only ChatGPT? =

All of them. The 51 named robots.txt invites include Google-Extended (which powers Gemini groundings), ClaudeBot and Claude-SearchBot (Anthropic), so Gemini and Claude can discover and read your catalog. The MCP endpoint is the native protocol of Claude Desktop and Claude Code, and also connects from Gemini CLI and any other MCP-compatible client. The llms.txt and llms-full.txt catalogs are plain markdown every AI engine can read.

= Can I use this alongside Yoast SEO or Rank Math? =

Yes. Shop2LLM auto-detects SEO plugins (Yoast, Rank Math, AIOSEO, SEOPress) and automatically disables conflicting modules (meta tags, schema, Open Graph). You keep the AI-specific features (MCP tools, llms.txt, crawler invites, visibility scores) and the other plugin keeps handling classic SEO.

== Screenshots ==

1. AI Visibility Dashboard — real-time AI Score with 10-factor breakdown, Hero Test Search, and live llms.txt / llms-full.txt / robots.txt / Product Feed status cards
2. UCP Readiness Score — 5-dimension agent-readiness audit (Catalog API, MCP & Agentic, llms.txt, Schema, robots.txt)
3. AI Traffic Monitor — crawler analytics across 81 AI bots and AI search activity

== Changelog ==

= 1.10.1 =
* New: Self-hosted update channel — GitHub-channel zips check shop2llm.com for updates (site URL and plugin/WP/PHP versions are sent with each check and disclosed on shop2llm.com/install). The wordpress.org package contains no update-check or telemetry code: wordpress.org installs keep using the official update channel exactly as before

= 1.10.0 =
* New: One-click connect — "Add to ChatGPT" and "Add to Claude" buttons copy the right config and open the right settings page; connection drops from four steps to two
* New: Official AI display data card — step-by-step guidance to read your AI-answers data in Google Search Console (free, official, since August 2026)
* Improvement: "Verify connection" now lives in Settings too — a real MCP handshake answers in place, and the first successful verify says thank you

= 1.9.0 =
* New: The ten tools now register as official WordPress Abilities (shop2llm namespace) — when the official MCP Adapter plugin is active they are exposed through its default server automatically and the built-in /mcp endpoint retires, so tools are never served twice
* New: The built-in MCP endpoint now speaks the MCP 2026-07-28 revision — server/discover, per-request version negotiation with UnsupportedProtocolVersionError, and Streamable HTTP protocol-version headers; existing clients keep working unchanged
* Improvement: /​.well-known/mcp discovery, llms.txt, agents.md, .md mirrors and the store overview now always point AI clients at the correct endpoint for the detected stack (official adapter or built-in fallback)

= 1.8.0 =
* Security: AI-facing content is now rendered exactly as a logged-out visitor sees it — members-only and paywalled sections are never exposed to AI agents
* Security: Password-protected posts and pages are excluded from /llms.txt, /llms-full.txt, .md mirrors and all MCP tools
* New: The optional AI usage policy in robots.txt now carries the machine-readable Content-Signal "use" preference (immediate / reference / full) alongside search, ai-input and ai-train — a declaration, not an enforcement

= 1.7.3 =
* Fix: Translation domain unified with the plugin slug so community translations load correctly
* Improvement: All admin icon output escaped through a strict SVG whitelist

= 1.7.2 =
* New: Deleted content is now reported to search engines automatically, so stale pages leave AI indexes faster (IndexNow removal push)
* New: Failed notifications are retried automatically (up to 3 times, 5 minutes apart) — no setup needed
* Improvement: robots.txt AI invitations now grouped by what they unlock — citations, lookups, or training — in plain English
* Improvement: Larger catalogs no longer exceed the IndexNow 10,000-URL per-request limit

= 1.7.1 =
* New: Search engines notified automatically — Bing, Yandex and more are pinged on activation and every content change (IndexNow), no setup needed
* New: Index Push status card shows your last automatic notification at a glance
* Improvement: On your first dashboard visit the AI catalog test search now runs itself, so you see AI answers from your site with zero clicks

= 1.7.0 =
* New: Markdown mirrors — every post and product is readable at yoursite.com/slug.md and via Accept: text/markdown
* New: ACP Readiness score — dashboard card audits the five catalog fields ChatGPT shopping reads before you apply at chatgpt.com/merchants
* New: /.well-known/mcp-server discovery manifest — AI agents connecting to your domain find the MCP endpoint automatically
* Improvement: AI usage policy now emits the standard Content-Signal directive (contentsignals.org) alongside the robots.txt banner

= 1.6.1 =
* Fix: Activation can no longer fatal or time out on restricted shared hosting — setup now runs on first dashboard load
* New: Value no longer depends on the cloud — if the AI preview is unreachable, the dashboard shows a live excerpt of your /llms.txt
* Lean: Classic SEO extras removed (sitemap, redirects, 404 log, migrations, audits) — core and your SEO plugin cover them; all AI features kept, codebase −27%
* Lean: Admin tabs 6 → 5, UCP score 7 → 5 dimensions, and the Pro card now lists exactly what Pro adds
* Docs: Activation Doctor command path corrected

= 1.6.0 =
* New: /llms-full.txt — your entire catalog readable in one fetch, with a languages section for multilingual sites
* New: Activation proof strip — three green status cards (llms.txt, robots.txt, product feed) with direct View links
* Improvement: One-click fixes for every score item, higher rate limits, per-crawler leaderboard, 7-day search history, cache-safe llms.txt delivery

= 1.5.7 =
* Improvement: Minimum PHP lowered from 8.1 to 8.0 to match the code's real floor — sites running PHP 8.0 can now install the plugin from wp-admin

= 1.5.6 =
* New: Value-moment rating loop — a ★★★★★ ask appears inline right after your first successful AI test search, again in AI Traffic when the first real crawler lands, and a day-30 fallback (2 lifetime asks, dismiss = re-ask in 30 days)
* Improvement: Directory listing rewritten around search keywords — vendor-by-vendor crawler invite list, per-tool MCP server guide, ChatGPT product feed section, 8 new keyword FAQs
* Improvement: Plugin renamed to "AI Visibility & LLMs.txt Generator — GEO, AEO & MCP for ChatGPT, Claude & Perplexity" and retagged for AI search discovery
* Fix: Removed the broken v1.3.5 dark-mode stylesheet — with an OS-level dark theme, cards went dark and the active tab / page title turned near-white on the white admin background. The admin UI now always matches the standard wp-admin light theme

= 1.5.5 =
* New: MCP tool surface grows from 2 to 4 tools
* New: get_policies — machine-readable returns/shipping/tax/terms/privacy pages with MerchantReturnPolicy JSON-LD that AI commerce agents can quote verbatim
* New: get_store_overview — first-call orientation: latest products and posts, categories, available tools and endpoints (15-minute cache)
* New: Opt-in AI usage policy in robots.txt — Training / Summarization / Attribution modes, off by default (Settings → Shop2LLM → AI Agents)

= 1.5.4 =
* New: Physical llms.txt conflict detection — one-click Remove / Overwrite when another plugin's static llms.txt file silently shadows Shop2LLM's dynamic endpoint
* Fix: /llms.txt, /agents.md and /llms-full.txt respond reliably even when another plugin hijacks the same rewrites — URI-level fallback that outruns activation order, plus rules registered before the activation flush
* Fix: Search metrics — Hero self-tests tracked separately (no longer inflate "API Calls" / MCP score), identical searches collapsed to one row, out-of-stock products found by exact name
* Removal: Google Feed factor dropped from the AI Visibility Score™ (9 → 8 factors); weights rebalanced to keep the /100 scale; "Acquisition Funnel" renamed to "Acquisition Channels"
* Improvement: Admin UX — notices confined to Shop2LLM pages, review ask moved to Dashboard Overview, AI Traffic tab merged, Yoast/Rank Math one-click migration banner, "Website" header link

= 1.5.3 =
* New: Shop2LLM now installs and activates on any WordPress site — WooCommerce is no longer a hard requirement (product features auto-activate when WooCommerce is present)
* New: The AI Visibility verification page now shows a prominent "Get it free on WordPress.org" card
* New: The first successful AI search confirmation banner includes a direct ★★★★★ rating link on WordPress.org

= 1.5.2 =
* Improvement: SEO settings (meta tags, sitemap, schema, redirects, title templates, Google Search Console) now live in their own SEO → SEO Settings tab, right where you'd look for them
* Improvement: The SEO tab's former "SEO Settings" dashboard is renamed "SEO Overview" to match what it actually shows
* Improvement: The optional anonymous usage data setting is now clearly labeled "Anonymous Usage Data" (previously "Analytics")

= 1.5.1 =
* Fix: Activation onboarding now completes — the first successful Hero Test Search marks onboarding done (previously the welcome funnel could never complete, so activation progress was invisible)
* Fix: Hero Test Search moved to the top of the Dashboard (above the value comparison card) so the "aha" moment is above the fold
* New: Hero Test Search comes pre-filled with your newest product (or post) title, and runs automatically right after activation — see your first AI answer in under a minute, zero typing
* Fix: Activation auto-search previously never ran due to a JavaScript error; it now uses the pre-filled catalog query
* New: One-time "AI can see your store" confirmation banner on your first successful AI search

= 1.5.0 =
* New: Acquisition Funnel — see how AI crawlers → AI referral sessions → AI-attributed orders → AI revenue connect in one card on the AI Traffic tab (last 30 days, all data stays on your server)
* New: AI order attribution — visitors arriving from ChatGPT, Claude, Perplexity, Gemini, Copilot, Grok, DeepSeek are remembered (session + first-party cookie, no personal data) and their orders tagged automatically; same meta keys as Pro's agentic checkout
* New: AI source detection extended to chatgpt.com, claude.ai, gemini.google.com, grok.com and chat.deepseek.com (also improves the existing AI-Driven Traffic stats)
* Pro: Per-platform revenue breakdown (revenue by AI platform) in the funnel card is a Pro feature

= 1.4.0 =
* New: Store Policies JSON-LD — returns, shipping, tax, terms and privacy pages now emit machine-readable schema (MerchantReturnPolicy, OfferShippingDetails) that AI commerce agents like Claude read when answering policy questions; llms.txt gains a Store Policies section pointing agents at those pages
* New: Site AI Visibility Score grows from 7 to 9 factors — adds Policies (10 pts) and Google Merchant Center feed health (10 pts); existing factor weights rebalanced to keep the /100 scale
* New: Product AI Score grows from 9 to 11 dimensions — adds Sale Price Consistency (flags fake discounts where sale ≥ regular) and Stock Status Current (flags out-of-stock listings untouched for 90+ days, and variable products with no purchasable in-stock variation); free tier max is now 70
* Fix: Robots.txt / llms.txt factor thresholds updated for the new weighting

= 1.3.14 =
* Fix: AI crawler detection now matches the most specific bot first — "Googlebot-Image" is no longer misattributed to the shorter "Googlebot" pattern (same fix for PerplexityBot-1, Applebot-Extended, Amazonbot-Image, Bytespider-Image)
* Docs: Corrected listing accuracy — 2 MCP tools (not 23), 81 crawlers tracked (not 65), 51 robots.txt AI bot allow directives (not 12); dashboard and UCP check copy now show live counts

= 1.3.13 =
* Docs: Description now also references GEO (Generative Engine Optimization) and AEO (Answer Engine Optimization)

= 1.3.12 =
* Fix: Documentation corrected — Shop2LLM requires WooCommerce 8.0+ (free) and PHP 8.1+; earlier listing text wrongly suggested the plugin also works without WooCommerce

= 1.3.11 =
* Feature: AI Score 7-day trend — daily snapshots with an up/down delta badge on the dashboard
* UX: Smarter onboarding — wizard steps now show real completion states (green ✓) for published content, ChatGPT connection, llms.txt/robots.txt readiness and first AI activity, each with a "Fix in AI Score card" shortcut, plus a gentle day-7 review reminder (permanent dismissal, no incentives)
* UX: Hero Test Search results now end with a value-closure footer and a one-click jump to the AI Score card; an empty AI Traffic tab is now a 3-point diagnostic (robots.txt / llms.txt / published content) with inline fix actions

= 1.3.10 =
* Fix: Product schema no longer replaced by meta-driven schema types on product pages — when FAQ/HowTo data is present, the Product JSON-LD (offers.price, GTIN, rating) is always emitted, with the extra type added as an additional block

= 1.3.9 =
* UX: AI Score auto-fix actions (Fix robots.txt, Flush Permalinks, etc.) now gated behind Pro upgrade — free users see diagnosis + 🔒 upgrade hooks
* UX: Product AI Score dimensions 6-9 (Categories, Tags, Stock Managed, Attributes) locked behind Pro — free users see 5/9 dimensions (max 65/100)
* UX: Content AI Score L2-L5 groups (Relevance, Content Quality, Entity Authority, Activity) locked behind Pro — free users see L1 only (max 30/100)
* UX: UCP Readiness Checkout Kit dimension locked behind Pro — shows 🔒 upgrade prompt for AI checkout capability
* UX: Score displays adjusted for free tier (e.g., `/65` instead of `/100`) with "Unlock full score →" upgrade links

= 1.3.8 =
* UX: Merged `Redirects` and `Sitemap` sub-menus into the main `Shop2LLM → SEO` tab as sub-tabs. The WP admin sidebar now shows a single `Shop2LLM` entry instead of three. Old URLs (`?page=shop2llm-seo-redirects`, `?page=shop2llm-seo-sitemap`) auto-redirect to the new sub-tab locations.
* UX: Added `nav-tab` sub-navigation inside SEO tab (`SEO Settings` / `Redirects` / `Sitemap`).

= 1.3.7 =
* Fix: MCP `initialize` returns dynamic `protocolVersion` (echoes client-requested version, defaults to `2025-06-18`) instead of hardcoded `2025-11-25`
* Fix: MCP `notifications/*` requests now return HTTP 202 with empty body per MCP 2025-06-18 spec (previously returned `-32601 Method not found`)
* Fix: MCP `tools/call` results now wrapped in `content[] + structuredContent` structure per spec
* Fix: Discovery manifest `mcp.version` synchronized to `2025-06-18`

= 1.3.6 =
* New: 6-tab Dashboard — Overview, AI Agents (UCP Score + MCP + /agents.md + 65 crawlers), AI Traffic (crawler analytics + GA4 + AI search), SEO, Analytics, Settings
* Fix: UCP Score gauge and visual compatibility improvements for broader PHP/WP version support

= 1.3.5 =
* New: GA4 AI Traffic + Crawler Log Analytics (65 crawlers, opt-in) + CJK Content Optimizer (Chinese/Japanese/Korean N-gram analysis)
* New: Dashboard visual refresh — UCP Score radial gauge, design tokens, empty states, micro-interactions, mobile-responsive layout, dark mode
* Fix: All Plugin Check warnings resolved to zero; security hardening (wp_safe_redirect, nonce verification)

= 1.3.4 =
* New: UCP Readiness Score (7 dimensions, 100 points) + /agents.md endpoint + Live Content Extractability (10-dimension editor score)
* Expanded: AI Crawler Registry from 12 to 65 tracked bots

= 1.3.3 =
* Privacy: product sync is now opt-in via Settings (WP Guideline 7 compliance) — no data sent on activation without consent
* Added Restart Onboarding button in Settings and PHP/WP version check on activation
* Fix: `heroRemaining` JavaScript error on activation (props WP.org Plugin Tests)

= 1.3.2 =
* Value Comparison Card — new Dashboard card showing the SEO and AI features included free, with a factual comparison to Yoast SEO Premium and Rank Math Pro paid tiers
* Activation Doctor — self-serve WP-CLI diagnostic (`wp eval-file tools/activation-doctor.php`) for onboarding funnel, connectivity checks, and Site Hash for fast support
* Directory Listing Refresh — AI visibility tags, sharper description, third-party performance benchmark disclosure (MakeWPFast.com A-rated)

= 1.3.1 =
* Multi-Keyword Optimization — unlimited focus keywords with independent per-keyword scoring and AI-powered keyword suggestions
* AI-Powered Schema Detection — cloud AI suggests best schema type with local fallback, 4-layer rate limit protection
* WooCommerce Deep Integration — GTIN/MPN/Brand/Condition identifiers, enhanced Product Schema, 6 product-specific checks

= 1.3.0 =
* Full SEO Engine — Meta Tags, Open Graph, XML Sitemap, Schema, Content Analysis, Redirect Manager, SEO Audit, all built-in and free
* Google Search Console Integration — clicks, impressions, CTR, position, top keywords dashboard
* 19 JSON-LD Schema Types with Title Template System and One-Click Migration from Yoast/RankMath

= 1.1.2 =
* Plugin Check zero ERROR compliance — text domain unified to 'shop-to-llm', enabling community translations
* AI-powered "See how AI sees your store" — test how AI recommends your products, 5 free test searches per day
* DeepSeek API integration — natural-language product recommendations with transparent provider disclosure

= 1.1.1 =
* Hero Test Search — AI-powered Aha moment at Dashboard top, type a question and see how AI recommends your products
* FinCore cloud LLM proxy — test searches use DeepSeek via FinCore cloud, no API key required
* Rich product cards — image thumbnail, currency-aware price, stock badge, star rating

= 1.1.0 =
* Product AI Index — syncs catalog metadata to Shop2LLM cloud so your products appear in AI searches across all connected stores
* Daily product sync via heartbeat with opt-out filter and layered privacy disclosure

= 1.0.9 =
* Welcome Modal simplified to single card with opt-in

= 1.0.8 =
* AI Visibility Score Breakdown — 7-factor card with per-item Fix buttons
* Inline Test Search and Content AI Score for non-WooCommerce sites
* Plugin Check zero ERROR compliance, Tested up to WP 7.0

= 1.0.7 =
* Shop2LLM Visibility Score™ — 5-dimension 25-item scoring system
* AI Crawler Activity Monitor with 7-day sparkline trends
* Editor meta box with score ring gauge and fix guidance

= 1.0.6 =
* Dashboard load time 80x faster (5s → 62ms)
* Works without WooCommerce — supports any WordPress site
* PHP 7.4+ compatibility (lowered from 8.1)

= 1.0.5 =
* AI Growth Progress card with milestone tracker and Achievement Badges

= 1.0.4 =
* Anonymous activation ping with SHA256-hashed site identifier

= 1.0.3 =
* Improved search keywords and short description

= 1.0.2 =
* HPOS compatibility, WooCommerce Marketplace support

= 1.0.1 =
* Security fixes: permission_callback, input sanitization, output escaping

= 1.0.0 =
* Initial release — MCP JSON-RPC, ChatGPT integration, llms.txt, AI crawler support

== Upgrade Notice ==

= 1.7.1 =
Search engines now find you automatically — on activation and every content change, Bing/Yandex/etc. are notified (IndexNow, zero setup), and your first dashboard visit runs the AI test search for you. See the new Index Push card. No breaking changes.

= 1.7.0 =
New discovery surfaces for the agentic-search era: every page now serves clean Markdown to AI coding agents (.md mirror plus Accept-header negotiation), your domain answers /.well-known/mcp-server so agents self-configure the MCP connection, and the dashboard gains an ACP Readiness score auditing the exact catalog fields ChatGPT shopping reads (WooCommerce stores). The robots.txt AI usage policy now also speaks the standard Content-Signal format. No breaking changes. Safe to update.

= 1.6.1 =
Activation hardening: activating can no longer fail on restricted shared hosting. Activation failed on an older version? Update, then click Activate again — it will work now. The Hero Test Search also proves your site is AI-readable with a local /llms.txt excerpt even when the cloud preview is down. Also a lean release: classic SEO plumbing (redirect manager, sitemap, Search Console, audit, migration, broken links) is removed — core sitemap plus your SEO plugin already cover it; Shop2LLM keeps 100% of the AI layer. No data loss. Safe to update.

= 1.5.3 =
No WooCommerce? No problem — Shop2LLM now activates on any WordPress site and makes your posts and pages discoverable by AI search. WooCommerce stores keep everything as before. No breaking changes.

= 1.5.0 =
Your dashboard now shows the full AI acquisition funnel — from AI crawler visits to AI-attributed orders and revenue, recorded locally with zero external requests. Attribution data flows into Pro's AI Revenue tab when Pro is installed. No breaking changes.

= 1.4.0 =
Your returns/shipping policy pages become machine-readable for AI agents (Claude), the site AI Score now tracks Google Merchant Center feed health, and product scores catch fake discounts and stale out-of-stock listings. Weights rebalanced (still /100); no breaking changes.

= 1.3.0 =
Major feature release — full SEO engine with Meta/OG/Schema/Sitemap/Redirect/Content Analysis, 18 JSON-LD types, SEO audit, one-click migration from Yoast/Rank Math. Auto-detects existing SEO plugins and disables conflicting modules. No breaking changes. Safe to update.

= 1.1.2 =
Plugin Check compliance release — text domain unified, AI test search powered by DeepSeek, all WARNINGs resolved. No breaking changes.

= 1.1.1 =
Adds a ChatGPT-style Hero Test Search box at the top of the Dashboard. Get an instant AI-generated answer about your own products/content — 5 free tests per day, no API key needed. Existing Inline Test Search and score-card "Test Search" button now redirect to this new Hero box.

= 1.1.0 =
Adds lightweight product metadata sync to cloud AI search index. Opt-out available. Readme updated with full data disclosure.

= 1.0.9 =
Daily heartbeat, simplified Welcome Modal, behavior metrics fix. No breaking changes. Safe to update.

= 1.0.8 =
AI Visibility Score Breakdown, Inline Test Search, Welcome Modal, Analytics telemetry fix. No breaking changes. Safe to update.

= 1.0.7 =
Shop2LLM Visibility Score™ with 5-dimension scoring, tabbed settings page, editor meta box. No breaking changes. Safe to update.

= 1.0.6 =
Dashboard loads 80x faster. Works without WooCommerce. No breaking changes. Safe to update.

= 1.0.5 =
New Dashboard cards: AI Growth Progress, Achievement Badges. Safe to update.

= 1.0.4 =
Privacy enhancement — anonymous activation ping with SHA256 hash. No breaking changes.

= 1.0.1 =
Security fixes and input sanitization. No breaking changes.

= 1.0.0 =
Initial release — Shop2LLM for WooCommerce!

== Privacy ==

This plugin can optionally connect to external services provided by FinCore. All external data transmission is **opt-in** and disabled by default:

* **Analytics (Settings → Shop2LLM → Analytics)** — when enabled, anonymized site metadata (hashed site identifier, platform, version numbers) and AI crawler visits (crawler type, page URL, user agent) are sent to our cloud service. This can be disabled at any time.

* **Product Sync (Settings → Shop2LLM → Product Sync)** — when enabled, lightweight product metadata (title, price, category, image URL) is synced to the Shop2LLM cloud search index so your products appear in AI searches across all connected stores. This is disabled by default and requires explicit opt-in.

* `api.shop2llm.com/fincore/v1/free/ping` — called when analytics is enabled, to register hashed site metadata. Also called during the daily heartbeat if analytics is active.
* `api.shop2llm.com/fincore/v1/store/feed/sync` — called when Product Sync is enabled, to sync product metadata to the cloud search index. Synced once daily.
* `api.shop2llm.com/fincore/v1/crawler/log` — called when AI crawlers visit your store, if analytics is enabled.
* **api.deepseek.com** — when you use the "Ask AI" Hero Test Search, your search query and lightweight product metadata (name, price, currency, stock, rating) are sent to DeepSeek's API to generate the AI reply. DeepSeek's servers may be located outside your jurisdiction. No personal customer data, order information, or full product descriptions are transmitted.

No personal customer data, order information, or product data is transmitted. All external calls use HTTPS and are non-blocking (will not affect site performance if the service is unavailable).

**Email notifications** — This plugin may send transactional emails to the site administrator:
* **AI Crawler Discovery** — when an AI platform first crawls your site, an email is sent to the admin. You can disable this in Settings → Shop2LLM → Email Notifications.
All email notifications are opt-in (disabled by default) and include an unsubscribe notice in each email.

Shop2LLM optionally checks Wikidata and Wikipedia public APIs to determine if your brand or focus query appears in their databases. This feature is disabled by default and must be explicitly enabled in settings. When enabled, only your focus query text is sent to these public APIs — no personal data, user data, or site credentials are ever transmitted. API results are cached locally for 7 days to minimize requests.

Privacy Policy: https://shop2llm.com/
Terms of Service: https://shop2llm.com/
