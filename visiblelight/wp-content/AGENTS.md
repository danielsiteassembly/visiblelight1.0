# VL Hub - Agent Documentation

## Overview

**VL Hub** is the central data aggregation and management system for the Visible Light ecosystem. It serves as the single source of truth for client profiles, data streams, integrations, and telemetry. The Hub coordinates data flow between client sites, visualization systems (Supercluster), and AI assistants (Luna).

**System Type:** Central Hub/Backend  
**Architecture:** WordPress-based with REST API  
**Author:** Visible Light  
**License:** GPLv2 or later

---

## Core Functionality

### Central Data Repository
- **Client Profiles:** Comprehensive client site profiles
- **Data Streams:** All connector data streams
- **Integrations:** Third-party service integrations
- **Telemetry:** System and usage telemetry
- **Conversations:** Luna Chat conversation logs
- **Sessions:** Session tracking and analytics

### Data Harmonization
- **Data Normalization:** Standardizes data from various sources
- **Data Enrichment:** Enhances data with additional context
- **Data Validation:** Validates data integrity and completeness
- **Data Caching:** Implements caching strategies for performance

### API Gateway
- **REST API Endpoints:** Comprehensive REST API for all Hub data
- **License-Based Access:** Secure access control via license keys
- **Client Authentication:** User authentication for client access
- **Rate Limiting:** Protects against abuse

---

## Data Categories

### 1. Client Profiles
Comprehensive profiles for each client license containing:

#### Site Information
- Home URL
- HTTPS status
- Hosting provider
- Site status

#### WordPress Core
- WordPress version
- PHP version
- MySQL version
- Memory limits
- Multisite status
- Core update availability

#### Content Inventory
- **Pages:** Full list with titles, IDs, status, content
- **Posts:** Complete inventory with titles, categories, tags, publication dates
- **Users:** All users with roles, email addresses, usernames
- **Media:** Media library items

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
- **Domain Information:** Registrar, registration dates, renewal dates, auto-renew status, DNS records

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

#### Performance Metrics
- **Lighthouse Insights:** Performance, Accessibility, Best Practices, SEO scores from PageSpeed Insights

#### Marketing & Advertising
- **LinkedIn Ads:** Account ID, campaigns count, metrics, last sync
- **Meta Ads:** Account ID, campaigns count, metrics, last sync

### 2. Data Streams
- **Stream Registry:** All data streams/connectors for each license
- **Stream Status:** Active/inactive status tracking
- **Stream Health:** Health scores and error counts
- **Stream Categories:** Organized by category (security, cloudops, analytics, etc.)
- **Stream Data:** Connector-specific payloads

### 3. Integrations
- **Connection Status:** Integration connection status
- **Integration Data:** Data from connected services
- **Sync Timestamps:** Last synchronization times
- **Error Tracking:** Integration error logs

### 4. Telemetry
- **Session Tracking:** Session starts and ends
- **Conversation Logs:** Luna Chat conversation transcripts
- **Usage Metrics:** System usage statistics
- **Performance Metrics:** System performance data

---

## REST API Endpoints

### Profile Endpoints

#### `/wp-json/vl-hub/v1/profile` (GET/POST)
- **Purpose:** Get or update client profile
- **Headers:** `X-Luna-License` or `license` parameter
- **GET Parameters:** `refresh` (optional) - Force refresh from client site
- **POST Body:** Profile data to store/update
- **Response:** Complete client profile

#### `/wp-json/vl-hub/v1/profile/security` (POST)
- **Purpose:** Store security data
- **Headers:** `X-Luna-License` or `license` parameter
- **Body:** Security data structure
- **Response:** Success confirmation

### Client Endpoints

#### `/wp-json/vl-hub/v1/client-sites` (GET)
- **Purpose:** Get all sites for a client
- **Parameters:** `license` (query parameter)
- **Response:** Array of site objects

#### `/wp-json/vl-hub/v1/clients` (GET)
- **Purpose:** Get list of clients for Supercluster
- **Permissions:** Public access (for Supercluster)
- **Response:** List of clients with basic info

