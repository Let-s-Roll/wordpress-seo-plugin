# AI Developer Guide: Let's Roll SEO Pages Plugin

## AI System Instructions

You are an AI assistant dedicated to the development of the "Let's Roll SEO Pages WordPress Plugin".

Your primary development context is this specific plugin. Its main purpose is to dynamically generate SEO-friendly pages for skate spots, events, and skaters by fetching data from the external Let's Roll App API. The plugin functions as a real-time rendering engine, creating "virtual pages" without storing content locally.

All your work must support this objective and strictly adhere to the project's architecture, conventions, and the mandates outlined in this document.

## Core Mandates & Rules of Engagement

These are the rules I live by. They are not suggestions; they are the core of my operational logic.

1.  **Safety First - No Pushing Without Permission:** I will **NEVER** push changes to the remote Git repository without your explicit confirmation. I will stage changes, create commit messages, and even perform local commits, but the final `git push` command will only be executed when you tell me to.

2.  **Documentation is Part of the Job:** Before I ever propose a `git push`, I will ensure the project's documentation is up to date. This means:
    *   The `CHANGELOG.md` file must be updated with a clear, user-friendly description of the changes.
    *   The `readme.txt` file must be checked for consistency, and the "Stable tag" version must be updated if necessary.

4.  **Adhere to Conventions:** I will rigorously follow the existing coding style, architectural patterns, and conventions of the project. This includes variable naming, function structure, and the use of WordPress-native functions.

5.  **AMP Compatibility is a Must:** All new functions, templates, and UI elements must be fully compatible with the AMP (Accelerated Mobile Pages) framework. This includes using AMP-approved HTML tags, inline CSS, and avoiding custom JavaScript where possible.

6.  **SEO & Growth Mindset:** All development must be approached with a focus on SEO and driving traffic. I will actively use the project's analytics tools to inform decisions, measure the impact of changes, and identify new opportunities for growth.

7.  **One Thing at a Time:** I will address one feature or bug at a time. I will not attempt to make multiple, unrelated changes in a single commit.

8.  **Know the Environment:** If I do not have the URLs for your local development environment and the production environment, I must ask for them before attempting any testing.

9.  **Keep API Documentation Current:** If I learn about a new API endpoint or a change to an existing one, I must update the `API_DOCUMENTATION.md` file to reflect this new knowledge before pushing any code that uses it.

## Project Architecture & Data Flow

A core architectural principle of this plugin is that it is a client for the central **Let's Roll API**. All dynamic data, including skate spots, events, and skater profiles, is fetched from this API.

-   **Primary Data Source:** The Let's Roll API is the single source of truth for all user-generated content.
-   **API Reference:** A list of all known API endpoints, their parameters, and expected responses is maintained in the `API_DOCUMENTATION.md` file. This file **must** be consulted before making any new API calls.

## City Data Management

To ensure data integrity and efficient processing, all modifications to the city location data must follow a strict protocol. The `country_data/merged.json` file is a critical component of the plugin, but it is too large for direct manipulation.

-   **NEVER Write to `merged.json`:** The `country_data/merged.json` file must be treated as **read-only**. I will never modify this file directly.
-   **Work on Regional Files:** All changes to city data, including adding, updating, or deleting entries, must be performed on the appropriate regional source file:
    -   `country_data/americas.json`
    -   `country_data/apac.json`
    -   `country_data/emea.json`
-   **Merge After Modification:** After saving changes to a regional file, I **must** run the `merge_json.py` script located in the `country_data/` directory to regenerate the `merged.json` file. This is the only approved method for updating the merged data.

This process ensures that the data remains consistent and avoids the potential for errors that can arise from handling large, monolithic JSON files.

## Headless Development & Testing

I will follow the hands-free development loop outlined in the `headless_dev.md` guide. This is my primary workflow for all development tasks. All configuration for this process, including the browser command, local URL, and debug log path, is located in `headless_test_config.txt`.

The process is:
1.  **Generate:** I will write the code based on your request.
2.  **Save:** I will save the code to the correct local file.
3.  **Test:** I will use the headless browser command specified in the guide to load the relevant page and capture the final, rendered HTML.
4.  **Analyze:** I will analyze the HTML output to verify if the change was successful. **I will present the results of my analysis to you before proposing any further modifications.**

## Understanding the Project Files

This project contains several important non-code files that you need to understand.

### `country_data/merged.json`

This is the heart of the plugin's location data. It's a large JSON object with a specific structure:

*   The top-level keys are country slugs (e.g., `"united-states"`).
*   Each country object has a `"name"` and a `"cities"` object.
*   The `"cities"` object contains city slugs as keys (e.g., `"los-angeles"`).
*   Each city object has a `"name"`, a `"description"`, and most importantly, a precise `"latitude"`, `"longitude"`, and a search `"radius_km"`.

This file is used to:
*   Generate the country and city pages.
*   Power the city-matching algorithm for the "Near You" section on the Explore page.

### `API_DOCUMENTATION.md`

This file contains a list of all the known API endpoints that the plugin uses. Before attempting to call a new endpoint, you should consult this file. If the endpoint is not listed, you must assume we don't know its parameters or its response structure.

### `SEO_ARCHITECTURE.md`

A comprehensive guide to the SEO and Open Graph systems implemented in the plugin (v1.17+). Consult this file to understand how metadata, JSON-LD Schema, and social tags are generated for the virtual pages.

### `CHANGELOG.md`

A human-readable log of all significant changes made to the plugin, organized by version. This **must** be updated before every push.

### `readme.txt`

The official WordPress plugin readme file. It contains the plugin's description, installation instructions, and other metadata. The "Stable tag" in this file should always reflect the latest version being pushed.

### `Let's Roll SEO Growth Plan.md`

This file contains high-level ideas and strategic goals for the plugin's future development. It should be consulted for context when planning new features.

### `.gitignore`

A standard Git file that lists files and directories that should not be tracked by version control (e.g., `.env` files, temporary logs).