# Luna Widget - Agent Documentation

## Overview

**Luna Widget** is a comprehensive WordPress plugin suite that provides intelligent, conversational AI assistance for WebOps, DataOps, and Security-related questions. The plugin integrates with the **Visible Light (VL) Hub Profile** to pull real-time data and combines it with **OpenAI GPT-4o** to deliver thoughtful, data-driven responses.

**Plugin Name:** Luna Chat — Widget (Client)  
**Version:** 1.7.0+  
**Author:** Visible Light  
**License:** GPLv2 or later

---

## Core Components

### 1. Luna Chat
A floating chat widget that appears on the client's WordPress site, providing real-time conversational assistance. Luna Chat:
- Maintains conversation history
- Provides contextual, data-driven responses
- Handles complex multi-part questions
- Supports session management with inactivity timers
- Allows transcript downloads
- Integrates with VL Hub Profile for comprehensive data access

### 2. Luna Compose
A shortcode-based interface (`[luna_composer]`) that enables:
- Pre-configured prompt buttons (Essentials)
- Custom sprite library for specialized responses
- Department-specific intents (WebOps, DataOps, Security, Marketing, etc.)
- Structured output formatting
- Auto-generation of long-form, data-driven responses
- Category-based organization of Essentials

**Essentials System:**
- Post Type: `luna_essentials`
- Taxonomy: `luna_essential_category`
- Prompt matching with similarity scoring
- Content preparation and normalization

### 3. Luna Report
A comprehensive reporting system that generates detailed reports about:
- Site health and performance
- Security status and compliance
- Content inventory and analytics
- Infrastructure overview
- Data stream summaries
- Performance metrics

**Features:**
- Automated report generation
- Customizable report templates
- Integration with VL Hub data
- Export capabilities

### 4. Luna Automate
An automation workflow system that enables:
- Automated task scheduling
- Workflow creation and management
- Integration with VL Hub connectors
- Task execution and monitoring
- Event-driven automation

**Capabilities:**
- Set up automated workflows
- Streamline operations
- Trigger actions based on data changes
- Schedule recurring tasks

---

## Core Architecture

### Data Integration: Visible Light Hub Profile

Luna Widget pulls **real-time data** from the client's **VL Hub Profile**, which centralizes, harmonizes, and provides access to:

#### WordPress Core Data
- WordPress version, PHP version, MySQL version
- Memory limits, multisite status
- Core update availability

#### Content Data
- **Pages:** Full list with titles, IDs, status, content
- **Posts:** Complete inventory with titles, categories, tags, publication dates
- **Users:** All users with roles, email addresses, usernames

#### Plugins & Themes
- Complete lists of installed plugins (active/inactive, versions, update availability)
- All installed themes (active/inactive, versions, update availability)
- Pending update counts

#### Security Infrastructure
- **SSL/TLS Certificates:** Certificate details, issuer, expiration dates, days until expiry, TLS version, cipher suite, valid from/to dates, last checked timestamps
- **Cloudflare:** Zone information, DDoS protection status, WAF configuration, CDN features
- **WAF (Web Application Firewall):** Provider, last audit dates
- **IDS (Intrusion Detection System):** Provider, last scan results, schedules
- **Authentication:** MFA status, password policies, session timeouts, SSO providers
- **Domain Information:** Registrar, registration dates, renewal dates, auto-renew status

#### CloudOps & Hosting
- **AWS S3:** Buckets, objects, storage usage, settings
- **Liquid Web:** Hosting assets, account information, connection status

#### Analytics & Performance
- **Google Analytics 4 (GA4):** 
  - **Metrics:** Total Users, New Users, Active Users, Sessions, Page Views, Bounce Rate, Avg Session Duration, Engagement Rate, Engaged Sessions, User Engagement Duration, Event Count, Conversions, Total Revenue, Purchase Revenue, Average Purchase Revenue, Transactions, Session Conversion Rate, Total Purchasers
  - **Dimensional Data:**
    - Geographic Data (countries, regions, cities with user/session/page view counts)
    - Device Data (device types, brands, browsers with metrics)
    - Traffic Sources (sources, mediums, campaigns with metrics)
    - Top Pages (page paths, titles with metrics)
    - Events (event names, counts, users, conversions)
    - Page Location Data (page-location combinations)
  - Property ID, Measurement ID, date ranges, last sync timestamps

