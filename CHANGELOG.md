# Changelog

## 1.8.0 - 2025-10-31

### ✨ Features & Enhancements

*   **"Latest Update" Banner on City Pages:**
    *   Displays a prominent banner on each city page featuring the most recent city update post.
    *   The banner includes the post's featured image, title, summary, a "Read More" link to the full post, and a "See all updates" link to the city's update archive.
*   **Site-Wide Breadcrumb Navigation:**
    *   Implemented a new, centralized breadcrumb system for all front-end pages.
    *   The breadcrumbs correctly start from the `/explore/` page and handle all page types, including country, city, detail pages, and the new city update archives and single posts.
*   **Improved AI Content Strategy:**
    *   Enhanced the AI prompt to be more strategic, teaching it to find a "hook" in the content to create more authentic, specific titles.
    *   The AI now correctly frames posts as a forward-looking guide for the upcoming month while gracefully recapping past discoveries in the summary.
*   **Correct Event Timing in Updates:**
    *   Updated the content aggregation logic to treat events as "previews." An event happening in October will now correctly be included in the post generated at the end of September, making the content more timely and useful.

### 🐛 Bug Fixes

*   **Fixed Historical Seeding Duplicates & Missing Posts:**
    *   Resolved a critical bug where historical seeding would create fewer posts than expected or generate duplicates. This was traced to two issues:
        1.  **Slug Collisions:** Post slugs are now made unique by appending the date bucket (e.g., `-2025-09`), preventing overwrites.
        2.  **Database Errors:** The `featured_image_url` column in the `wp_lr_city_updates` table is now correctly set to allow `NULL` values, preventing posts without images from failing to save.
*   **Resolved Fatal Error on Breadcrumb Implementation:** Fixed a "Cannot redeclare function" fatal error by removing the old, duplicate breadcrumb function from the main plugin file.

## 1.7.0 - (Date)

### ✨ Features & Enhancements

*   **AI-Powered Content Generation:** Implemented a full-featured AI content generation system for city update pages.
    *   The AI acts as "content glue," generating engaging, SEO-friendly titles, summaries, and section intros that wrap around the visual content cards.
    *   Includes a robust fallback system that reverts to template-only rendering if the AI call fails, ensuring reliability.
    *   Adds a new admin setting to select the Gemini AI model, with a dynamic fetch to list available models.
*   **Flexible Update Frequency:** Adds a global setting to switch content bucketing between **Weekly** and **Monthly**, allowing for better handling of cities with different activity levels.
*   **Enhanced Visuals & UX:**
    *   The `/updates/` archive page now has a blog-style layout with a **Featured Image** for each post.
    *   AI-generated summaries and historical post dates are now displayed on the archive page.
    *   Sessions are now displayed in a clean, "boxed-in" card format instead of a simple list.
    *   Historical seeding is now correctly limited to content from the last 6 months.

### 🐛 Bug Fixes

*   **AMP-Compliant Avatar Placeholders:** Implemented a robust, server-side fallback for skater avatars using the image proxy. This fixes the issue where placeholders were not appearing on AMP pages.
*   **Fixed AI Fatal Error:** Resolved a PHP Parse Error in the AI prompt construction.
*   **Fixed AI Content Integration:** Corrected multiple bugs to ensure AI-generated text snippets are correctly fetched, parsed, and displayed in the templates.

## 1.6.0 - (Unreleased)

### ✨ Enhancements
*   **Rich Content Cards:** Events and Reviews on the city update pages are now displayed as rich, visual cards, matching the style of the main city pages for a consistent user experience.
*   **Enriched Discovery Data:** The content discovery process now fetches and stores full, detailed data for events (including images) and reviews (including user profiles and star ratings) to power the new card-based layouts.

### 🐛 Bug Fixes
*   **Unified Card Styling:** Fixed a bug where skater, spot, and event cards on the update pages were missing the correct border and styling. All cards now have a consistent appearance.
*   **Event Discovery Logic:** Corrected a critical bug in the event discovery process that caused events to appear in the wrong city. The logic now correctly uses a hybrid approach, fetching geo-located events and supplementing them with true "orphan" events (those without a spot ID) from the local feed.

