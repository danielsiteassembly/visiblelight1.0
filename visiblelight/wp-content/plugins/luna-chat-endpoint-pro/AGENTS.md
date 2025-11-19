# Luna Chat Endpoint Pro - Agent Documentation

## Overview

**Luna Chat Endpoint Pro** is a WordPress plugin that provides Hub-side REST API endpoints for the Luna Chat system. It handles all API requests from client sites, manages conversation logging, session tracking, and provides comprehensive data endpoints for the Visible Light ecosystem.

**Plugin Name:** Luna Chat Endpoint Pro  
**Version:** 2.0.0  
**Author:** Visible Light  
**License:** GPLv2 or later

---

## Core Functionality

### Hub-Side API Endpoints
Provides REST API endpoints that client sites call to:
- Log conversations
- Track session starts and ends
- Store comprehensive system data
- Retrieve client profiles
- Validate field mappings
- Access constellation datasets
- Handle SOC 2 snapshot data

### Conversation Management
- **Conversation Logging:** Stores conversation transcripts from client sites
- **Session Tracking:** Tracks session starts, ends, and reasons
- **License Association:** Links all data to license keys/IDs

### Profile Management
- **Profile Storage:** Stores comprehensive client profiles
- **Profile Resolution:** Resolves license keys to profiles with refresh capabilities
- **Profile Updates:** Accepts profile updates from client sites
- **Field Validation:** Validates specific profile fields

### Constellation Dataset
- **Telemetry Aggregation:** Builds constellation datasets for visualization
- **Client Mapping:** Maps clients to their telemetry data
- **Category Organization:** Organizes data by categories (identity, infrastructure, security, content, plugins, themes, users, AI, sessions, integrations)

---

## REST API Endpoints

### Core Chat Endpoints

#### `/wp-json/luna/v1/chat-live` (POST)
- **Purpose:** Chat endpoint for client sites
- **Parameters:**
  - `tenant` (optional): Tenant identifier
  - `prompt`: User message/prompt
- **Response:** Simple Hub response indicating client sites should use their own chat functionality

#### `/wp-json/luna/v1/health` (GET)
- **Purpose:** Health check endpoint
- **Response:** Status confirmation that Hub endpoints are working

### Conversation & Session Endpoints

#### `/wp-json/luna_widget/v1/conversations/log` (POST)
- **Purpose:** Log conversations from client sites
- **Headers:** `X-Luna-License` or `license` parameter
- **Body:** Conversation data including:
  - `id`: Conversation ID
  - `started_at`: Start timestamp
  - `transcript`: Array of conversation turns
- **Storage:** Stored in `vl_hub_conversations` option keyed by license ID

#### `/wp-json/luna_widget/v1/chat/session-start` (POST)
- **Purpose:** Track session starts
- **Headers:** `X-Luna-License` or `license` parameter
- **Body:**
  - `session_id`: Unique session identifier
  - `started_at`: Start timestamp
- **Storage:** Stored in `vl_hub_session_starts` option

#### `/wp-json/luna_widget/v1/chat/session-end` (POST)
- **Purpose:** Track session ends
- **Headers:** `X-Luna-License` or `license` parameter
- **Body:**
  - `session_id`: Session identifier
  - `reason`: End reason (timeout, user, etc.)
  - `ended_at`: End timestamp
- **Storage:** Stored in `vl_hub_session_ends` option

### Profile Endpoints

#### `/wp-json/vl-hub/v1/profile` (GET/POST)
- **Purpose:** Get or update client profile
- **Headers:** `X-Luna-License` or `license` parameter
- **GET:** Returns resolved profile (with optional refresh)
- **POST:** Stores/updates profile data
- **Query Parameters:**
  - `refresh` (GET): Force refresh from client site

