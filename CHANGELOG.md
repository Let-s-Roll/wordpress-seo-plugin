# Changelog

= 1.17.22 =
*   **Enhancement: Restore Emoji Descriptions:** Reverted the decision to use plain bullet points for social descriptions. We have restored high-visibility emojis (📅, 📍, ⭐, 🛼) to maximize visual appeal and engagement on platforms that support them (Discord, Slack, Twitter), accepting that some stricter platforms (Facebook) may strip them.

= 1.17.21 =
*   **Fix: Social Media Compatibility:** Replaced emojis in Open Graph descriptions with standard bullet separators (`•`). This resolves an issue where strict parsers (like Facebook's) would strip the entire description due to emoji encoding, ensuring consistent previews across all platforms.

= 1.17.20 =
*   **Enhancement: Emoji-Powered Social Snippets:** Added high-visibility emojis to Open Graph and SEO descriptions. This visually separates key information (Date, Location, Rating) in social media previews where standard line breaks are typically stripped by platforms like Facebook.

= 1.17.19 =
*   **Enhancement: Richer Social Snippets:** Improved the logic for generating `og:description` for Skate Spots and Events.
    *   **Events:** Now includes date, time, venue address, and a description excerpt.
    *   **Spots:** Now includes address, star rating, and community review stats.

= 1.17.18 =
*   **Fix: Enable Country Page Schema:** Resolved a logic error where the new `Country` schema markup was not being triggered for Country pages. The switch statement now correctly includes the `country` case, ensuring the enhanced JSON-LD is output to the page header.

= 1.17.17 =
*   **Fix: SEO Module Stability:** Resolved a critical "Undefined Function" error caused by a race condition in the SEO metadata file loading.
*   **Refactor:** completely reorganized `includes/seo-metadata.php` to ensure robust function definition before execution.
*   **Optimization:** Temporarily disabled aggressive meta tag stripping to ensure maximum compatibility across different server environments.

= 1.17.16 =
*   **Enhancement: Country Page Schema:** Upgraded Country pages to use the specific `Country` Schema type. They now list all available cities using the `containsPlace` property, creating a clear semantic hierarchy (Country > City > Spot) for search engines.

= 1.17.15 =
*   **Enhancement: Semantic City Schema:** Refined the JSON-LD Schema for City Pages to clearly distinguish them from simple lists.
    *   **Type:** City pages are now marked up as `City` (instead of `CollectionPage`) to establish them as the authoritative entity for that location.
    *   **Geo-Coordinates:** Added latitude and longitude to the City schema to boost local SEO signals.
    *   **Contained Places:** Top skate spots are now nested under the `containsPlace` property.
*   **Enhancement:** List pages (e.g., `/skatespots/`, `/skaters/`) retain the `CollectionPage` + `ItemList` schema to target "Top 10" list rankings.
*   **Fix:** Resolved missing `ItemList` schema for the `/skaters/` list page.

= 1.17.14 =
*   **Feature: Standard SEO Meta Tags:** Expanded the SEO engine to generate standard HTML `<title>`, `<meta name="description">`, and `<link rel="canonical">` tags for all virtual pages.
    *   **Optimized Titles:** Browser tabs now display rich, descriptive titles (e.g., "Skate Spot: [Name] | Let's Roll") instead of generic page names.
    *   **Canonical URLs:** Self-referencing canonical tags prevent duplicate content issues caused by tracking parameters.
    *   **Meta Descriptions:** Context-aware descriptions improve click-through rates from search results.
*   **Refactor:** Renamed `includes/open-graph.php` to `includes/seo-metadata.php` to reflect its expanded role in managing all SEO and social metadata.

= 1.17.13 =
*   **Feature: JSON-LD Structured Data:** Implemented comprehensive Schema.org markup to boost SEO and enable Rich Snippets in Google search results.
    *   **Skate Spots:** Marked up as `SportsActivityLocation` with ratings, address, and geo-coordinates.
    *   **Events:** Marked up as `Event` with dates, location, and organizer info.
    *   **Skaters:** Marked up as `Person` with profile images and social links.
    *   **Breadcrumbs:** Added global `BreadcrumbList` schema for all pages to improve site structure understanding.
    *   **Featured Lists:** City and List pages now include `ItemList` schema, featuring the top ranked spots and upcoming events. This specifically targets "Top 10" style featured snippets in search results.
    *   **Collections:** Overview pages are defined as `CollectionPage`.

= 1.17.12 =
*   **Feature: Open Graph for City Updates:** Added dedicated Open Graph support for City Update archive pages and single update posts. Single updates now correctly feature their AI-generated titles, summaries, and featured images when shared on social media.

= 1.17.11 =
*   **Fix: Comprehensive Open Graph Support:** Restored and expanded dynamic Open Graph (OG) tag generation to cover all key pages, including Explore, Country, City, and Item List pages (Spots, Events, Skaters), in addition to Detail and Activity pages.
*   **Enhancement:** Improved social media sharing by providing page-specific titles, descriptions, and a consistent branding fallback using the plugin's icon.
*   **Refactor:** Centralized Open Graph logic in `includes/open-graph.php` for easier maintenance.

= 1.17.10 =
*   **Feature: General Newsletter Creator:** Introduced a new tool to send a general newsletter (Blog Post only) to a specific Brevo list ID. This is ideal for reaching contacts who are not yet geo-located to a specific city.
*   **Enhancement: Shared Component Architecture:** The general newsletter leverages existing email partials, repurposing the blog image as the hero image for a consistent branding experience.
*   **Fix: General Layout Refinements:** Cleaned up the general newsletter layout by removing redundant city-specific headings and fixing placeholder replacements.

= 1.17.9 =
*   **Enhancement: Exponential Backoff for Rate Limiting:** Improved the bulk campaign sender's retry logic by implementing exponential backoff. Each consecutive rate-limit hit for a specific item now results in a progressively longer wait time (1.5x multiplier), providing a more effective "cooling off" period for the Brevo API.

= 1.17.8 =
*   **Tweak: Flexible Bulk Content Window:** Relaxed the bulk campaign selection filter to include any city update published within the last 45 days. This ensures that updates generated at the very end of a previous month (for the current month's distribution) are correctly included.

= 1.17.7 =
*   **Fix: Smart Bulk Content Selection:** Updated the bulk campaign generator to correctly select the most chronologically recent update for each city using `publish_date`.
*   **Enhancement: Month-Aware Filtering:** Added a strict filter to the bulk process, ensuring it only targets updates published in the current calendar month to prevent sending outdated content.

= 1.17.6 =
*   **Enhancement: Robust Rate-Limit Handling:** The bulk campaign processor now intelligently handles HTTP 429 "Too Many Requests" errors. It automatically pauses execution, respects the "Retry-After" header, and displays a countdown timer before automatically resuming, ensuring all city campaigns are created successfully.

= 1.17.5 =
*   **Feature: Bulk Campaign Creator:** Added a new "Bulk Campaign Creator" section to the Brevo Sender page. This allows administrators to automatically generate email campaigns for every city that has a published update, featuring a selected blog post.
*   **Enhancement: Queue-based Bulk Processing:** Implemented a robust, asynchronous processing loop for bulk campaign generation to prevent server timeouts and provide real-time progress updates.

= 1.17.4 =
*   **Fix: Conditional Image Display in Emails:** Implemented logic to automatically hide image placeholders in email campaigns if a featured image is missing for either the City Update or the Blog Post. This ensures a cleaner layout without broken icons or empty spaces.

= 1.17.3 =
*   **Enhancement: Personalized Email Greetings:** Added "Hey {{ contact.FIRSTNAME }}," to the campaign header for a more personal touch.
*   **Tweak: Removed App Download CTA:** Deleted the "Download our free app" button from the campaign footer, as recipients are already active app users.

= 1.17.2 =
*   **Enhancement: Refined Brevo Email Templates:** Polished the email template partials (`header.php`, `section_city_update.php`, etc.) to match a cleaner, professional HTML structure.
*   **Enhancement: Dynamic Featured Images in Email:** The campaign header now dynamically pulls the featured image from the selected City Update or Blog Post.
*   **Fix: Functioning Campaign Links:** Resolved an issue where "Read More" links in Brevo campaigns were missing their destination URLs.
*   **Cleanup:** Integrated the `standard_template.html` as a reference for future template refinements.

= 1.17.1 =
*   **Refactor: Email Template Partials:** Refactored the Brevo email campaign template into smaller, more manageable partial files (`header.php`, `section_city_update.php`, `section_blog_post.php`, `footer.php`) located in `email-templates/`. This significantly improves maintainability and flexibility for email content.
*   **Fix: Malformed CSS @import in Email Template:** Corrected a malformed CSS `@import` rule in the email template, resolving an issue where extraneous text was displayed at the top of generated email campaigns.
*   **Enhancement: 'Read More' Links in Email Campaigns:** Added dynamic "Read more..." links to the end of content sections within generated email campaigns, leading to the full City Update and Blog Post pages.
*   **Documentation: Brevo Campaign Sender:** Added comprehensive docblocks to `brevo-integration.php` and `admin/brevo-sender-page.php` to clearly explain the new Brevo campaign sender functionality, its parameters, and AJAX interactions.

= 1.17.0 =
*   **Feature: Brevo Campaign Sender (Manual):** Introduced a new "Brevo Sender" admin page, allowing administrators to manually create and send email campaigns. Campaigns can combine a City Update post with a standard WordPress blog post, targeting city-specific Brevo lists.
*   **Enhancement: Brevo Campaign Draft Mode:** Added an option to the "Brevo Sender" to create campaigns as drafts in Brevo, enabling review and manual sending from the Brevo dashboard.
*   **Fix: Brevo Sender API Sender Configuration:** Corrected the Brevo API call for creating campaigns to use the verified sender "Let's Roll Team <hey@lets-roll.app>", resolving "Sender is invalid / inactive" errors.
*   **Enhancement: Brevo Settings (Sender ID Field):** Added a "Brevo Sender ID" field to the "Brevo Sync" settings page, though the campaign sender now uses email/name directly as per Brevo API documentation.
*   **Enhancement: Admin Menu Reorganization:** Reordered the main "Let's Roll SEO" admin menu items to: "SEO Settings" (parent), "Content Discovery" (under SEO Settings), "Brevo Sync", "Brevo Sender", "Import/Export", "Data Tools".
*   **Refactor: Improved Brevo API Logging:** Enhanced logging within `lr_create_and_send_brevo_campaign` to include full raw API responses for better debugging.

= 1.16.0 =
*   **Feature: Configurable Logging:** Added new options to the "Development & Testing" settings page to provide granular control over logging. Administrators can now enable or disable the generation of the `link_verification.csv` file and choose whether to clear the `content_discovery.log` before each run, reducing log noise on production sites while preserving debugging capabilities.
*   **Cosmetic: Plugin Name Update:** Changed the plugin's display name in the WordPress admin UI from "Let's Roll SEO Pages" to "Let's Roll SEO" for brevity.
*   **Cosmetic: Admin Menu Title Update:** Changed the main admin menu title in the dashboard sidebar to "Let's Roll SEO" to match the new plugin name.

= 1.15.0 =
*   **Refactor: Consistent Image Proxy URL Generation:** Introduced the `LR_PLUGIN_FILE` constant and updated image proxy URL generation in `lets-roll-seo-pages.php`, `includes/rendering-functions.php`, and `includes/content-publication.php` to use `plugins_url()`. This ensures that `image-proxy.php` URLs are consistently resolved based on the plugin's actual installed directory name, fixing "file not found" errors when the plugin folder name changes between local and live environments.
*   **Fix: Data Tools Page Enhanced for Image URLs:** The "Data Tools" admin page's search and replace utility now correctly updates the `featured_image_url` column in `wp_lr_city_updates`, resolving hardcoded local URLs for featured images.
*   **Fix: Relative Image URLs in Content Publication:** Modified `includes/content-publication.php` to use `wp_make_link_relative()` when storing image proxy URLs. This ensures newly generated city update posts will store relative paths, preventing future hardcoding of absolute domains.
*   **Feature: Data Tools Admin Page:** Introduced a new "Data Tools" admin page with a search and replace utility. This tool allows administrators to perform bulk search and replace operations on the plugin's custom database tables, specifically targeting `post_content` and `post_summary` in `wp_lr_city_updates` to correct hardcoded URLs or other text.

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
*   **Feature:** Skip Existing Posts during Historical Seeding. The historical seeder now checks for existing posts (based on a deterministic slug) before generating new AI content, significantly improving efficiency on re-runs and preventing redundant AI calls.
*   **Feature:** City Filter for Generated Posts. Added a dropdown filter to the "Generated City Update Posts" section on the admin page, allowing users to easily view posts for a specific city.
*   **Fix:** Prevented Duplicate Posts. Resolved an issue where `wpdb->replace()` was creating duplicate posts due to non-deterministic post slugs. The `post_slug` is now consistently generated from the city slug and time bucket, ensuring proper overwriting.
*   **Fix:** Improved Seeder Stability & Crash Recovery. Implemented several enhancements to prevent crashes and improve recovery:
    *   Reduced `LR_SEEDING_BATCH_SIZE` to 1 to prevent memory accumulation and crashes across multiple city processes.
    *   Increased PHP memory limit to `512M` in `lets-roll-seo-pages.php` to handle larger data payloads during AI content generation.
    *   Added a "heartbeat" mechanism (`lr_seeding_current_city`) for granular crash detection, allowing the admin page to display the exact city where a crash occurred.
    *   Updated the "Force Unlock & Reset" button to clear the new heartbeat option.
*   **Under the Hood:** Removed temporary diagnostic logging from `includes/ai-content.php` that was used for crash analysis.

= 1.11.1 =
*   **Fix:** Prevented Premature Post Generation by Historical Seeder. Corrected a bug in the historical seeder (`lr_run_historical_seeding_for_city`) that caused it to generate posts for the current, incomplete month. The seeder now includes a completeness check, ensuring posts are only created for past time periods, aligning its behavior with the live content publication cron job.

= 1.11.0 =
*   **Major Feature:** "AI-First" Link Adjudication. The link verification system has been completely redesigned to a more robust "AI-First" model. The system now presumes all links are good unless they are demonstrably broken (e.g., 404, 500, cURL error). For all other links, the system now relies exclusively on an AI adjudicator to determine the content's relevance. This AI-driven, contextual approach replaces the previous, brittle keyword-based filtering, preventing false positives where good links were discarded due to common words like "advertisement" or "no results found".
*   **Fix:** Resolved 403 Forbidden Errors. Fixed a critical issue where valid links were failing the quality check with a 403 error. By adding a standard browser `User-Agent` to all server-side requests, the system can now bypass simple anti-bot measures and correctly fetch content from sites like `dothebay.com`.
*   **Fix:** Corrected AI Content Erasure Bug. Fixed a major regression where AI-generated text was being erased during the link verification process. Correctly initialized the variable holding the text to ensure content is preserved, especially for snippets that contain no links.

= 1.12.0 =
*   **Feature:** "Seed All Cities (Historical)" functionality. Adds a new admin interface and a background batch processing system to generate historical content summary posts for every city in the database. This allows for the rapid creation of a baseline of content across the entire site.

= 1.10.0 =
*   **Feature:** Intelligent Link Verification Cascade. Implemented a robust, multi-step cascade to verify and correct external links in AI-generated content. The system uses an intelligent liveness check, a "Refresh Search" for dead links, and an AI-powered "Broad Search" to evaluate and select the most contextually relevant replacement link, significantly improving link quality and reliability.
*   **Under the Hood:** Comprehensive Verification Logging. The entire link verification cascade is now logged in detail to `link_verification.csv`, providing full transparency into the decision-making process for debugging and analysis.

## 1.9.0 - 2025-11-13

### ✨ Features & Enhancements

*   **High-Quality External Links via Google Custom Search API:**
    *   Implemented a new link verification system that uses the Google Custom Search API to replace low-quality, AI-generated URLs (e.g., from aggregator sites) with authoritative, primary sources.
    *   Added two new WordPress options, `google_search_api_key` and `google_search_engine_id`, to the "Let's Roll SEO Settings" page to power this feature.
    *   The system intelligently parses Markdown links from all AI-generated text snippets, queries the Google API for a better URL, and replaces it before the post is saved.

### 🐛 Bug Fixes

*   **Fixed AI Content Rendering:** Resolved a critical bug where AI-generated content (summaries and section intros) was not appearing on the front end. This was traced to two issues:
    1.  **Incorrect HTML Sanitization:** Replaced `esc_html()` with `wp_kses_post()` for AI-generated content to ensure that safe HTML tags (like `<a href="...">`) are preserved while still protecting against malicious code.
    2.  **Pass-by-Reference Error:** Corrected a pass-by-reference logic error in the link verification loop. The modified text with corrected links is now explicitly re-assigned back to the main content array, ensuring the changes are not lost before rendering.
*   **Resolved Fatal Error on Function Redeclaration:** Fixed a fatal error ("Cannot redeclare lr_convert_markdown_links_to_html()") by removing a duplicate function declaration that was incorrectly added to `includes/content-publication.php`.

### 🛠 Under the Hood

*   **Unified Post Generation Logic:** Refactored the historical seeder (`lr_run_historical_seeding_for_city`) to use the main `lr_generate_city_update_post` function. This removes duplicate code and ensures that all posts, whether generated historically or by the live cron, use the same consistent logic, including the new link verification step.
*   **Improved Diagnostic Logging:** Added extensive, detailed logging throughout the content generation and link verification process to provide clear visibility for future debugging.

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