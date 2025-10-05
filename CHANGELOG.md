# Changelog

## [1.3.1] - 2025-10-04

### ✨ New Features

*   **Breadcrumb Navigation:** Added breadcrumb navigation to the Explore, Country, City, and Detail pages to improve user experience and SEO. The breadcrumbs are fully AMP-compatible.

###  housekeeping

*   **AI Developer Guide Updated:** Updated the `GEMINI.md` file with new core rules for the AI assistant, including a mandate for AMP compatibility and a focus on SEO. Removed the outdated "City Page as Blueprint" rule.
*   **Improved CTA Banner Documentation:** Added comprehensive file and function-level docblocks to `cta-banner.php` to improve code clarity and maintainability.

## [1.3.0] - 2025-10-04

This major update introduces a dynamic "Near You" section to the Explore page and significantly hardens the plugin's API interactions for production environments.

### ✨ New Features

*   **Dynamic "Near You" Section on Explore Page:**
    *   The Explore page now features a "Rollerskating Near You" section that is displayed at the top of the page.
    *   This section is powered by IP-based geolocation to determine the user's approximate location.
    *   A city-matching algorithm compares the user's location against the curated list of cities in `country_data/merged.json`. The "Near You" section is **only displayed** if the user is within the defined radius of a known city, ensuring a high-quality, relevant experience.
    *   When a city is matched, the section uses that city's specific coordinates and radius to display the most popular local skate spots, upcoming events, and nearby skaters.
    *   "View All" links are provided for each section, directing users to the full city-specific pages.
    *   The entire section is hidden from known search engine bots to provide a clean, static page for indexing.

*   **"Featured Cities" Section:**
    *   A new "Featured Cities" section has been added to the Explore page, showcasing prominent skating locations like Paris, New York, Tokyo, and Los Angeles.

### 🚀 Performance & Reliability

*   **24-Hour API Caching:**
    *   All API calls (for spots, events, skaters, and IP geolocation) are now cached for 24 hours using the WordPress Transients API. This dramatically improves performance for repeat visitors and reduces server load.
    *   A cache-busting version number (`LR_CACHE_VERSION`) has been implemented, allowing all caches to be invalidated easily by incrementing the version.
    *   Caching is automatically bypassed when the plugin's "Testing Mode" is enabled.

*   **Self-Healing Authentication:**
    *   The core API fetching function now includes robust retry logic. If an API call fails with a `401 Unauthorized` error (due to an expired token), the system automatically deletes the old token, requests a fresh one, and retries the original API call.
    *   Fixed a critical bug where the "Bearer " prefix was being duplicated in the authentication token, causing all API calls to fail.
    *   Error handling for authentication has been significantly improved to provide clear, actionable error messages.

*   **Robust IP Address Detection:**
    *   The plugin now uses a hardened function to detect the user's real IP address, correctly handling requests that come through reverse proxies or load balancers (like Cloudflare) by checking common server headers (`HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`, etc.).

*   **Standalone Image Proxy:**
    *   The `image-proxy.php` script has been rewritten to be fully self-contained. It now correctly loads the WordPress environment and its own functions to handle authentication and API calls, resolving numerous `502 Bad Gateway` errors that occurred in certain environments.
    *   Corrected the API endpoints used by the proxy for fetching spot and event images, fixing `404 Not Found` errors.

### 🐛 Bug Fixes

*   Fixed a persistent bug where event images were not loading on the Explore page by ensuring the code performs a secondary API call to fetch attachment details, mirroring the logic of the working City page.
*   Resolved numerous fatal errors caused by incorrect function calls and dependency loading issues.

###  housekeeping

*   Added a `.gitignore` file to prevent sensitive environment files (`.env`) and temporary files from being committed to the repository.