#### `/wp-json/luna_widget/v1/system/comprehensive` (GET/POST)
- **Purpose:** Get or store comprehensive system data
- **Headers:** `X-Luna-License` or `license` parameter
- **GET:** Returns comprehensive profile data
- **POST:** Stores comprehensive data from client
- **Storage:** Merged into `vl_hub_profiles` option

#### `/wp-json/vl-hub/v1/profile/security` (POST)
- **Purpose:** Store security data from client sites
- **Headers:** `X-Luna-License` or `license` parameter
- **Body:** Security data structure
- **Storage:** Stored in profile's `security` key

### Validation Endpoints

#### `/wp-json/luna_widget/v1/validate/field` (POST)
- **Purpose:** Validate a specific profile field
- **Headers:** `X-Luna-License` or `license` parameter
- **Parameters:**
  - `field`: Field name to validate
- **Response:** Validation result with value and error (if any)

#### `/wp-json/luna_widget/v1/validate/all` (POST)
- **Purpose:** Validate all profile fields
- **Headers:** `X-Luna-License` or `license` parameter
- **Response:** Validation results for all fields

### Constellation Endpoint

#### `/wp-json/vl-hub/v1/constellation` (GET)
- **Purpose:** Get constellation dataset for visualization
- **Parameters:**
  - `license` (optional): Filter by license
- **Response:** Complete constellation dataset with clients, categories, and nodes

### SOC 2 Endpoint

#### `/wp-json/vl-hub/v1/soc2/snapshot` (GET)
- **Purpose:** Get SOC 2 snapshot data
- **Headers:** `X-VL-License` or `X-Luna-License` or `license` parameter
- **Response:** SOC 2 snapshot structure with controls, evidence, risks, compliance status

### Client Authentication Endpoints

#### `/wp-json/vl-hub/v1/auth-check` (GET)
- **Purpose:** Check client authentication
- **Permissions:** Requires logged-in user
- **Response:** Authentication status and user/license info

#### `/wp-json/vl-hub/v1/client-data` (GET)
- **Purpose:** Get client data for authenticated user
- **Permissions:** Requires logged-in user with license
- **Response:** Client configuration and data

#### `/wp-json/vl-hub/v1/clients` (GET)
- **Purpose:** Get list of clients for Supercluster
- **Permissions:** Public access (for Supercluster)
- **Response:** List of clients with basic info

### Luna Compose Endpoint

#### `/wp-json/luna_compose/v1/respond` (POST)
- **Purpose:** Handle Luna Compose responses
- **Parameters:**
  - `prompt`: User prompt
  - `client`: Client identifier (optional)
  - `refresh`: Force refresh (optional)

---

## Core Functions

### Profile Management
- `vl_hub_find_license_record($license_key)` - Finds license record by key
- `vl_hub_profile_resolve($license_key, $options)` - Resolves profile with optional refresh
- `vl_hub_profile_missing_inventory($profile)` - Checks if profile needs refresh
- `vl_hub_refresh_profile_from_client($license_info, $profile)` - Refreshes profile from client site
- `vl_hub_fetch_client_endpoint($site_url, $path, $license_key, $query)` - Fetches data from client site

### Constellation Building
- `vl_rest_constellation_dataset($req)` - Builds constellation dataset
- `vl_constellation_build_dataset($license_filter)` - Assembles constellation data
- `vl_constellation_build_client(...)` - Builds client constellation node
- Category builders:
  - `vl_constellation_identity_category()`
  - `vl_constellation_infrastructure_category()`
  - `vl_constellation_security_category()`
  - `vl_constellation_content_category()`
  - `vl_constellation_plugins_category()`
  - `vl_constellation_theme_category()`
  - `vl_constellation_users_category()`
  - `vl_constellation_ai_category()`
  - `vl_constellation_sessions_category()`
  - `vl_constellation_integrations_category()`

