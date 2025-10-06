# Let's Roll API Documentation

This document outlines the known API endpoints used by the Let's Roll SEO Pages plugin. All endpoints are relative to the base URL: `https://beta.web.lets-roll.app/api/`.

---

### 1. Authentication

*   **Endpoint:** `auth/signin/email`
*   **Method:** `POST`
*   **Purpose:** Authenticates with the API to retrieve an access token.
*   **Request Body:**
    ```json
    {
        "email": "YOUR_API_EMAIL",
        "password": "YOUR_API_PASSWORD"
    }
    ```
*   **Expected Response Structure:**
    *   An object containing a `tokens` object, which in turn has an `access` property.
    *   Example: `{ "tokens": { "access": "Bearer eyJ..." } }`

---

### 2. Spots

*   **Endpoint:** `spots/v2/inBox`
*   **Purpose:** Fetches a list of skate spots within a given geographical bounding box.
*   **Known Parameters:**
    *   `ne`: The North-East corner of the bounding box (e.g., `55.8,12.7`).
    *   `sw`: The South-West corner of the bounding box (e.g., `55.5,12.4`).
    *   `limit`: The maximum number of spots to return (e.g., `1000`).
*   **Expected Response Structure:**
    *   A direct **array** of spot objects.
    *   Each object contains at least an `_id` and a `sessionsCount`.

*   **Endpoint:** `spots/{id}`
*   **Purpose:** Fetches the full details for a single skate spot.
*   **Known Parameters:** None (ID is in the path).
*   **Expected Response Structure:**
    *   An object containing a `spotWithAddress` object, which holds the full spot details, including `name` and `satelliteAttachment`.

*   **Endpoint:** `spots/spot-satellite-attachment/{id}/content`
*   **Purpose:** Fetches the actual image content for a spot's satellite view. This endpoint typically returns a redirect to a final image URL (e.g., on S3).
*   **Known Parameters:**
    *   `width`: The desired image width in pixels.
    *   `quality`: The desired image quality (1-100).

---

### 3. Events

*   **Endpoint:** `roll-session/event/inBox`
*   **Purpose:** Fetches a list of events within a given geographical bounding box.
*   **Known Parameters:**
    *   `ne`: The North-East corner of the bounding box.
    *   `sw`: The South-West corner of the bounding box.
    *   `limit`: The maximum number of events to return.
*   **Expected Response Structure:**
    *   An object containing a `rollEvents` property, which is an **array** of event objects.

*   **Endpoint:** `roll-session/{id}/attachments`
*   **Purpose:** Fetches a list of attachments (images) for a specific event.
*   **Known Parameters:** None (ID is in the path).
*   **Expected Response Structure:**
    *   A direct **array** of attachment objects, each containing an `_id`.

*   **Endpoint:** `roll-session/{session_id}/attachment/{attachment_id}/content`
*   **Purpose:** Fetches the actual image content for an event's attachment. This endpoint typically returns a redirect to a final image URL.
*   **Known Parameters:**
    *   `width`: The desired image width in pixels.
    *   `quality`: The desired image quality (1-100).

---

### 4. Skaters

*   **Endpoint:** `nearby-activities/v2/skaters`
*   **Purpose:** Fetches a list of skaters who have been active near a given point.
*   **Known Parameters:**
    *   `lat`: The latitude of the search center.
    *   `lng`: The longitude of the search center.
    *   `minDistance`: The minimum distance from the center (usually `0`).
    *   `maxAgeInDays`: How recently the skater must have been active (e.g., `90`).
    *   `limit`: The maximum number of skaters to return.
*   **Expected Response Structure:**
    *   A complex object containing two main arrays: `activities` and `userProfiles`.
    *   The `activities` array contains objects with a `userId` and a `distance` (in meters).
    *   The `userProfiles` array contains the de-duplicated profiles for the users in the `activities` list.
    *   **To get geographically accurate results, you must first filter the `activities` by `distance` and then use the `userId`s from the filtered list to select the correct `userProfiles`.**
    *   **Important:** The `userProfiles` objects in this response **do not** contain email addresses.
    *   **Full Response Example:**
        ```json
        {
            "activities": [
                {
                    "_id": "62979660adfdcd546d65a8a9",
                    "activityType": "app-usage",
                    "userId": "619a2fb42262d8c9412453d8",
                    "distance": 2370.44
                }
            ],
            "userProfiles": [
                {
                    "userId": "619a2fb42262d8c9412453d8",
                    "firstName": "Irka from LR team",
                    "skateName": "unicorn",
                    "publicBio": "Hey, im Irka...",
                    "_id": "619a2fe3e3710b09c867c1f4"
                }
            ]
        }
        ```

*   **Endpoint:** `user/profile/{id}`
*   **Purpose:** Fetches the full profile for a single skater, which includes their email address.
*   **Known Parameters:** None (ID is in the path).
*   **Expected Response Structure:**
    *   A full user profile object, including an `email` property.
