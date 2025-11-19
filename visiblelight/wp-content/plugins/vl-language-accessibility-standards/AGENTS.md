# VL Language & Accessibility Standards - Agent Documentation

## Overview

**VL Language & Accessibility Standards (VL LAS)** is a comprehensive WordPress plugin that provides language, compliance, and accessibility toolkit features. It includes cookie consent banners, legal shortcodes, WCAG audit checks, language detection and translation capabilities, and optional Gemini 2.5-powered language assistance. The plugin also includes Corporate License Code field for secured Hub data access.

**Plugin Name:** VL Language & Accessibility Standards  
**Version:** 1.1.1  
**Author:** Visible Light AI  
**License:** GPLv2 or later  
**Text Domain:** vl-las

---

## Core Components

### 1. Cookie Consent Banner
- **Customizable Banner:** Configurable cookie consent banner
- **Position Options:** Bottom-right, bottom-left, top-right, top-left
- **Visibility Control:** Show/hide based on settings
- **Message Customization:** Customizable consent message
- **JavaScript Integration:** Frontend cookie banner functionality

### 2. Legal Shortcodes
- **Privacy Policy:** `[vl_las_privacy_policy]`
- **Terms of Service:** `[vl_las_terms]`
- **Copyright:** `[vl_las_copyright]`
- **Data Privacy:** `[vl_las_data_privacy]`
- **Cookie Policy:** `[vl_las_cookie]`
- **Customizable Content:** Each shortcode supports custom content

### 3. Accessibility Auditing
- **WCAG Compliance Checks:** Automated accessibility audits
- **Full Audit Engine:** Comprehensive accessibility checking (if available)
- **Regex Audit Engine:** Lightweight regex-based auditing (fallback)
- **HTML/URL Support:** Audit HTML directly or fetch from URL
- **Audit Reports:** Structured audit report generation
- **Report Storage:** Database storage for audit reports

### 4. Language Detection & Translation
- **Language Detection:** Automatic language detection
- **HTML Lang Attribute:** Applies detected language to `<html lang>` attribute
- **Multi-Language Support:** Supports multiple languages
- **Language Switcher:** Frontend language switcher functionality
- **Gemini Integration:** Optional Gemini 2.5 API for translation assistance

### 5. SOC 2 Compliance
- **SOC 2 Snapshots:** Stores and retrieves SOC 2 compliance data
- **Hub Integration:** Connects to VL Hub for SOC 2 data
- **Endpoint Support:** `/wp-json/vl-hub/v1/soc2/snapshot`
- **Compliance Tracking:** Tracks controls, evidence, risks, compliance status

### 6. High Contrast Mode
- **Accessibility Feature:** High contrast CSS for better visibility
- **Body Class:** Adds `vl-las-contrast` class when enabled
- **CSS Enqueuing:** Loads high contrast stylesheet

---

## Core Architecture

### Class Structure

#### Main Classes
- **VL_LAS_Admin:** Admin interface and settings management
- **VL_LAS_Cookie:** Cookie consent banner functionality
- **VL_LAS_Privacy:** Legal shortcodes and privacy features
- **VL_LAS_Accessibility_Audit:** Full accessibility audit engine
- **VL_LAS_Audit_Regex:** Regex-based audit engine (fallback)
- **VL_LAS_Audit_Store:** Database storage for audit reports
- **VL_LAS_Languages:** Language management
- **VL_LAS_Language_Detect:** Language detection functionality
- **VL_LAS_Translate:** Translation capabilities with Gemini
- **VL_LAS_SOC2:** SOC 2 compliance management
- **VL_LAS_REST:** REST API endpoint registration
- **VL_LAS_DB:** Database operations

### Autoloader
- **PSR-4 Style:** Maps `VL_LAS_*` classes to `includes/class-vl-las-*.php`
- **Automatic Loading:** Loads classes on demand
- **Explicit Includes:** Some classes explicitly included for initialization

---

## REST API Endpoints

### Public Endpoints

#### `/wp-json/vl-las/v1/ping` (GET)
- **Purpose:** Health check endpoint
- **Response:** Plugin status and version

#### `/wp-json/vl-las/v1/routes` (GET)
- **Purpose:** List all registered routes
- **Response:** Array of registered route paths and methods

### Admin Endpoints