### Field Validation
- `vl_validate_field_mapping($profile, $field)` - Validates specific profile field
- Supports validation for:
  - TLS fields (status, version, issuer, provider, dates, host)
  - WAF fields (provider, last_audit)
  - IDS fields (provider, last_scan, result, schedule)
  - Auth fields (mfa, password_policy, session_timeout, sso_providers)
  - Domain fields (registrar, registered_on, renewal_date, auto_renew, dns_records)

### VLDR Integration
- `luna_vldr_get_score_from_hub($license_key, $domain)` - Gets VLDR score with caching
- Enriches chat context with VLDR data for competitors
- Filters: `luna_chat_context_enrich` - Adds VLDR data to chat context

---

## Data Storage

### WordPress Options
- `vl_licenses_registry` - License registry
- `vl_hub_profiles` - Client profiles (keyed by license ID)
- `vl_hub_conversations` - Conversation logs (keyed by license ID)
- `vl_hub_session_starts` - Session start logs (keyed by license ID)
- `vl_hub_session_ends` - Session end logs (keyed by license ID)
- `vl_client_connections` - Client connection data
- `vl_hub_soc2_snapshots` - SOC 2 snapshot data (keyed by license ID)

### Database Tables
- `wp_vl_competitor_reports` - Competitor analysis reports (if table exists)

---

## Constellation Dataset Structure

### Client Structure
```php
[
  'license_id' => 'id',
  'license_key' => 'redacted-key',
  'client' => 'Client Name',
  'site' => 'https://site.com',
  'active' => true/false,
  'created' => 'ISO date',
  'last_seen' => 'ISO date',
  'categories' => [
    // Category structures
  ]
]
```

### Category Structure
```php
[
  'slug' => 'category-slug',
  'name' => 'Category Name',
  'color' => '#hex-color',
  'icon' => 'icon-name.svg',
  'nodes' => [
    // Node structures
  ]
]
```

### Node Structure
```php
[
  'id' => 'node-id',
  'label' => 'Node Label',
  'color' => '#hex-color',
  'value' => 1-10, // Size/importance
  'detail' => 'Additional detail text'
]
```

---

## Integration Points

### With Client Sites
- Receives conversation logs
- Accepts profile updates
- Tracks session activity
- Validates field mappings

### With Luna Widget
- Provides profile data
- Logs conversations
- Tracks sessions
- Supplies constellation data

### With VL Hub
- Stores all Hub data centrally
- Coordinates data synchronization
- Provides telemetry aggregation

### With Supercluster
- Supplies constellation datasets
- Provides client lists
- Enables visualization data

---

## Expectations for Agents

When working with Luna Chat Endpoint Pro code, agents should understand:

1. **Hub Authority** - This plugin runs on the Hub and serves client sites
2. **License Validation** - All endpoints require license validation
3. **Data Storage** - Uses WordPress options for data persistence
4. **Profile Resolution** - Profiles can be refreshed from client sites
5. **Error Handling** - All endpoints return valid JSON responses
6. **Permission Checks** - Some endpoints require authentication
7. **Data Aggregation** - Constellation endpoint aggregates telemetry data
8. **Field Validation** - Supports validation of specific profile fields
9. **Session Tracking** - Tracks detailed session metrics
10. **Conversation Logging** - Stores complete conversation transcripts

---

## Security Considerations

- License keys required for most endpoints
- Some endpoints support unauthenticated access (for client sites)
- Authentication endpoints require logged-in users
- License keys are redacted in constellation datasets
- CORS headers may be needed for cross-origin requests

---

## Version History

- **2.0.0** - Current version
  - Comprehensive profile management
  - Constellation dataset endpoint
  - Field validation endpoints
  - Session tracking
  - Conversation logging
  - SOC 2 snapshot support
  - VLDR integration
  - Client authentication endpoints

---

*This documentation helps AI agents understand Luna Chat Endpoint Pro's role as the Hub-side API provider for the Visible Light ecosystem. It coordinates data flow, conversation logging, and provides comprehensive endpoints for client sites and visualization systems.*

