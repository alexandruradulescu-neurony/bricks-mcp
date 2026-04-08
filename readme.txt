=== Bricks MCP ===
Contributors: alexradulescu
Tags: ai, bricks builder, mcp, artificial intelligence, page builder
Requires at least: 6.4
Tested up to: 6.9
Stable tag: 1.9.5
Requires PHP: 8.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI assistants like Claude to your Bricks Builder site. Build and edit pages using natural language — no clicking required.

== Description ==

Bricks MCP turns your WordPress site into an AI-controlled page builder. It implements the Model Context Protocol (MCP) — an open standard for connecting AI assistants to external tools — so that any MCP-compatible client (Claude Desktop, Claude Code, and others) can read and modify your Bricks Builder pages through plain conversation.

Tell your AI assistant "create a hero section with a headline and a call-to-action button" and it happens. No template hunting. No clicking through panels.

= How It Works =

The plugin registers a REST API endpoint on your WordPress site that speaks the MCP protocol. You add the endpoint URL to your AI client's MCP configuration, authenticate with a WordPress Application Password, and your AI can start working with your site immediately.

= Available Tools (20 tools) =

* **get_site_info** — Site config, design tokens, child theme CSS, color palette, page summaries, class groups, design patterns
* **get_builder_guide** — Complete builder reference with sections: professional, workflow, recipes, gotchas, and more
* **page** — List, search, get (detail/summary/context/describe views), create, update content, append, delete, duplicate, snapshots, SEO
* **element** — Add, update, remove, move, bulk add/update, duplicate, find elements on pages
* **template** — Manage Bricks templates (header, footer, content, popup), import/export
* **template_condition** — Set template display conditions
* **template_taxonomy** — Manage template tags and bundles
* **bricks** — Builder settings, element schemas, breakpoints, dynamic tags, pattern library (analyze/save/use), AI notes
* **global_class** — Create/edit/delete CSS classes, batch operations, import CSS, categories
* **global_variable** — Manage CSS variables and categories
* **color_palette** — Manage color palettes and individual colors
* **typography_scale** — Manage typography scale variables
* **theme_style** — Manage Bricks theme styles
* **component** — List/create/update components, instantiate, fill slots
* **font** — Adobe Fonts, font settings
* **code** — Page CSS and custom scripts
* **media** — Unsplash search, sideload images, manage featured images
* **menu** — Create/edit/delete menus, assign to locations
* **wordpress** — Get posts/users/plugins, activate/deactivate plugins, create/update users
* **woocommerce** — WooCommerce status, elements, template scaffolding
* **metabox** — Read Meta Box custom fields, list field groups, get dynamic tags

All tools are free to use. The plugin is open source and hosted on [GitHub](https://github.com/alexandruradulescu-neurony/bricks-mcp).

= Authentication =

All requests are authenticated using WordPress Application Passwords, the built-in authentication system available since WordPress 5.6. No third-party authentication service is involved.

= Requirements =

* WordPress 6.4 or later
* PHP 8.2 or later
* Bricks Builder theme 1.6 or later (required for Bricks-specific tools)

= Getting Started =

1. Install and activate the plugin.
2. Go to **Settings > Bricks MCP** and enable the plugin.
3. Create a WordPress Application Password under **Users > Profile**.
4. Add the MCP server URL to your AI client configuration.
5. Start building pages with natural language.

Full setup documentation is available in the [GitHub repository](https://github.com/alexandruradulescu-neurony/bricks-mcp).

== External Services ==

This plugin optionally connects to the Unsplash API to search for images.

**Service:** Unsplash (api.unsplash.com)
**When used:** Only when the `search_media` tool is called by an AI assistant, and only if you have configured an Unsplash API key in the plugin settings.
**What is sent:** Your search query string and your Unsplash API key.
**Unsplash Terms of Service:** https://unsplash.com/terms
**Unsplash Privacy Policy:** https://unsplash.com/privacy
**Unsplash API Guidelines:** https://unsplash.com/documentation

No data is sent to Unsplash unless you explicitly configure an API key and an AI assistant invokes the image search tool.

No other external services are contacted by this plugin.

== Installation ==

1. Upload the `bricks-mcp` folder to the `/wp-content/plugins/` directory, or install the plugin via the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Settings > Bricks MCP** to configure the plugin.
4. Enable the MCP server and optionally require authentication (strongly recommended for production sites).
5. Go to **Users > Your Profile** and scroll to **Application Passwords**. Create a new Application Password and copy it — you will need it for your AI client.
6. Add your site's MCP endpoint URL and credentials to your AI client (see the [GitHub repository](https://github.com/alexandruradulescu-neurony/bricks-mcp) for client-specific setup guides).
7. (Optional) Enter an Unsplash API key in the settings to enable image search.

== Frequently Asked Questions ==

= What is MCP (Model Context Protocol)? =

MCP is an open protocol created by Anthropic that gives AI assistants a standard way to connect to external tools and data sources. It works like a universal adapter: the AI client connects to an MCP server, discovers what tools are available, and calls them by name. This plugin implements that server for WordPress and Bricks Builder.

= Does this plugin work without Bricks Builder? =

Yes, partially. The core WordPress tools (get_site_info, wordpress, media, menu) work on any WordPress site regardless of the active theme. The Bricks-specific tools (page, element, template, bricks, global_class, component, etc.) require Bricks Builder to be installed and active.

= Which AI tools and clients are supported? =

Any MCP-compatible client can connect to this plugin. Verified clients include Claude Desktop and Claude Code. Because MCP is an open protocol, support for other clients is expected to grow over time.

= Is it safe to expose a REST API endpoint for AI access? =

Yes, when configured correctly. The plugin includes multiple security layers: WordPress Application Password authentication (enabled by default), per-tool capability checks, configurable rate limiting (120-1000 RPM), a Dangerous Actions toggle that gates JavaScript/code injection, delete confirmation requirements (`confirm: true`), protected pages that block AI modifications, element count safety checks that prevent accidental content wipes, and centralized CSS sanitization. Never disable authentication on a publicly accessible site.

== Screenshots ==

1. The Bricks MCP settings page under Settings > Bricks MCP.
2. Example Claude Desktop configuration connecting to the MCP server endpoint.
3. An AI assistant creating a Bricks Builder hero section from a plain-text prompt.

== Changelog ==

= 1.9.0 =
* Added design interpretation workflow for building pages from visual references.
* Added map_design tool for matching design descriptions to site assets.
* Added token-based confirmation system for destructive operations.
* Enhanced admin settings with connection status badge, getting started checklist, and request counter.
* Comprehensive security hardening: CSS sanitization, role validation, input sanitization, depth limits.

= 1.0.0 =
* Initial release.