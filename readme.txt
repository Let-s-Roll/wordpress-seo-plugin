=== Let's Roll SEO Pages WordPress Plugin ===
Contributors: (Your Name)
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.17.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
A WordPress plugin to dynamically generate SEO-friendly pages for skate spots, events, and skaters from the external Let's Roll App API.

== Description ==
This plugin was created to solve a key business objective: leveraging the rich, user-generated data within the Let's Roll mobile app to create a vast network of SEO-optimized landing pages on the main WordPress website. The primary goal is to attract organic search traffic from users searching for local roller skating information (e.g., "skate spots in berlin", "rollerskating events near me").
The plugin does not store content locally. Instead, it acts as a sophisticated rendering engine, creating "virtual pages" on the fly by fetching all necessary data from the Let's Roll API in real-time.

A key feature is the dynamic "Near You" section on the Explore page. This uses a robust, server-side IP geolocation system to identify the user's location and match it to a known city in our database. If a match is found, it displays relevant local content. This section is hidden from search engine bots to ensure clean indexing. The Explore page also includes a curated "Featured Cities" section to highlight major skating hubs.

The plugin also features a Content Discovery and Automation architecture designed to proactively find, generate, and publish new content with minimal manual intervention. It uses a custom database table to store a queue of discovered content items (spots, events, etc.) and a robust background processing system to generate AI-powered articles. This includes a "historical seeder" to rapidly build out a baseline of content for all known cities.

== Core Architecture ==
The plugin is built on a "Virtual Post" architecture, which is a robust and standard WordPress development pattern. This was chosen to ensure maximum compatibility with existing themes and plugins, and to solve issues with URL conflicts and content duplication that arise from more basic approaches.
The core process is as follows:
Precise URL Rewrites: The plugin programmatically generates a set of highly specific URL rewrite rules based on the locations defined in its settings. This ensures that it only responds to its own URLs (e.g., /germany/berlin/, /spots/{id}) and NEVER interferes with real WordPress pages like /news/ or /about/.
Virtual Post Injection: When a user visits one of these URLs (including the main /explore/ page), the plugin hooks into WordPress's the_posts filter. This happens very early in the page load process. The plugin then creates a single, "fake" post object in memory.
Dynamic Content Generation: The plugin generates the title and content for this virtual post by making one or more secure, server-side calls to the Let's Roll API.
Theme Rendering: The plugin hands this single, complete virtual post back to WordPress. The active theme then renders this post just like it would any normal page, ensuring all theme styling, headers, footers, and other plugins (like AMP) work correctly.
This architecture is stable, scalable, and avoids the common pitfalls of dynamic page generation in WordPress.

== File Structure ==
The plugin is organized into a main controller file, an admin file, and several template files for rendering content.
lets-roll-seo-pages.php (The Main Controller)
This is the heart of the plugin.
It handles plugin activation/deactivation hooks.
It contains all the core API functions for authentication (lr_get_api_access_token) and data fetching (lr_fetch_api_data).
It programmatically generates all the URL rewrite rules.
It contains the main lr_virtual_page_controller function that creates the virtual posts.
It includes all other necessary files.
admin/admin-page.php (The Settings Page)
Creates the "Let's Roll SEO" settings page under the main "Settings" menu in the WordPress admin.
Handles the saving of API credentials and the main locations JSON data.
Contains the logic for the "Generate Sitemap CSV" utility.
admin/content-discovery-page.php (The Content Discovery Page)
Provides the admin interface for all content discovery and automation tools.
Includes buttons to manually trigger content scans and the "Seed All Cities (Historical)" batch process.
Displays logs and progress indicators for background tasks.
includes/content-publication.php (The Content Publication Engine)
Contains the core backend logic for the content automation system.
It defines the batch processing functions that iterate through cities, aggregate historical data, and generate AI-powered posts.
It hooks into WordPress's cron system to run these tasks in the background.
cta-banner.php (The App Install Banner)
Contains all the HTML, CSS, and logic for the dismissible "Install the App" banner that appears in the footer.
It is built to be AMP-compatible using a CSS-only "checkbox hack" for the close button, avoiding any custom JavaScript that would be stripped by the AMP plugin.
image-proxy.php (The Secure Image Handler)
A small, dedicated script that acts as a proxy for fetching images from protected API endpoints (like spot satellite images).
The page templates use this proxy in <img> tags to securely display images without exposing API tokens to the user's browser.
templates/ (The View Layer)
This directory contains all files responsible for generating the HTML content for the virtual pages. Each file contains one or more functions that fetch data and return an HTML string.
template-explore-page.php: Renders the main /explore/ hub page with a list of all countries.
template-country-page.php: Renders a country's overview page.
template-city-page.php: Renders a city's overview page, including the "Top 3" sections for spots, skaters, and events.
template-detail-page.php: Renders the paginated list pages for a city's spots, events, or skaters.
template-single-spot.php: Renders the final detail page for an individual skate spot.
template-single-event.php: Renders the final detail page for an individual event.
template-single-skater.php: Renders the final detail page for an individual skater's profile.
template-single-activity.php: Renders the detail page for an individual skate session or post, complete with an image slideshow.