#### SEO & Search
- **Google Search Console (GSC):** Clicks, impressions, CTR, top queries, top pages
- **Competitor Analysis:** Full competitor reports with Lighthouse scores, keywords, meta descriptions, timestamps
- **VLDR (Visible Light Domain Ranking):** Domain ranking scores for all tracked domains (client and competitors)

#### Data Streams
- **All Data Streams:** Complete data stream details including:
  - Categories (security, cloudops, analytics, etc.)
  - Health scores
  - Status (active/inactive)
  - Error/warning counts
  - Last updated timestamps
  - Stream-specific data (e.g., SSL/TLS Status connector with `ssl_tls_data` field)

#### Performance Metrics
- **Lighthouse Insights:** Performance, Accessibility, Best Practices, SEO scores from PageSpeed Insights

#### Marketing & Advertising
- **LinkedIn Ads:** Account ID, campaigns count, metrics, last sync
- **Meta Ads:** Account ID, campaigns count, metrics, last sync

---

## AI Integration: OpenAI GPT-4o

### Configuration
- OpenAI API key is stored in WordPress options (`luna_openai_api_key`)
- Can also be set via constant: `LUNA_OPENAI_API_KEY`
- Configured through WordPress admin settings page

### How GPT-4o is Used

Luna Widget uses a **hybrid response system** that combines:

1. **Deterministic Responses:** For common, straightforward queries (e.g., "How many posts do I have?", "Do I have SSL?"), Luna provides immediate, data-driven answers using VL Hub Profile data directly.

2. **AI-Enhanced Responses:** For complex questions, analysis requests, or when follow-up details are needed, Luna uses GPT-4o with the complete VL Hub Profile data as context.

### System Prompt & Context

When GPT-4o is invoked, it receives:

- **System Message:** Instructs Luna to be a friendly, conversational WebOps assistant with comprehensive access to ALL Visible Light Hub data
- **Facts Text:** A comprehensive, structured text representation of ALL VL Hub Profile data, including:
  - Site URL, HTTPS status, hosting provider
  - WordPress version, theme, plugins
  - SSL/TLS certificate details
  - Security infrastructure (WAF, IDS, authentication)
  - CloudOps data (AWS S3, Liquid Web)
  - GA4 metrics and dimensional data
  - Content inventory (pages, posts, users)
  - SEO data (GSC, competitor reports, VLDR)
  - Data streams summary
  - Performance metrics
  - Marketing/advertising data

- **Conversation History:** Previous messages in the current chat session
- **User Query:** The current question or request

### Response Types

1. **Deterministic:** Fast, direct answers from VL Hub data (e.g., post counts, SSL status)
2. **Hybrid Deterministic:** Conversational responses with deterministic data + follow-up offers (e.g., "Yes, you have 5 posts. Would you like more details?")
3. **AI-Generated:** GPT-4o responses using VL Hub data as context for complex analysis, recommendations, or comprehensive reports

---

## Key Features

### 1. Real-Time Data Access
- Pulls live data from VL Hub Profile via REST API endpoints
- Supports direct access to VL Hub Profile class when available (faster, bypasses HTTP)
- Caches data with configurable TTL (default: 5 minutes)
- Background pre-warming for comprehensive data on first message

### 2. Performance Optimization
- **First Message Optimization:** Uses basic facts for fast initial response, pre-warms comprehensive cache in background
- **Caching:** Transient-based caching for Hub collections
- **Direct Access:** Prefers direct PHP class access over HTTP when VL Hub Profile is on same installation
- **Timeout Management:** Reduced HTTP timeouts (5 seconds) for faster failure detection

### 3. Session Management
- Conversation history stored as WordPress post meta
- Inactivity timer (2 minutes warning, 3 minutes auto-close)
- Manual session end with confirmation popover
- Transcript download functionality (.txt file)

### 4. Error Handling & Fallbacks
- Graceful degradation when Hub data unavailable
- Fallback to basic facts if comprehensive data fails
- Direct access fallback if HTTP requests fail
- On-the-fly data fetching for specific queries (e.g., SSL/TLS)

