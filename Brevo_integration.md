# Brevo Integration Guide

This document details the functionality and implementation of the Brevo API integration for the Let's Roll SEO Pages plugin. The integration follows a non-destructive, list-based approach to managing skater location data.

The process is broken down into three main pillars:

1.  **Contact Lookup:** Finding a unique contact in Brevo.
2.  **City List Sync:** Ensuring a Brevo contact list exists for every city.
3.  **Main Sync Process:** Adding contacts to their corresponding city list.

---

## 1. Core Logic: Dual-Attribute Contact Lookup

To maximize the chances of finding a contact, the plugin uses a dual-lookup function, `lr_find_brevo_contact($skateName)`.

**Method:**
It performs two separate `GET` requests to the `https://api.brevo.com/v3/contacts` endpoint in sequence.

1.  **Primary Search (FIRSTNAME):** It first attempts to find a unique contact where the standard `FIRSTNAME` attribute exactly matches the skater's `skateName`.
    *   **Example URL:** `.../contacts?filter=equals(FIRSTNAME,"rollerskatingcharlie")`

2.  **Fallback Search (SKATENAME):** If, and only if, the first search does not return a single, unique contact, it automatically performs a second search. This time, it looks for a match against the custom `SKATENAME` attribute.
    *   **Example URL:** `.../contacts?filter=equals(SKATENAME,"rollerskatingcharlie")`

The function only returns a contact object if one of these searches finds **exactly one** match. If it finds zero, or more than one, it returns `null` to prevent ambiguity.

---

## 2. City List Management

Before the main sync can run, a corresponding contact list must exist in Brevo for each city defined in the plugin.

### `lr_brevo_ajax_sync_city_lists()`

This function, triggered by the "Sync City Lists" button, orchestrates the entire process:

1.  **Fetch All Brevo Lists:** It first makes paginated `GET` requests to `.../contacts/lists` to retrieve a complete list of all existing contact lists within the designated folder (e.g., folder ID 31).
2.  **Compare to Plugin Cities:** It compares this list against the cities defined in the plugin's settings.
3.  **Create Missing Lists:** If a list for a city (e.g., "Berlin") does not exist, it makes a `POST` request to `.../contacts/lists` to create it inside the correct folder.
4.  **Store Mappings:** It saves an array to the WordPress database (`wp_options` table) that maps each city name to its corresponding Brevo list ID (e.g., `['Berlin' => 123, 'Paris' => 124]`).

This process ensures that the main sync always has a valid list ID to work with.

---

## 3. Main Sync Process: Adding Contacts to Lists

The main sync, executed by `lr_add_skater_to_brevo_city_list($skateName, $city_name)`, is a non-destructive operation.

1.  **Find Contact:** It uses the dual-lookup function `lr_find_brevo_contact()` to find the correct Brevo contact for the given skater.
2.  **Get List ID:** It retrieves the stored city-to-list-ID mappings.
3.  **Add to List:** If a contact and a list ID are found, it makes a `POST` request to `.../contacts/lists/{listId}/contacts/add` with the contact's email in the payload.

This adds the contact to the correct city list without overwriting any existing contact attributes. The plugin then logs the skater as "processed" to prevent redundant syncs until the defined re-sync period has passed.
