# Let's Roll SEO Architecture

This document outlines the SEO and Open Graph architecture implemented in the plugin as of version 1.17.22. The system is designed to provide comprehensive, semantic metadata for all dynamically generated "virtual pages."

## Core Components

The SEO logic is split into two primary files located in the `includes/` directory:

1.  **`includes/seo-metadata.php`**: Handles standard HTML tags (`<title>`, `<meta description>`, `<link canonical>`) and Open Graph (OG) tags for social media.
2.  **`includes/schema-markup.php`**: Handles JSON-LD Structured Data (Schema.org) for Rich Snippets.

Both files hook into `wp_head` (or `pre_get_document_title`) to inject code into the page header.

## 1. Page Detection & Data Fetching

The system uses a centralized helper function `lr_get_page_details_from_uri()` (in `seo-metadata.php`) to identify the current virtual page type based on the URL.

Supported Types:
*   **Explore:** `/explore/`
*   **Country:** `/{country-slug}/`
*   **City:** `/{country-slug}/{city-slug}/`
*   **List:** `/{country-slug}/{city-slug}/{type}/` (skatespots, events, skaters)
*   **City Update List:** `/{country-slug}/{city-slug}/updates/`
*   **Single Update:** `/{country-slug}/{city-slug}/updates/{post-slug}/`
*   **Detail (Spot/Event):** `/spots/{id}`, `/events/{id}`
*   **Skater Profile:** `/skaters/{username}`
*   **Activity/Session:** `/activity/{id}`

Data is fetched via `lr_get_current_page_api_data()`, which uses the existing API caching infrastructure.

## 2. Open Graph & Social Sharing (`seo-metadata.php`)

**Goal:** Ensure every link shared on Facebook, Twitter, Discord, etc., looks professional and inviting.

*   **Title:** Optimized format (e.g., "Skate Spot: [Name] | Let's Roll").
*   **Image:**
    *   **Spots:** Satellite image.
    *   **Events/Activities:** Event photo or video thumbnail.
    *   **Skaters:** User avatar.
    *   **General:** Plugin icon fallback.
*   **Description:**
    *   Context-aware summaries.
    *   **Emoji Enhanced:** Uses emojis (`📅`, `📍`, `⭐`, `🛼`) to visually separate data points (Date, Location, Rating) in the description text, as social platforms strip newlines.

## 3. JSON-LD Structured Data (`schema-markup.php`)

**Goal:** Provide Google with semantic understanding for Rich Snippets and Knowledge Graph integration.

| Page Type | Schema Type | Key Properties | SEO Benefit |
| :--- | :--- | :--- | :--- |
| **All Pages** | `BreadcrumbList` | `itemListElement` | Better site structure understanding. |
| **Country** | `Country` | `containsPlace` (List of Cities) | Establishes geographic hierarchy. |
| **City** | `City` | `containsPlace` (Top Spots), `geo` | Local SEO authority. |
| **List (Spots)** | `CollectionPage` | `mainEntity` (`ItemList` of Spots) | Targets "Top 10 Spots" lists/carousels. |
| **List (Events)** | `CollectionPage` | `mainEntity` (`ItemList` of Events) | Targets "Events Near Me" results. |
| **Spot Detail** | `SportsActivityLocation` | `aggregateRating`, `geo`, `address` | Review stars and map pins in SERPs. |
| **Event Detail** | `Event` | `startDate`, `location`, `organizer` | Event pack features in Google. |
| **Skater** | `Person` | `image`, `sameAs` (Socials) | Person knowledge panels. |

## 4. Standard SEO Tags

*   **Canonical URL:** Automatically generated self-referencing canonical tag to prevent duplicate content issues from query parameters.
*   **Meta Description:** Matches the Open Graph description for consistency.
*   **Document Title:** Filters `wp_title` to replace generic WordPress titles with specific, keyword-rich variants.