== Setup & Installation ==
Upload the lets-roll-seo-pages folder to the /wp-content/plugins/ directory.
Activate the plugin through the 'Plugins' menu in WordPress.
Navigate to Settings -> Let's Roll SEO.
Enter your API Email and Password.
Enter your Google Custom Search API Key and Search Engine ID.
Paste your comprehensive locations data into the "Locations JSON" text area.
Click "Save Settings".
Navigate to Settings -> Permalinks and click "Save Changes". This is a critical step that saves the plugin's custom URL rules to the database. This must be done every time the plugin is activated or the locations data is significantly changed.

== Key Features ==
Dynamic Page Hierarchy: Creates pages for countries, cities, and detail lists (spots, skaters, events).
Individual Item Pages: Creates unique, shareable URLs for every single spot, skater, and event.
Enriched Spot & Activity Pages: The plugin enhances spot list pages with a "Top 3 Most Active Spots" section, complete with the latest user reviews. Individual activity pages feature an interactive, AMP-compatible image slideshow.
Secure & Robust API Handling: All API calls are made on the server-side. The access token is securely cached, and the system includes self-healing logic to automatically re-authenticate if a token expires. IP detection is hardened to work behind reverse proxies.
Configurable Locations: All countries, cities, coordinates, and descriptions are managed from a single JSON object on the settings page, making it easy to update and expand.

