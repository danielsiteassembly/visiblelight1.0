# Supercluster - Agent Documentation

## Overview

**Supercluster** is a WordPress theme and application that provides a 3D visualization dashboard for the Visible Light ecosystem. It creates an immersive, interactive interface for viewing client data, telemetry, and relationships in a three-dimensional space using Three.js.

**Theme Name:** Supercluster  
**Author:** Visible Light  
**License:** GPLv2 or later

---

## Core Functionality

### 3D Visualization Dashboard
- **Three.js Integration:** Uses Three.js r180 for 3D rendering
- **Orbit Controls:** Interactive camera controls for navigation
- **Galaxy Visualization:** Represents clients and data as nodes in a 3D space
- **Interactive Navigation:** Zoom, pan, and rotate controls

### Client Data Visualization
- **Omniscient App Observatory:** View all client sites/apps in a unified interface
- **Data Stream Visualization:** Visual representation of data streams and connectors
- **Category Organization:** Organizes data by categories (Supercluster, Web & Infra, Content, Search Intel, Reporting, Marketing & Ads, Sales & Conversions, Security, CloudOps, Users & Identity, Competitive)

### Navigation & Interface
- **Main Menu:** Category-based navigation menu
- **Right Sidebar:** Widgets for "Omniscient" summary and "Stream Activity"
- **Client Greeting:** Personalized client name display with dropdown
- **License Key Display:** Shows license key with copy functionality
- **Connection Status:** Hub connection status indicator
- **Luna Chat Integration:** Embedded Luna Chat widget in bottom left

---

## Key Features

### 1. Three.js 3D Visualization
- **Canvas Rendering:** 3D canvas for galaxy/constellation visualization
- **Node Representation:** Clients and data represented as 3D nodes
- **Connection Lines:** Visual connections between related nodes
- **Interactive Controls:** Mouse/touch controls for navigation
- **Performance Optimization:** Efficient rendering for large datasets

### 2. Page Templates & Views

#### Main Dashboard
- **Supercluster View:** Main 3D visualization with galaxy
- **Category Pages:** Filtered views by category
- **Stream Pages:** Individual data stream views
- **Omniscient App Observatory:** Unified app/site listing

#### Internal Pages
- **Luna Compose:** `/luna/compose/` or `/luna-compose`
- **Luna Report:** `/luna/report/`
- **Luna Automate:** `/luna/automate/`
- **Shared Documents:** `/invite_from/luna/compose/{document_id}`

### 3. Omniscient App Observatory
- **Site Listing:** Grid/list view of all client sites
- **Search Functionality:** Search sites by title, URL, or domain
- **View Toggle:** Switch between list and block views
- **Site Cards:** Display site information with:
  - OG images
  - Site titles and URLs
  - Status badges (ping status, SSL/TLS, etc.)
  - Click-through to sites

### 4. Widget System
- **Omniscient Widget:** Summary of client's observatory
- **Stream Activity Widget:** Recent activity feed
- **Widget Toggle:** Show/hide widgets
- **Status Indicators:** Visual status icons

### 5. Authentication & Access
- **Login Page:** Custom login interface at `/supercluster-ai-constellation-login/`
- **License-Based Access:** Access controlled by license keys
- **Client Authentication:** User authentication for client access
- **Session Management:** Maintains user sessions

---

## Technical Architecture

### Frontend Technologies
- **Three.js 0.180.0:** 3D rendering library
- **OrbitControls:** Camera control system
- **ES6 Modules:** Modern JavaScript module system
- **CSS3:** Styling and animations

### WordPress Integration
- **Theme Structure:** Standard WordPress theme structure
- **Template Hierarchy:** Custom page templates
- **REST API:** Integrates with VL Hub REST endpoints
- **Enqueue Scripts:** Proper script/style enqueuing

### Data Sources
- **VL Hub REST API:** Fetches client data from Hub
- **Constellation Endpoint:** `/wp-json/vl-hub/v1/constellation`
- **Client Sites Endpoint:** `/wp-json/vl-hub/v1/client-sites`
- **Profile Endpoint:** `/wp-json/vl-hub/v1/profile`

---

## Key Components

### JavaScript Modules

#### Supercluster Load Animation
- **File:** `assets/js/supercluster-load-animation.js`
- **Purpose:** Loading animation for main dashboard
- **Trigger:** Only loads on main dashboard (not internal pages)

#### Main Script (index.html)
- **Three.js Setup:** Scene, camera, renderer initialization
- **Orbit Controls:** Interactive camera controls
- **Page Detection:** Detects current page type
- **Canvas Management:** Shows/hides canvas based on page type
- **Label Management:** Shows/hides galaxy labels based on page type

### Page Detection Logic
```javascript
// Detects page types:
- isOmniscientPage: /omniscient-app-observatory/
- isLunaComposePage: /luna-compose or /luna/compose/
- isLunaReportPage: /luna/report/
- isLunaAutomatePage: /luna/automate/
- isSharedComposePage: /invite_from/luna/compose/
- isStreamPage: /content/data-stream/
- isCategoryPage: /content/category/
- isMainDashboardPage: Main Supercluster view
```