### ✨ New Features

*   **Content Discovery System (Layer 1):**
    *   Introduced a new, daily background process that automatically scans the Let's Roll API to discover new content.
    *   The system discovers five types of content: new spots, events, reviews, skate sessions, and newly seen skaters in a city.
    *   It uses a combination of API `createdAt` timestamps and a local "memory" database to accurately detect new content.

*   **Dedicated Admin Monitoring Page:**
    *   Added a new **"Content Discovery"** admin page to monitor and test the new system.
    *   The page includes a real-time **Activity Log** to provide visibility into the background discovery process.
    *   It features a **"Run Content Discovery Now"** button that uses an asynchronous, queue-based AJAX process to allow for safe, on-demand testing without causing server timeouts.
    *   A log of all permanently discovered content is displayed in a table, with controls to clear it for testing.

*   **Robust Asynchronous Processing:**
    *   The entire discovery process (both manual and scheduled) is now handled by a queue-based background worker. This breaks the task into small, per-city jobs to ensure it can run reliably without hitting execution time limits, even on a large number of cities.

*   **Admin Menu Reorganization:**
    *   All plugin admin pages ("SEO Settings," "Brevo Sync," and the new "Content Discovery") are now consolidated under a single, top-level **"Let's Roll"** menu with a custom icon for a cleaner, more organized interface.

### 🛠 Under the Hood

*   **Custom Database Tables:**
    *   Added a new custom database table, `wp_lr_discovered_content`, to act as a permanent, "atomic ledger" of all content found by the discovery system.
    *   Added a second table, `wp_lr_seen_skaters`, to track which skaters have been seen in which cities, enabling the "newly seen skater" discovery logic.

## 1.5.0
*   **FIX(style):** Fixed a styling regression where the session list on single spot pages and the meta info box on single activity pages lost their borders and background. The specific CSS rules for these components have been moved to the global stylesheet to ensure they are applied consistently.

## 1.4.9
*   **FIX(style):** Resolved a persistent mobile padding issue on AMP pages by increasing the specificity of the CSS selector to `body .entry-content`. This ensures the plugin's horizontal padding rules override the theme's default styles.

## 1.4.8
*   **FIX:** Restored dynamic, page-specific text to the CTA banner. A new helper function now inspects the page's query variables to ensure the banner text is relevant to the content being viewed.

## 1.4.7
*   **FIX:** Restored the missing CTA banner on all front-end pages. The banner is now hooked into the `wp_footer` action to ensure it is displayed reliably across all templates.

## 1.4.6
*   **FIX:** Resolved a fatal error on the Explore page (`Call to undefined function lr_calculate_distance()`) by adding the missing distance calculation utility function.
*   **FIX:** Prevented a potential PHP notice on the Explore page by ensuring spot statistics are only displayed for spot items in the "Near You" grid.

## 1.4.5
*   **STYLE:** Improved the visual presentation of skate spot tiles across the city, explore, and skatespot list pages for better balance and readability.
    *   Increased the height of spot images from 120px to 180px to make them more prominent.
    *   Significantly reduced the vertical spacing between the spot name and the stats bar (stars, skater count) by adjusting CSS padding and removing flex-grow properties.
    *   Changed the "Near You" section on the explore page to a 3-column grid (down from 4) to prevent a cramped appearance on desktop.

## 1.4.4
*   **FIX:** Corrected a layout issue on the skatespot list page (`/skatespots/`) where the "Top 3 Most Active Spots" section was not rendering as a grid. Moved the CSS style block to the beginning of the render function to ensure styles were loaded before the content, fixing the visual alignment and adding the correct borders.

## 1.4.3
*   **ENHANCEMENT:** Enriched the "Top 3 Most Active Spots" section on skatespot detail pages with stats (rating, skater count, session count) to provide more context.
*   **FORMATTING:** Centered the stats block for better mobile presentation and prevented line breaks between numbers and labels.

## 1.4.2
*   **FIX:** Changed the Brevo sync worker's fallback schedule from hourly to every ten minutes to ensure more timely processing if the self-scheduling mechanism fails.