### 5. Security & Authentication
- REST API endpoints with unauthenticated access support
- CORS headers for cross-origin requests
- License key-based data access
- Secure API key storage

---

## Data Flow

### Request Flow

1. **User sends message** → Luna Chat widget
2. **Check conversation history** → Load existing session or create new
3. **Determine facts source:**
   - First message: Use basic facts (fast) + trigger background comprehensive collection
   - Subsequent messages: Use comprehensive facts (cached)
4. **Process query:**
   - Check for deterministic patterns (SSL, posts, updates, etc.)
   - If deterministic match: Return immediate answer
   - If no match or complex question: Prepare GPT-4o request
5. **Build context:**
   - Format VL Hub Profile data as facts text
   - Include conversation history
   - Add system instructions
6. **Generate response:**
   - Deterministic: Return directly
   - AI: Call OpenAI GPT-4o API with context
7. **Save to conversation history** → Store as post meta
8. **Return response** → Display in chat widget

### Data Collection Flow

1. **License key lookup** → Get from WordPress options or license manager
2. **Check cache** → Look for cached Hub collections
3. **If cache miss:**
   - Try direct access (VL Hub Profile class)
   - Fallback to HTTP requests to VL Hub REST API
   - Collect data from multiple endpoints:
     - `/wp-json/vl-hub/v1/profile` (comprehensive profile)
     - `/wp-json/vl-hub/v1/all-connections` (data streams/connectors)
     - `/wp-json/vl-hub/v1/security` (security data)
     - `/wp-json/vl-hub/v1/cloudops` (AWS S3, Liquid Web)
     - `/wp-json/vl-hub/v1/analytics` (GA4)
     - `/wp-json/vl-hub/v1/search` (GSC)
     - `/wp-json/vl-hub/v1/competitive` (competitor reports, VLDR)
4. **Normalize payloads** → Convert to consistent structure
5. **Extract connector data** → Pull specific data from connectors (e.g., SSL/TLS Status)
6. **Cache results** → Store in WordPress transients
7. **Build facts array** → Structure data for Luna's use

---

## Response Patterns

### Deterministic Patterns

Luna recognizes and responds immediately to:

- **SSL/TLS queries:** "do i have ssl?", "ssl certificate", "tls status"
- **Content queries:** "how many posts", "do i have posts", "published posts"
- **Update queries:** "pending updates", "plugin updates", "theme updates"
- **Health queries:** "site health", "health check"
- **Greeting patterns:** "hello", "hi", "hey luna"

### Hybrid Responses

For certain queries, Luna provides:
1. **Deterministic answer** with specific data
2. **Follow-up offer** for deeper analysis
3. **AI enhancement** when user requests more details

Example:
- User: "do i have posts?"
- Luna: "Yes, you have 5 published posts on your site, including 'Welcome Post'. Assigned categories include 'News', 'Updates'. Do you need any further information about the content or performance of these posts?"
- User: "yes, please supply a content and performance review"
- Luna: [GPT-4o generates comprehensive analysis using VL Hub data]

### AI-Generated Responses

For complex questions, Luna uses GPT-4o with instructions to:
- Use actual VL Hub Profile data (not generic responses)
- Provide thoughtful, data-driven analysis
- Write in human-readable format
- Reference specific data points
- Include actionable suggestions
- Never use emoticons, emojis, or special characters (plain text only)

---

## Luna Compose (Essentials System)

Luna Compose allows clients to create custom response "Essentials" with:

- **ID:** Unique identifier (3-64 characters, lowercase alphanumeric with hyphens)
- **Name:** Human-readable name
- **Category:** Taxonomy-based categorization
- **Intent:** Purpose of the essential
- **Triggers:** Keywords that activate the essential
- **Prompt:** The question/request template
- **Output:** Expected response format

Essentials are validated and can override default responses for specific use cases. The system uses similarity matching to find the best essential for a given prompt.

---

## Technical Details

### WordPress Integration
- **Post Type:** `luna_chat` - Stores conversation sessions
- **Post Type:** `luna_essentials` - Stores Luna Compose essentials
- **Taxonomy:** `luna_essential_category` - Categories for essentials
- **Post Meta:**
  - `transcript` - Array of conversation turns
  - `license_key` - Associated license
  - `last_activity` - Timestamp
