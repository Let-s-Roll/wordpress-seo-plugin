=== Let's Roll SEO Pages WordPress Plugin ===
Contributors: (Your Name)
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
A WordPress plugin to dynamically generate SEO-friendly pages for skate spots, events, and skaters from the external Let's Roll App API.

== Description ==
This plugin was created to solve a key business objective: leveraging the rich, user-generated data within the Let's Roll mobile app to create a vast network of SEO-optimized landing pages on the main WordPress website. The primary goal is to attract organic search traffic from users searching for local roller skating information (e.g., "skate spots in berlin", "rollerskating events near me").
The plugin does not store content locally. Instead, it acts as a sophisticated rendering engine, creating "virtual pages" on the fly by fetching all necessary data from the Let's Roll API in real-time.

A key feature is the dynamic "Near You" section on the Explore page. This uses a robust, server-side IP geolocation system to identify the user's location and match it to a known city in our database. If a match is found, it displays relevant local content. This section is hidden from search engine bots to ensure clean indexing. The Explore page also includes a curated "Featured Cities" section to highlight major skating hubs.

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
Paste your comprehensive locations data into the "Locations JSON" text area.
Click "Save Settings".
Navigate to Settings -> Permalinks and click "Save Changes". This is a critical step that saves the plugin's custom URL rules to the database. This must be done every time the plugin is activated or the locations data is significantly changed.

== Key Features ==
Dynamic Page Hierarchy: Creates pages for countries, cities, and detail lists (spots, skaters, events).
Individual Item Pages: Creates unique, shareable URLs for every single spot, skater, and event.
Enriched Spot & Activity Pages: The plugin enhances spot list pages with a "Top 3 Most Active Spots" section, complete with the latest user reviews. Individual activity pages feature an interactive, AMP-compatible image slideshow.
Secure & Robust API Handling: All API calls are made on the server-side. The access token is securely cached, and the system includes self-healing logic to automatically re-authenticate if a token expires. IP detection is hardened to work behind reverse proxies.
Configurable Locations: All countries, cities, coordinates, and descriptions are managed from a single JSON object on the settings page, making it easy to update and expand.

Sitemap Generation: Includes a utility to generate a CSV sitemap of all primary pages, formatted for import into SEO plugins like AIOSEO.
AMP-Compatible CTA Banner: A dismissible "Install the App" banner that works correctly on AMP-enabled sites.
Robust Caching Strategy: The plugin is designed to work with caching plugins like W3 Total Cache. By pre-warming the cache (using the caching plugin's sitemap feature), the dynamically generated pages can be served as fast, static HTML files to all users.

== Changelog ==

= 1.4.2 =
* FIX: Changed the Brevo sync worker's fallback schedule from hourly to every ten minutes to ensure more timely processing if the self-scheduling mechanism fails.

= 1.4.1 =
* FIX: Resolved a critical issue where Brevo sync cron jobs would fail to run due to being loaded only in an admin context.
* FIX: Improved cron job logging and persistence for better debugging.
* FIX: Fixed the admin UI to correctly display the activity log from background processes.