### Canvas & Label Management
- **hideThreeJSCanvas():** Hides 3D canvas on internal pages
- **hideSuperclusterLabels():** Hides galaxy labels on internal pages
- **Main Dashboard:** Canvas and labels always visible
- **Internal Pages:** Canvas and labels hidden

---

## REST API Integration

### Endpoints Used

#### `/wp-json/vl-hub/v1/client-sites` (GET)
- **Purpose:** Get all sites for a client
- **Parameters:** `license` (query parameter)
- **Response:** Array of site objects with:
  - `title`: Site title
  - `url`: Site URL
  - `domain`: Domain name
  - `og_image`: Open Graph image URL
  - `ping_color`: Status color
  - `ping_label`: Status label
  - SSL/TLS status
  - Other metadata

#### `/wp-json/vl-hub/v1/constellation` (GET)
- **Purpose:** Get constellation dataset
- **Parameters:** `license` (optional filter)
- **Response:** Constellation dataset with clients, categories, nodes

#### `/wp-json/vl-hub/v1/profile` (GET)
- **Purpose:** Get client profile
- **Headers:** `X-Luna-License` or `license` parameter
- **Response:** Comprehensive client profile

---

## UI Components

### Header
- **Logo:** Visible Light logo
- **Client Greeting:** "Hello, {Client Name}" with dropdown
- **Dropdown Menu:**
  - Account Settings
  - Log Out

### Main Navigation Menu
Categories:
- Supercluster (active by default)
- Web & Infra
- Content
- Search Intel
- Reporting
- Marketing & Ads
- Sales & Conversions
- Security
- CloudOps
- Users & Identity
- Competitive

### Bottom Left
- **License Key:** Display with copy button
- **Connection Status:** Hub status indicator
- **Luna Chat:** Embedded chat widget container

### Bottom Right
- **Controls:** Navigation controls
  - Move Left (←)
  - Move Right (→)
  - Zoom In (+)
  - Zoom Out (−)

### Right Sidebar
- **Omniscient Widget:** Client observatory summary
- **Stream Activity Widget:** Recent activity feed

### Modals & Overlays
- **Client Dropdown Lightbox:** Account menu
- **Logout Confirmation Modal:** Logout confirmation
- **Tutorial Overlay:** Interactive tutorial system

---

## Styling & Assets

### CSS
- **External Stylesheet:** `https://supercluster.visiblelight.ai/styles.css`
- **Inline Styles:** Component-specific inline styles
- **Responsive Design:** Mobile-friendly layouts

### Images & Icons
- **Logo:** Visible Light icon logo
- **Category Icons:** SVG icons for each category
- **Status Icons:** Checkmarks, arrows, etc.
- **Toggle Icons:** Eye icons for show/hide

---

## Data Flow

### Initial Load
1. **Page Detection:** Determine page type
2. **Canvas Setup:** Initialize Three.js if main dashboard
3. **Data Fetching:** Load client data from Hub
4. **Rendering:** Render 3D visualization or page content
5. **Widget Population:** Load widget data

### Navigation
1. **Menu Click:** User clicks category menu item
2. **Page Update:** Update URL or load new content
3. **Canvas Management:** Show/hide canvas as needed
4. **Data Refresh:** Fetch new data if needed

### Omniscient App Observatory
1. **License Extraction:** Get license from URL
2. **API Call:** Fetch sites from `/wp-json/vl-hub/v1/client-sites`
3. **Rendering:** Render site cards in grid/list
4. **Search/Filter:** Filter sites based on search input
5. **View Toggle:** Switch between list and block views

---

## Expectations for Agents

When working with Supercluster code, agents should understand:

1. **3D Visualization** - Uses Three.js for immersive data visualization
2. **Page Detection** - Different pages require different rendering approaches
3. **Canvas Management** - Canvas should be hidden on internal pages
4. **Data Integration** - Integrates with VL Hub REST API
5. **Performance** - Efficient rendering for large datasets
6. **User Experience** - Intuitive navigation and interaction
7. **Responsive Design** - Works on various screen sizes
8. **Luna Integration** - Embedded Luna Chat widget
9. **License-Based Access** - Access controlled by license keys
10. **Widget System** - Modular widget architecture

---

## Key Functions

### JavaScript Functions
- `renderOmniscientAppObservatory()` - Renders Omniscient page
- `hideThreeJSCanvas()` - Hides 3D canvas on internal pages
- `hideSuperclusterLabels()` - Hides galaxy labels on internal pages
- `resolveObservatoryImage()` - Resolves OG image for sites
- `escapeAttr()` - Escapes HTML attributes

### PHP Functions (if any)
- Theme template functions
- REST API integration helpers

---

## Version History

- **Current** - Active development
  - Three.js 0.180.0 integration
  - Omniscient App Observatory
  - Luna Compose/Report/Automate integration
  - Enhanced page detection
  - Improved canvas management
  - Widget system
  - Tutorial system

---

*This documentation helps AI agents understand Supercluster's role as the visualization dashboard for the Visible Light ecosystem. It provides an immersive 3D interface for exploring client data, relationships, and telemetry.*