- **REST API Endpoints:**
  - `/wp-json/luna-widget/v1/chat` - Main chat handler
  - `/wp-json/luna-widget/v1/chat/history` - Conversation history
  - `/wp-json/luna-widget/v1/composer/prompts` - Luna Compose prompts
  - `/wp-json/luna_compose/v1/respond` - Luna Compose response handler

### Caching Strategy
- **Hub Collections Cache:** 5 minutes TTL (configurable)
- **Basic Facts Cache:** Uses Hub profile cache
- **Background Pre-warming:** Non-blocking collection for first message

### Error Handling
- All REST endpoints return valid JSON (even on errors)
- Try/catch blocks prevent PHP errors from leaking to JavaScript
- Graceful fallbacks at every level
- Comprehensive error logging

---

## Expectations for Agents

When working with Luna Widget code, agents should understand:

1. **Luna is a WordPress Plugin** - All code runs within WordPress context
2. **Real-Time Data Priority** - Always use VL Hub Profile data over generic responses
3. **Hybrid Approach** - Combine deterministic answers with AI when appropriate
4. **Performance Matters** - First message should be fast; comprehensive data loads in background
5. **Data-Driven Responses** - Never provide generic answers when VL Hub data is available
6. **Comprehensive Context** - GPT-4o receives ALL VL Hub Profile data, not just basic facts
7. **User Experience** - Responses should be conversational, helpful, and actionable
8. **Error Resilience** - Always have fallbacks; never break the chat experience
9. **Component Integration** - Luna Chat, Compose, Report, and Automate work together as a unified system
10. **Essentials System** - Luna Compose uses Essentials (formerly canned responses) for specialized prompts

---

## Key Functions

### Data Collection
- `luna_hub_collect_collections()` - Fetches all Hub data categories
- `luna_profile_facts()` - Basic facts (fast, for first message)
- `luna_profile_facts_comprehensive()` - Full facts with all VL Hub data
- `luna_fetch_hub_data_streams()` - Gets data streams/connectors
- `luna_fetch_ga4_metrics_from_hub()` - Gets GA4 analytics data

### Chat Processing
- `luna_widget_chat_handler()` - Main chat endpoint handler
- `luna_openai_messages_with_facts()` - Builds GPT-4o context with VL Hub data
- `luna_generate_openai_answer()` - Calls OpenAI API

### Luna Compose
- `luna_essentials_find()` - Finds matching essential by prompt
- `luna_essentials_normalize_prompt_text()` - Normalizes prompt for matching
- `luna_essentials_prepare_content()` - Prepares content for display

### UI Components
- Luna Chat widget (floating FAB + panel)
- Luna Compose shortcode
- Luna Report button and interface
- Luna Automate button and interface
- Session management (timers, close confirmation, transcript download)

---

## Data Sources Summary

Luna Widget has access to **ALL** of the following from VL Hub Profile:

✅ WordPress Core (version, PHP, MySQL, memory)  
✅ Content (pages, posts, users)  
✅ Plugins & Themes (lists, versions, updates)  
✅ Security (SSL/TLS, Cloudflare, WAF, IDS, authentication, domain)  
✅ CloudOps (AWS S3, Liquid Web hosting)  
✅ Analytics (GA4 with full metrics + dimensional data)  
✅ SEO (Google Search Console, competitor reports, VLDR)  
✅ Performance (Lighthouse scores)  
✅ Marketing (LinkedIn Ads, Meta Ads)  
✅ Data Streams (all connectors with full details)

**This comprehensive data access enables Luna to answer complex WebOps, DataOps, and Security questions with real, actionable insights based on the client's actual infrastructure and data.**

---

## Version History

- **1.7.0+** - Current version
  - Enhanced SSL/TLS data extraction from connectors
  - Improved first message performance
  - Hybrid response system (deterministic + AI)
  - Session management improvements
  - Transcript download functionality
  - On-the-fly data fetching for specific queries
  - Luna Compose Essentials system
  - Luna Report functionality
  - Luna Automate workflow system

---

*This documentation is maintained to help AI agents understand Luna Widget's architecture, data sources, and response patterns. Always prioritize real VL Hub Profile data over generic responses, and ensure responses are data-driven, thoughtful, and actionable.*

