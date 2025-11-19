# Luna License Manager - Agent Documentation

## Overview

**Luna License Manager** is a WordPress plugin that manages licenses, client profiles, and data streams for the Visible Light ecosystem. It serves as the central licensing and profile management system that coordinates between client sites and the VL Hub.

**Plugin Name:** Luna License Manager  
**Version:** Current  
**Author:** Visible Light  
**License:** GPLv2 or later

---

## Core Functionality

### License Management
- **License Registry:** Stores and manages all license keys and associated metadata
- **License Validation:** Validates license keys and checks activation status
- **Client Association:** Links licenses to client names and site URLs
- **License Status Tracking:** Tracks active/inactive status, creation dates, last seen timestamps
- **Plugin Version Tracking:** Monitors Luna Widget plugin versions per license

### Client Profile Management
- **Profile Storage:** Stores comprehensive client profiles in WordPress options
- **Profile Resolution:** Resolves license keys to client profiles with refresh capabilities
- **Profile Synchronization:** Syncs profiles from client sites to Hub
- **Profile Caching:** Implements caching strategies for profile data

### Data Stream Management
- **Stream Registry:** Manages data streams/connectors for each license
- **Stream Status:** Tracks active/inactive status of streams
- **Stream Categories:** Organizes streams by category (security, cloudops, analytics, etc.)
- **Stream Health:** Monitors stream health scores and error counts
- **Stream Removal Tracking:** Tracks removed streams and marks them as inactive

---

## Key Features

### 1. License Registry System
- Stores licenses in WordPress options (`vl_licenses_registry`)
- Each license entry contains:
  - License key
  - Client name
  - Site URL
  - Active status
  - Creation timestamp
  - Last seen timestamp
  - Plugin version (if available)

### 2. Profile Storage & Resolution
- Profiles stored in WordPress options (`vl_hub_profiles`)
- Profile structure includes:
  - Site information (URL, HTTPS status)
  - WordPress core data (version, PHP version, MySQL version)
  - Content inventory (posts, pages, users)
  - Plugins and themes lists
  - Security data (SSL/TLS, Cloudflare, WAF, IDS, authentication, domain)
  - CloudOps data (AWS S3, Liquid Web)
  - Analytics data (GA4)
  - SEO data (GSC, competitor reports, VLDR)
  - Performance metrics (Lighthouse)
  - Marketing data (LinkedIn Ads, Meta Ads)
  - Data streams summary

### 3. Data Stream Management
- Streams stored in WordPress options (`vl_data_streams`)
- Stream structure includes:
  - Stream ID
  - Name and description
  - Categories
  - Status (active/inactive)
  - Health score
  - Error/warning counts
  - Last updated timestamp
  - Stream-specific data (connector payloads)

### 4. Stream Status Tracking
- **Active Streams:** Currently active and functioning streams
- **Inactive Streams:** Streams that have been removed or deactivated
- **Removed Streams:** Tracks streams that were explicitly removed
- **Status Validation:** Ensures stream entries are valid and complete

---

## Core Functions

### License Management
- `VL_License_Manager::get_license_streams($license_key)` - Gets all streams for a license
- `VL_License_Manager::split_streams_by_status($streams)` - Splits streams by active/inactive status
- `vl_hub_find_license_record($license_key)` - Finds license record by key
- `vl_hub_profile_resolve($license_key, $options)` - Resolves and optionally refreshes profile

### Profile Management
- `vl_hub_profile_missing_inventory($profile)` - Checks if profile is missing inventory data
- `vl_hub_refresh_profile_from_client($license_info, $profile)` - Refreshes profile from client site
- Profile data stored in `vl_hub_profiles` option keyed by license ID

### Stream Management
- Streams retrieved via `VL_License_Manager::get_license_streams()`
- Streams automatically filtered to exclude removed/invalid entries
- Status tracked in stream data structure
- Removed streams marked as inactive with removal timestamps

---

## Data Structures

### License Record
```php
[
  'key' => 'license-key-string',
  'client' => 'Client Name',
  'site' => 'https://client-site.com',
  'active' => true/false,
  'created' => timestamp,
  'last_seen' => timestamp,
  'plugin_version' => '1.7.0'
]
```

### Client Profile
```php
[
  'license_id' => 'license-id',
  'license_key' => 'license-key',
  'client_name' => 'Client Name',
  'home_url' => 'https://client-site.com',
  'https' => true/false,
  'site' => [...],
  'wordpress' => [...],
  'posts' => [...],
  'pages' => [...],
  'users' => [...],
  'plugins' => [...],
  'themes' => [...],
  'security' => [...],
  'cloudops' => [...],
  'ga4' => [...],
  'gsc' => [...],
  'competitor_reports' => [...],
  'data_streams_summary' => [...],
  'profile_last_synced' => 'timestamp'
]
```

### Data Stream
```php
[
  'id' => 'stream-id',
  'name' => 'Stream Name',
  'categories' => ['security', 'analytics'],
  'status' => 'active'|'inactive',
  'health_score' => 95,
  'error_count' => 0,
  'warning_count' => 0,
  'last_updated' => 'timestamp',
  'connector_data' => [...],
  'removed' => true/false,
  'removed_at' => 'timestamp'
]
```

---

## Integration Points

### With Luna Widget
- Provides license validation for Luna Chat
- Supplies profile data for AI context
- Manages data stream access

### With VL Hub
- Stores profiles centrally
- Coordinates data synchronization
- Manages license-to-client mappings

### With Client Sites
- Receives profile updates via REST API
- Validates license keys
- Tracks client activity

---

## REST API Endpoints

The License Manager works with endpoints provided by other plugins:

- `/wp-json/vl-hub/v1/profile` - Get/update client profile
- `/wp-json/vl-hub/v1/client-sites` - Get all sites for a client
- `/wp-json/luna_widget/v1/system/comprehensive` - Get/store comprehensive system data

---

## Expectations for Agents

When working with Luna License Manager code, agents should understand:

1. **Central Authority** - License Manager is the source of truth for licenses and profiles
2. **Profile Completeness** - Profiles should include all available data categories
3. **Stream Status** - Always check stream status before using stream data
4. **License Validation** - Always validate license keys before accessing profile data
5. **Data Freshness** - Profiles can be refreshed from client sites when needed
6. **Error Handling** - Gracefully handle missing licenses or incomplete profiles
7. **Caching** - Profile data is cached; refresh when necessary
8. **Stream Filtering** - Removed streams are automatically filtered out
9. **Status Tracking** - Stream status is explicitly tracked in stream data
10. **Backward Compatibility** - Support both legacy and current data structures

---

## Key Classes

### VL_License_Manager
Main class for license and stream management:
- Static methods for license operations
- Stream retrieval and filtering
- Status management

---

## Data Storage

### WordPress Options
- `vl_licenses_registry` - License registry
- `vl_hub_profiles` - Client profiles (keyed by license ID)
- `vl_data_streams` - Data streams (keyed by license key)
- `vl_removed_streams_{license_key}` - Removed streams per license

---

## Version History

- **Current** - Active development
  - Stream status tracking
  - Profile refresh capabilities
  - Stream filtering and validation
  - Removed stream tracking

---

*This documentation helps AI agents understand Luna License Manager's role in the Visible Light ecosystem. It serves as the central licensing and profile management system that coordinates data flow between client sites and the Hub.*

