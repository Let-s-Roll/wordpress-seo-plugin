# Content Automation Architecture

This document outlines the three-layer architecture for the automated discovery, generation, and distribution of local content for the Let's Roll SEO Pages plugin.

## Core Strategy

The system is designed to be modular, scalable, and future-proof. It decouples the act of discovering new content from the act of presenting it, allowing for maximum flexibility in how content is used (e.g., for SEO, emails, or future features like push notifications).

---

## The 3-Layer Architecture

### Layer 1: The Atomic Content Ledger (The "Source of Truth")

This is the foundational layer. Its sole purpose is to be a comprehensive, permanent, internal-facing log of every single new piece of content discovered from the Let's Roll API.

*   **Technology:** A custom WordPress database table named `wp_lr_discovered_content`.
*   **Structure:**
    *   `id` (Primary Key)
    *   `content_type` (Enum: 'spot', 'event', 'review')
    *   `api_id` (The unique ID from the Let's Roll API)
    *   `city_slug` (e.g., 'berlin')
    *   `discovered_at` (Timestamp)
    *   `data_cache` (A JSON blob containing the raw API data for the item)
*   **Function:** A daily background job ("Content Discovery") scans the Let's Roll API. It uses a combination of endpoints to ensure both accuracy and efficiency:
    *   **Spots:** Fetches from `spots/v2/inBox` and then filters by a precise circular radius.
    *   **Events:** Fetches a primary list from `roll-session/event/inBox` and supplements it with "orphan" events (those without a `spotId`) from the `local-feed` to ensure comprehensive coverage.
    *   **Sessions:** Fetches efficiently from the `local-feed` endpoint, ignoring any items of type 'Event'.
    *   **Reviews:** Fetches all spots and then queries the `ratings-opinions` endpoint for each one.
    *   **Skaters:** Fetches from `nearby-activities/v2/skaters` and compares against the `wp_lr_seen_skaters` table to find new skaters in a city.
    *   For every single new item it finds, it creates one new row in this table.

---

### Layer 2: The AI-Powered Public Content Feed (The "SEO Engine")

This is the public-facing, SEO-optimized layer that is generated *from* the data in Layer 1.

*   **Technology:** A WordPress Custom Post Type named **"City Update"**.
*   **Function:** After the discovery job populates Layer 1, a second background process ("Aggregate & Publish") runs. For each city that has new content in the ledger for that day, it performs the following:
    1.  It queries Layer 1 to get all new items for that city from the last 24 hours.
    2.  It bundles this data into a structured JSON payload.
    3.  It sends this JSON to an AI API with a detailed prompt (see below) to generate a complete, engaging blog post in HTML format.
    4.  It takes the HTML response from the AI and creates a **single new "City Update" post** for that day, using the AI-generated content as the `post_content`.
    5.  **Fallback:** If the AI API call fails, the system generates a simple, structured list of the new items as a fallback to ensure an update is always posted.

The public-facing "Updates" page for a city (e.g., `/berlin/updates/`) will be the archive page for this Custom Post Type, creating a blog-style feed of daily updates.

---

### Layer 3: The Action & Engagement Layer (The "Distribution Engine")

This is the layer that takes the content generated in Layer 2 and distributes it to users.

*   **Technology:** Brevo API integration and WordPress email scheduling.
*   **Function:** This process is now greatly simplified. To send a weekly email digest, for example, it will:
    1.  Find the "City Update" posts from the last 7 days for a specific city.
    2.  Combine their content into a single HTML email body.
    3.  Use a Brevo template to send this content to the appropriate city-based contact list.

---

## The AI Prompt for Post Generation

This is the core of the content generation in Layer 2.

> **SYSTEM:** You are an expert SEO content writer and a passionate roller skater. Your tone is enthusiastic, authentic, and helpful. You are writing a blog post for the Let's Roll community website.
>
> **USER:** Based on the following JSON data for a specific city, write a complete blog post in HTML format.
>
> **Instructions:**
> 1.  **Generate a Title:** Create an exciting `<h1>` title for the post.
>     *   If there is only one new item (e.g., one spot), make the title about that specific item (e.g., "New Skate Spot Discovered in Berlin: The Rail Yard!").
>     *   If there are multiple items, create a summary title (e.g., "Berlin Skate Update: A New Event and Fresh Reviews!").
> 2.  **Write an Introduction:** Write a short, engaging introductory paragraph about the latest happenings in the city's skate scene.
> 3.  **Generate the Body Content (HTML):**
>     *   If there is **only one item**, make it the "hero." Write at least two detailed paragraphs about it. Include all relevant details from the JSON and add some enthusiastic commentary.
>     *   If there are **multiple items**, create a "digest" format. For each item, create a `<h2>` subheading and write a short, punchy paragraph about it.
>     *   **Always include links.** Use `<a>` tags to link to the full spot or event pages (use placeholder URLs like `[spot_url]` for now).
> 4.  **Write a Conclusion:** End with a friendly call-to-action, encouraging readers to check out the spots in the Let's Roll app.
>
> **JSON Data:**
> ```json
> {
>   "city": "Berlin",
>   "new_spots": [
>     { "name": "The Rail Yard", "description": "An old industrial area with perfect flat ground and some DIY ledges.", "url": "[spot_url_1]" }
>   ],
>   "new_events": [],
>   "new_reviews": [
>     { "spot_name": "Tempelhof Field", "rating": 5, "comment": "So much space, perfect for practicing new moves!", "url": "[spot_url_2]" }
>   ]
> }
> ```