#### `/wp-json/vl-las/v1/audit` (GET/POST)
- **Purpose:** Run accessibility audit
- **Permissions:** Requires `manage_options` capability
- **GET:** Returns endpoint status
- **POST:** Runs audit with parameters:
  - `url` (optional): URL to audit
  - `html` (optional): HTML content to audit directly
- **Response:** Audit report with engine type (full/regex)

#### `/wp-json/vl-las/v1/gemini-test` (POST)
- **Purpose:** Test Gemini API key
- **Permissions:** Requires `manage_options` capability
- **Response:** API test result with status and response

---

## Configuration Options

### WordPress Options

All options prefixed with `vl_las_`:

- **Languages:** `vl_las_languages` - Array of supported languages
- **Gemini API Key:** `vl_las_gemini_api_key` - Gemini API key for translation
- **License Code:** `vl_las_license_code` - Corporate license code for Hub access
- **Cookie Consent:** `vl_las_cookie_consent_enabled` - Enable/disable cookie banner
- **Cookie Visibility:** `vl_las_cookie_visibility` - Show/hide cookie banner
- **Cookie Position:** `vl_las_cookie_position` - Banner position
- **Cookie Message:** `vl_las_cookie_message` - Consent message text
- **Legal Content:**
  - `vl_las_legal_privacy_policy`
  - `vl_las_legal_terms`
  - `vl_las_legal_copyright`
  - `vl_las_legal_data_privacy`
  - `vl_las_legal_cookie`
- **High Contrast:** `vl_las_high_contrast` - Enable high contrast mode
- **HTML Lang:** `vl_las_apply_html_lang` - Apply language to HTML lang attribute
- **Audit Engine:** `vl_las_audit_engine` - 0=off, 1=diagnostics, 2=regex-only
- **Audit Show JSON:** `vl_las_audit_show_json` - Show JSON in audit reports
- **SOC 2 Enabled:** `vl_las_soc2_enabled` - Enable SOC 2 features
- **SOC 2 Endpoint:** `vl_las_soc2_endpoint` - SOC 2 snapshot endpoint URL

---

## Features

### 1. Cookie Consent Banner
- **Frontend Integration:** JavaScript-based banner
- **Positioning:** Configurable position (bottom-right default)
- **Styling:** Customizable appearance
- **Consent Tracking:** Tracks user consent
- **Compliance:** GDPR/CCPA compliance support

### 2. Legal Shortcodes
- **Privacy Policy:** `[vl_las_privacy_policy]`
- **Terms:** `[vl_las_terms]`
- **Copyright:** `vl_las_copyright]`
- **Data Privacy:** `[vl_las_data_privacy]`
- **Cookie Policy:** `[vl_las_cookie]`
- **Content Management:** Editable through admin interface

### 3. Accessibility Auditing

#### Full Audit Engine (if available)
- **Comprehensive Checks:** Full WCAG compliance checking
- **HTML Analysis:** Analyzes HTML structure
- **URL Fetching:** Can fetch and audit remote URLs
- **Detailed Reports:** Comprehensive audit reports

#### Regex Audit Engine (fallback)
- **Lightweight:** Fast regex-based checking
- **Pattern Matching:** Uses regex patterns for common issues
- **Fallback Mode:** Used when full engine unavailable

#### Audit Report Structure
```php
[
  'timestamp' => 'ISO date',
  'url' => 'audited URL',
  'engine' => 'full'|'regex',
  'issues' => [
    // Array of accessibility issues
  ],
  'summary' => [
    // Summary statistics
  ]
]
```

### 4. Language Features

#### Language Detection
- **Automatic Detection:** Detects page language
- **HTML Lang:** Applies to `<html lang>` attribute
- **Current Language:** `VL_LAS_Language_Detect::current()`
- **Label to Code:** `VL_LAS_Language_Detect::label_to_code($label)`

#### Translation (Gemini)
- **Gemini 2.5 API:** Optional translation using Gemini
- **API Key Required:** Requires Gemini API key
- **Translation Service:** `VL_LAS_Translate` class
- **Test Endpoint:** `/wp-json/vl-las/v1/gemini-test`

### 5. SOC 2 Compliance
- **Snapshot Storage:** Stores SOC 2 snapshots
- **Hub Integration:** Connects to VL Hub endpoint
- **License-Based:** Uses license code for access
- **Data Structure:**
  - Controls
  - Evidence
  - Risks
  - Compliance status
  - Timestamps