### Constellation Endpoint

#### `/wp-json/vl-hub/v1/constellation` (GET)
- **Purpose:** Get constellation dataset for visualization
- **Parameters:** `license` (optional filter)
- **Response:** Complete constellation dataset with:
  - Clients array
  - Categories for each client
  - Nodes within each category
  - Colors and icons for visualization

### SOC 2 Endpoint

#### `/wp-json/vl-hub/v1/soc2/snapshot` (GET)
- **Purpose:** Get SOC 2 snapshot data
- **Headers:** `X-VL-License` or `X-Luna-License` or `license` parameter
- **Response:** SOC 2 snapshot structure

### Authentication Endpoints

#### `/wp-json/vl-hub/v1/auth-check` (GET)
- **Purpose:** Check client authentication
- **Permissions:** Requires logged-in user
- **Response:** Authentication status

#### `/wp-json/vl-hub/v1/client-data` (GET)
- **Purpose:** Get client data for authenticated user
- **Permissions:** Requires logged-in user with license
- **Response:** Client configuration

### VLDR Endpoint

#### `/wp-json/vl-hub/v1/vldr` (GET)
- **Purpose:** Get VLDR (Visible Light Domain Ranking) data
- **Parameters:** `license` and `domain` (query parameters)
- **Response:** VLDR score and metrics

---

## Data Storage

### WordPress Options
- **`vl_licenses_registry`:** License registry with client associations
- **`vl_hub_profiles`:** Client profiles keyed by license ID
- **`vl_hub_conversations`:** Conversation logs keyed by license ID
- **`vl_hub_session_starts`:** Session start logs
- **`vl_hub_session_ends`:** Session end logs
- **`vl_client_connections`:** Client connection data
- **`vl_hub_soc2_snapshots`:** SOC 2 snapshot data
- **`vl_data_streams`:** Data streams keyed by license key
- **`vl_removed_streams_{license_key}`:** Removed streams per license

### Integration-Specific Options
- **`vl_ssl_tls_settings_{license_key}`:** SSL/TLS settings
- **`vl_cloudflare_settings_{license_key}`:** Cloudflare settings
- **`vl_cloudflare_zones_{license_key}`:** Cloudflare zones
- **`vl_aws_s3_settings_{license_key}`:** AWS S3 settings
- **`vl_aws_s3_data_{license_key}`:** AWS S3 data
- **`vl_ga4_settings_{license_key}`:** GA4 settings
- **`vl_ga4_data_{license_key}`:** GA4 data
- **`vl_gsc_settings_{license_key}`:** GSC settings
- **`vl_gsc_data_{license_key}`:** GSC data
- **`vl_pagespeed_settings_{license_key}`:** PageSpeed settings
- **`vl_pagespeed_analyses_{license_key}`:** PageSpeed analyses
- **`vl_liquidweb_settings_{license_key}`:** Liquid Web settings
- **`vl_liquidweb_assets_{license_key}`:** Liquid Web assets
- **`vl_competitor_settings_{license_key}`:** Competitor settings

### Database Tables
- **`wp_vl_competitor_reports`:** Competitor analysis reports

---

## Data Flow

### Profile Resolution Flow
1. **License Lookup:** Find license record by key
2. **Profile Retrieval:** Get stored profile from options
3. **Missing Data Check:** Determine if profile needs refresh
4. **Client Site Fetch:** If needed, fetch from client site
5. **Data Merging:** Merge fetched data with stored profile
6. **Enrichment:** Add Hub-specific data (streams, integrations)
7. **Caching:** Store updated profile
8. **Return:** Return complete profile

### Data Stream Flow
1. **Stream Registration:** Streams registered per license
2. **Status Tracking:** Track active/inactive status
3. **Data Collection:** Collect data from connectors
4. **Health Monitoring:** Monitor stream health
5. **Error Tracking:** Track errors and warnings
6. **Storage:** Store in `vl_data_streams` option