## 1.4.1
*   **FIX:** Resolved a critical issue where Brevo sync cron jobs would fail to run due to being loaded only in an admin context.
*   **FIX:** Improved cron job logging and persistence for better debugging.
*   **FIX:** Fixed the admin UI to correctly display the activity log from background processes.

## [1.4.0] - 2025-10-06

### ✨ New Features

*   **Brevo Skater Location Sync:**
    *   Added a comprehensive new tool under "Settings -> Brevo Sync" to enrich Brevo contacts with city data based on their activity in the Let's Roll app.
    *   **Dry-Run & Testing:** The tool allows you to load a list of skaters for any city without making changes, and then test the enrichment process on a single skater to ensure the connection is working.
    *   **Single-City & Full Sync:** You can run the sync process for just one selected city or for all cities at once.
    *   **Duplicate Prevention:** The tool now keeps a log of all skaters that have been successfully processed. It will automatically skip these skaters in future runs to prevent redundant API calls.
    *   **Log Management:** A "Processed Skaters Log" is now visible on the admin page. You can view which city each skater was synced to, remove individual skaters from the log to re-sync them, or clear the entire log for a full reset.
    *   **IP Whitelisting Helper:** The admin page now displays the server's public IP address to make it easy to whitelist in the Brevo API settings.
    *   **Geographically Accurate Filtering:** The skater fetching logic has been significantly improved. It now uses the distance data from the API's `activities` object to ensure that only skaters genuinely within a city's defined radius are included, making the sync data much more accurate.

## [1.3.3] - 2025-10-06

### 🐛 Bug Fixes

*   **Fixed CTA Banner on Explore Page:** Resolved an issue where the Call-to-Action banner on the `/explore` page was duplicated, incorrectly positioned at the top of the page, and had a non-functional close button. The banner now renders correctly in the footer and is fully functional, matching the behavior on all other pages.
*   **Corrected Mobile Button Sizes:** Fixed a visual bug in the CTA banner where the Apple App Store and Google Play Store buttons were rendered at different sizes on mobile devices. Both buttons now have a consistent height and alignment.

## [1.3.2] - 2025-10-06

###  housekeeping

*   **AI Developer Guide Updated:** Refined the `GEMINI.md` instructions to be more direct and professional. Removed the "Rollie" persona and added a clear system mandate defining the AI's role and the project's context.

### ✨ SEO & Engagement Improvements

*   **Richer City Pages:** Increased the number of items displayed on city pages from 3 to 6 for skate spots, local skaters, and upcoming events. This provides a more comprehensive overview of the local scene for visitors, addressing the high bounce rate identified in analytics.
*   **Improved "Near You" Accuracy:** Standardized the city radii in `country_data/merged.json` using a tiered system based on city size (Mega-Hub, Major, Standard, Compact). This improves the consistency and accuracy of the IP-based city matching for the "Near You" feature on the Explore page.
*   **Geolocation Debugging:** Added temporary logging to the Explore page to provide visibility into the IP geolocation and city-matching process, allowing for easier debugging and refinement of the "Near You" feature.

### 🐛 Bug Fixes

*   **Corrected Indianapolis City Data:** Fixed a data entry error in `country_data/merged.json` where the city of Indianapolis was missing its name and coordinates, causing it to appear as an empty list item on the United States country page.
*   **Removed Debug Logging:** Removed temporary `error_log` statements from the Explore page template that were used for debugging the geolocation feature.

## [1.3.1] - 2025-10-04

### ✨ New Features

*   **CTA Banner on Explore Page:** Added the app download CTA banner to the main Explore page to improve user conversion.
*   **Breadcrumb Navigation:** Added breadcrumb navigation to the Explore, Country, City, and Detail pages to improve user experience and SEO. The breadcrumbs are fully AMP-compatible.

### 🐛 Bug Fixes

*   **CTA Banner Formatting:** Corrected the implementation of the CTA banner on the Explore page to ensure it renders at full-width by hooking into the `wp_footer` action, consistent with other page templates.

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