Content Discovery & Automation: A powerful background processing system that automatically discovers new content from the API and queues it for publication. Includes a "Seed All Cities" feature to rapidly generate a baseline of historical content for all locations.
Sitemap Generation: Includes a utility to generate a CSV sitemap of all primary pages, formatted for import into SEO plugins like AIOSEO.
AMP-Compatible CTA Banner: A dismissible "Install the App" banner that works correctly on AMP-enabled sites.
Robust Caching Strategy: The plugin is designed to work with caching plugins like W3 Total Cache. By pre-warming the cache (using the caching plugin's sitemap feature), the dynamically generated pages can be served as fast, static HTML files to all users.

== Changelog ==

= 1.14.0 =
*   **Major Feature: Content Automation Engine:**
    *   **Content Discovery:** Implemented a new, daily background process that automatically scans the Let's Roll API to discover new content (spots, events, reviews, sessions, and newly seen skaters).
    *   **Content Publication:** A new cron job (`lr_publication_cron`) generates monthly "City Update" posts, summarizing all new content for each city.
    *   **AI-Powered Summaries:** The publication system uses AI to generate engaging, SEO-friendly titles, summaries, and section intros for each city update post.
*   **New Admin Interface:**
    *   **"Content Discovery" Page:** A new admin page to monitor the discovery process, view logs, and manually trigger content discovery.
    *   **Historical Seeding:** Added a "Seed All Cities (Historical)" feature to generate a baseline of content for the last 6 months across all cities.
*   **Database Schema:**
    *   Added new custom database tables (`wp_lr_discovered_content`, `wp_lr_city_updates`, `wp_lr_seen_skaters`) to store discovered content and manage the publication process.
*   **Under the Hood:**
    *   Implemented a robust, queue-based background processing system for content discovery to ensure reliability and prevent server timeouts.

= 1.13.1 =
*   **Feature:** Added a new "Import/Export" admin page. This allows for the easy migration of all discovered content and generated city update posts between different WordPress instances (e.g., from a staging site to a live site) via a JSON file.
*   **Fix:** Hardened the historical seeder against fatal crashes caused by malformed URLs in the AI-generated content. The system now validates URLs before attempting to fetch them, logging the error and skipping the invalid link instead of halting the entire process.
*   **Tweak:** Reduced the delay between historical seeding batches from 60 seconds to 5 seconds to accelerate the process of catching up on cities that have already been processed.

= 1.13.0 =
* Feature: Skip Existing Posts during Historical Seeding. The historical seeder now checks for existing posts (based on a deterministic slug) before generating new AI content, significantly improving efficiency on re-runs and preventing redundant AI calls.
* Feature: City Filter for Generated Posts. Added a dropdown filter to the "Generated City Update Posts" section on the admin page, allowing users to easily view posts for a specific city.
* Fix: Prevented Duplicate Posts. Resolved an issue where `wpdb->replace()` was creating duplicate posts due to non-deterministic post slugs. The `post_slug` is now consistently generated from the city slug and time bucket, ensuring proper overwriting.
* Fix: Improved Seeder Stability & Crash Recovery. Implemented several enhancements to prevent crashes and improve recovery:
    * Reduced `LR_SEEDING_BATCH_SIZE` to 1 to prevent memory accumulation and crashes across multiple city processes.
    * Increased PHP memory limit to `512M` in `lets-roll-seo-pages.php` to handle larger data payloads during AI content generation.
    * Added a "heartbeat" mechanism (`lr_seeding_current_city`) for granular crash detection, allowing the admin page to display the exact city where a crash occurred.
    * Updated the "Force Unlock & Reset" button to clear the new heartbeat option.
* Under the Hood: Removed temporary diagnostic logging from `includes/ai-content.php` that was used for crash analysis.

= 1.10.0 =
*   **Feature:** Intelligent Link Verification Cascade. Implemented a robust, multi-step cascade to verify and correct external links in AI-generated content. The system uses an intelligent liveness check, a "Refresh Search" for dead links, and an AI-powered "Broad Search" to evaluate and select the most contextually relevant replacement link, significantly improving link quality and reliability.
*   **Under the Hood:** Comprehensive Verification Logging. The entire link verification cascade is now logged in detail to `link_verification.csv`, providing full transparency into the decision-making process for debugging and analysis.

= 1.9.0 =
*   **Feature:** High-Quality External Links via Google Custom Search API. Implemented a new link verification system that uses the Google Custom Search API to replace low-quality, AI-generated URLs with authoritative, primary sources.
*   **Fix:** Resolved AI Content Rendering Bug. Corrected a critical bug where AI-generated content was not appearing on the front end due to issues with HTML sanitization and a pass-by-reference error in the link verification loop.
*   **Refactor:** Unified Post Generation Logic. Refactored the historical seeder to use the main post generation function, ensuring all posts (historical and live) are created with the same consistent logic, including the new link verification step.

= 1.8.0 =
*   **Feature:** "Latest Update" Banner on City Pages. Displays a prominent banner on each city page featuring the most recent city update post, complete with featured image, title, summary, and relevant links.
*   **Feature:** Site-Wide Breadcrumb Navigation. Implemented a new, centralized breadcrumb system for all front-end pages, correctly rooting all paths from the `/explore/` page.
*   **Enhancement:** Improved AI Content Strategy. The AI prompt is now more strategic, teaching the AI to find a "hook" in the content to create more authentic, specific titles and to correctly handle mixed-tense (past and future) content.
*   **Enhancement:** Correct Event Timing in Updates. The content aggregation logic now treats events as "previews," ensuring that a post generated at the end of one month correctly features events for the upcoming month.
*   **Fix:** Resolved Historical Seeding Bugs. Fixed critical issues where historical seeding would create duplicates or miss posts due to slug collisions and database errors with `NULL` image URLs.
*   **Fix:** Resolved Fatal Error on Breadcrumb Implementation. Fixed a "Cannot redeclare function" fatal error by removing the old, duplicate breadcrumb function.

= 1.5.0 =
* FIX(style): Fixed a styling regression where the session list on single spot pages and the meta info box on single activity pages lost their borders and background. The specific CSS rules for these components have been moved to the global stylesheet to ensure they are applied consistently.

= 1.4.9 =
* FIX(style): Resolved a persistent mobile padding issue on AMP pages by increasing the specificity of the CSS selector to `body .entry-content`. This ensures the plugin's horizontal padding rules override the theme's default styles.

= 1.4.8 =
* FIX: Restored dynamic, page-specific text to the CTA banner. A new helper function now inspects the page's query variables to ensure the banner text is relevant to the content being viewed.

= 1.4.7 =
* FIX: Restored the missing CTA banner on all front-end pages. The banner is now hooked into the `wp_footer` action to ensure it is displayed reliably across all templates.

= 1.4.6 =
* FIX: Resolved a fatal error on the Explore page (`Call to undefined function lr_calculate_distance()`) by adding the missing distance calculation utility function.
* FIX: Prevented a potential PHP notice on the Explore page by ensuring spot statistics are only displayed for spot items in the "Near You" grid.

= 1.4.5 =
* STYLE: Improved the visual presentation of skate spot tiles across the city, explore, and skatespot list pages for better balance and readability.
* STYLE: Increased the height of spot images from 120px to 180px to make them more prominent.
* STYLE: Significantly reduced the vertical spacing between the spot name and the stats bar (stars, skater count) by adjusting CSS padding and removing flex-grow properties.
* STYLE: Changed the "Near You" section on the explore page to a 3-column grid (down from 4) to prevent a cramped appearance on desktop.

= 1.4.4 =
* FIX: Corrected a layout issue on the skatespot list page (`/skatespots/`) where the "Top 3 Most Active Spots" section was not rendering as a grid. Moved the CSS style block to the beginning of the render function to ensure styles were loaded before the content, fixing the visual alignment and adding the correct borders.

= 1.4.3 =
* ENHANCEMENT: Enriched the "Top 3 Most Active Spots" section on skatespot detail pages with stats (rating, skater count, session count) to provide more context.
* FORMATTING: Centered the stats block for better mobile presentation and prevented line breaks between numbers and labels.

= 1.4.2 =
* FIX: Changed the Brevo sync worker's fallback schedule from hourly to every ten minutes to ensure more timely processing if the self-scheduling mechanism fails.

= 1.4.1 =
* FIX: Resolved a critical issue where Brevo sync cron jobs would fail to run due to being loaded only in an admin context.
* FIX: Improved cron job logging and persistence for better debugging.
* FIX: Fixed the admin UI to correctly display the activity log from background processes.