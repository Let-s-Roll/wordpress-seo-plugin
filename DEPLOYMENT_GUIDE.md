# Production Deployment Guide: Content Automation

This guide outlines the steps required to deploy the new Content Automation features to your production site, generate the initial historical content, and set the system to run automatically.

## Part 1: Merging and Plugin Activation (Manual Steps)

This part involves merging the code and ensuring the new features are activated correctly on your production site.

1.  **Merge the `feature/content-automation` Branch:** Merge your current working branch into your `main` or `master` branch in your Git environment.

2.  **Automatic Deployment:** Your site will automatically update from Git, pulling all the new and modified files into your production WordPress installation.

3.  **CRUCIAL: Deactivate and Reactivate the Plugin:** This is the most important manual action you must take. It is required to create the new database tables.
    *   Go to your WordPress Admin Dashboard.
    *   Navigate to the "Plugins" page.
    *   Find the "Let's Roll SEO Pages" plugin and click **"Deactivate"**.
    *   Immediately after it deactivates, click **"Activate"**.
    *   This action will trigger the functions that create the new database tables (`wp_lr_discovered_content`, `wp_lr_city_updates`, etc.) and ensure the database schema is up to date.

## Part 2: Kicking Off the Content Generation (One-Time Actions)

This part covers how to discover and publish all historical content for all cities without repetitive button clicking.

1.  **Run a Full, Site-Wide Content Discovery:**
    *   Navigate to the "Content Discovery" admin page within the plugin's settings.
    *   Click the **"Run Full Discovery Now"** button.
    *   This will start a background process that discovers all available content for *every city*. This is a long-running process. You can monitor its progress in the log viewer on the same page. Wait for the log to show the "Full content discovery run finished" message before proceeding.

2.  **Run a Full, Site-Wide Historical Seeding:**
    *   Once the full discovery is complete, the historical posts for all cities must be generated.
    *   To do this in one step, a temporary script will be required. Ask your AI assistant to **"create the one-time script to seed historical posts for all cities."**
    *   The assistant will provide a PHP script. Place this script in the plugin's root directory.
    *   Execute the script by visiting its URL in your browser (e.g., `https://your-site.com/wp-content/plugins/lets-roll-seo-pages/temporary_seeder.php`).
    *   This will generate all historical posts for the last 6 months for every city.
    *   After the process is complete and you've verified the posts, you can delete the temporary script.

## Part 3: Setting Up Automatic Monthly Updates (Final Configuration)

This is the final step to put the system on autopilot.

1.  **Set the Update Frequency:**
    *   Navigate to the main "Let's Roll SEO" admin page.
    *   Find the "Content Automation Settings" section.
    *   In the "Update Frequency" dropdown, select **"Monthly"**.
    *   Click "Save Settings".

2.  **Confirm Cron Jobs are Scheduled:**
    *   On the "Content Discovery" admin page, you can verify that the `lr_content_discovery_cron` and `lr_publication_cron` have future scheduled times listed.

Once these steps are complete, the system will be fully configured to automatically discover new content daily and publish monthly update posts.