### 6. High Contrast Mode
- **CSS Enqueuing:** Loads high contrast stylesheet
- **Body Class:** Adds `vl-las-contrast` class
- **Accessibility:** Improves visibility for users with visual impairments

---

## Database Schema

### Audit Reports Table
- **Table Name:** `{prefix}_vl_las_audit_reports`
- **Columns:**
  - `id` (primary key)
  - `url` (varchar)
  - `report_json` (text)
  - `created_at` (datetime)
  - `updated_at` (datetime)

---

## Integration Points

### With VL Hub
- **SOC 2 Snapshots:** Retrieves from Hub endpoint
- **License Code:** Uses for Hub authentication
- **Data Access:** Secured Hub data access

### With WordPress
- **Shortcodes:** Registers legal shortcodes
- **Admin Interface:** Settings page in WordPress admin
- **Frontend:** Cookie banner and language switcher
- **REST API:** Custom endpoints

### With Gemini API
- **Translation:** Optional translation service
- **API Key:** Stored securely in options
- **Test Endpoint:** Validates API key

---

## File Structure

```
vl-language-accessibility-standards/
├── vl-language-accessibility-standards.php (main plugin file)
├── admin/
│   ├── class-vl-las-admin.php
│   └── views/
│       └── settings-page.php
├── includes/
│   ├── class-vl-las-accessibility-audit.php
│   ├── class-vl-las-audit-regex.php
│   ├── class-vl-las-audit-store.php
│   ├── class-vl-las-cookie.php
│   ├── class-vl-las-db.php
│   ├── class-vl-las-language-detect.php
│   ├── class-vl-las-languages.php
│   ├── class-vl-las-license.php
│   ├── class-vl-las-privacy.php
│   ├── class-vl-las-soc2.php
│   ├── class-vl-las-translate.php
│   └── rest/
│       └── class-vl-las-rest.php
├── assets/
│   ├── css/
│   │   └── high-contrast.css
│   └── js/
│       ├── admin.js
│       ├── cookie-banner.js
│       └── lang-switcher.js
└── languages/
    └── (translation files)
```

---

## Expectations for Agents

When working with VL LAS code, agents should understand:

1. **Accessibility First** - Plugin prioritizes WCAG compliance
2. **Multi-Language Support** - Handles multiple languages
3. **Compliance Focus** - GDPR/CCPA/SOC 2 compliance features
4. **Audit Engines** - Multiple audit engines with fallbacks
5. **Hub Integration** - Connects to VL Hub for SOC 2 data
6. **License Code** - Corporate license code for secured access
7. **Gemini Integration** - Optional AI-powered translation
8. **Error Handling** - Graceful fallbacks for missing components
9. **REST API** - Comprehensive REST API endpoints
10. **Database Storage** - Audit reports stored in database

---

## Key Functions

### Audit Functions
- `VL_LAS_Accessibility_Audit::run_audit($url)` - Run full audit
- `VL_LAS_Accessibility_Audit::run_audit_html($html)` - Audit HTML directly
- `VL_LAS_Audit_Regex::run($html, $url)` - Run regex audit
- `VL_LAS_Audit_Store::install()` - Create audit table

### Language Functions
- `VL_LAS_Language_Detect::current()` - Get current language
- `VL_LAS_Language_Detect::label_to_code($label)` - Convert label to code

### Translation Functions
- `VL_LAS_Translate::translate($text, $target_lang)` - Translate text

### SOC 2 Functions
- `VL_LAS_SOC2::get_snapshot($license_code)` - Get SOC 2 snapshot
- `VL_LAS_SOC2::store_snapshot($data)` - Store snapshot

---

## WP-CLI Commands

### `wp vl-las audit`
- **Purpose:** Run accessibility audit via CLI
- **Parameters:**
  - `--url`: URL to audit (optional, defaults to home URL)
- **Output:** JSON audit report

---

## Version History

- **1.1.1** - Current version
  - Cookie consent banner
  - Legal shortcodes
  - Accessibility auditing (full + regex engines)
  - Language detection and translation
  - SOC 2 compliance
  - High contrast mode
  - Gemini 2.5 integration
  - Corporate license code support
  - REST API endpoints
  - Database storage for audit reports

---

*This documentation helps AI agents understand VL Language & Accessibility Standards' comprehensive features for language, compliance, and accessibility. The plugin provides tools for WCAG compliance, multi-language support, legal content management, and SOC 2 compliance tracking.*