### Constellation Dataset Flow
1. **License Retrieval:** Get all licenses or filter by license
2. **Profile Loading:** Load profiles for each license
3. **Telemetry Aggregation:** Aggregate conversations, sessions, connections
4. **Category Building:** Build categories (identity, infrastructure, security, etc.)
5. **Node Creation:** Create nodes for each data point
6. **Dataset Assembly:** Assemble complete dataset
7. **Response:** Return constellation dataset

---

## Integration Points

### With Client Sites
- **Profile Updates:** Receives profile updates from client sites
- **Data Synchronization:** Syncs data from client WordPress installations
- **License Validation:** Validates license keys
- **Activity Tracking:** Tracks client activity

### With Luna Widget
- **Profile Data:** Supplies comprehensive profiles for AI context
- **Conversation Logging:** Receives conversation logs
- **Session Tracking:** Tracks session activity
- **Field Validation:** Validates profile fields

### With Supercluster
- **Constellation Data:** Supplies constellation datasets
- **Client Lists:** Provides client lists for visualization
- **Site Data:** Provides site data for Omniscient App Observatory

### With Luna License Manager
- **License Registry:** Shares license registry
- **Profile Storage:** Coordinates profile storage
- **Stream Management:** Manages data streams

### With Luna Chat Endpoint Pro
- **Endpoint Provider:** Provides Hub-side endpoints
- **Data Storage:** Stores all Hub data
- **API Gateway:** Serves as API gateway

---

## Key Functions

### Profile Management
- `vl_hub_profile_resolve($license_key, $options)` - Resolves profile with optional refresh
- `vl_hub_find_license_record($license_key)` - Finds license record
- `vl_hub_profile_missing_inventory($profile)` - Checks for missing data
- `vl_hub_refresh_profile_from_client($license_info, $profile)` - Refreshes from client
- `vl_hub_fetch_client_endpoint($site_url, $path, $license_key, $query)` - Fetches from client

### Constellation Building
- `vl_rest_constellation_dataset($req)` - REST handler for constellation
- `vl_constellation_build_dataset($license_filter)` - Builds dataset
- `vl_constellation_build_client(...)` - Builds client constellation
- Category builders for each data category

### Data Enrichment
- Filter: `vl_hub_profile_resolved` - Enriches profiles with Hub data
- Adds SSL/TLS, Cloudflare, streams, competitors, AWS S3, GA4, Liquid Web, GSC, PageSpeed data

---

## Expectations for Agents

When working with VL Hub code, agents should understand:

1. **Central Authority** - Hub is the single source of truth for all client data
2. **Data Completeness** - Profiles should include all available data categories
3. **License-Based Access** - All data access requires license validation
4. **Data Freshness** - Profiles can be refreshed from client sites
5. **Stream Management** - Data streams are tracked and managed
6. **Telemetry Aggregation** - Hub aggregates telemetry from multiple sources
7. **API Gateway** - Hub serves as API gateway for all Hub data
8. **Data Harmonization** - Hub normalizes and enriches data
9. **Caching Strategy** - Implements caching for performance
10. **Error Handling** - Gracefully handles missing or incomplete data

---

## Security Considerations

- **License Validation:** All endpoints validate license keys
- **Authentication:** Some endpoints require user authentication
- **Data Privacy:** Client data is secured and access-controlled
- **API Security:** REST API endpoints have permission callbacks
- **License Redaction:** License keys are redacted in constellation datasets

---

## Performance Optimization

- **Caching:** Profiles and data are cached
- **Lazy Loading:** Data loaded on demand
- **Background Refresh:** Profiles refreshed in background when possible
- **Efficient Queries:** Optimized database queries
- **Transient Storage:** Uses WordPress transients for temporary data

---

## Version History

- **Current** - Active development
  - Comprehensive profile management
  - Data stream tracking
  - Constellation dataset generation
  - SOC 2 snapshot support
  - VLDR integration
  - Multi-integration support
  - Telemetry aggregation
  - Enhanced data enrichment

---

*This documentation helps AI agents understand VL Hub's role as the central data aggregation and management system for the Visible Light ecosystem. It coordinates data flow, provides comprehensive APIs, and serves as the single source of truth for all client data, integrations, and telemetry.*

