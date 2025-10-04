import google.auth
from google.analytics.admin_v1alpha import AnalyticsAdminServiceClient
from google.analytics.data_v1beta import BetaAnalyticsDataClient
from google.analytics.data_v1beta.types import DateRange, Metric, RunReportRequest
import os
import time

def test_analytics_connection():
    """
    Tests the connection to both the Google Analytics Admin and Data APIs using
    the credentials specified in the GOOGLE_APPLICATION_CREDENTIALS env var.
    """
    try:
        # --- SHARED AUTHENTICATION ---
        print("--- Step 1: Checking for credentials environment variable...")
        creds_path = os.getenv("GOOGLE_APPLICATION_CREDENTIALS")
        if not creds_path:
            raise ValueError("ERROR: GOOGLE_APPLICATION_CREDENTIALS environment variable is not set.")
        print(f"--- Step 1 SUCCESS: Found credentials path: {creds_path}")

        print("\n--- Step 2: Attempting to load credentials from file...")
        credentials, project_id = google.auth.load_credentials_from_file(
            creds_path,
            scopes=["https://www.googleapis.com/auth/analytics.readonly"]
        )
        project_id = os.getenv("GOOGLE_PROJECT_ID") or project_id
        print(f"--- Step 2 SUCCESS: Loaded credentials for project: {project_id or 'Not specified'}")


        # --- ADMIN API TEST ---
        print("\n--- Step 3: Creating Google Analytics Admin client...")
        admin_client = AnalyticsAdminServiceClient(credentials=credentials)
        print("--- Step 3 SUCCESS: Admin client created.")

        print("\n--- Step 4: Fetching account summaries (Admin API Test)...")
        summaries = admin_client.list_account_summaries()
        account_list = list(summaries)

        if not account_list:
            print("\nNo accounts found for the authenticated user. Cannot proceed to Data API test.")
            return

        print("--- Step 4 SUCCESS: Admin API call successful. Accounts found.")

        first_property_id = None
        for account_summary in account_list:
            if not first_property_id and account_summary.property_summaries:
                first_property_id = account_summary.property_summaries[0].property.split('/')[-1]
                print(f"    (Found property '{first_property_id}' to use for Data API test)")
                break

        if not first_property_id:
            print("\nNo properties found in any account. Cannot proceed to Data API test.")
            return

        # --- DATA API TEST ---
        print("\n--- Step 5: Creating Google Analytics Data client...")
        data_client = BetaAnalyticsDataClient(credentials=credentials)
        print("--- Step 5 SUCCESS: Data client created.")

        print(f"\n--- Step 6: Running a simple report on property '{first_property_id}' (Data API Test)...")
        request = RunReportRequest(
            property=f"properties/{first_property_id}",
            date_ranges=[DateRange(start_date="7daysAgo", end_date="today")],
            metrics=[Metric(name="activeUsers")],
        )
        report_response = data_client.run_report(request)
        print("--- Step 6 SUCCESS: Data API call successful.")

        print("\n\n--- FINAL RESULT: SUCCESS! ---")
        print("Both Admin and Data APIs are working correctly.")
        print("\nSample Report Response:")
        print(report_response)

    except Exception as e:
        print(f"\n\n--- FINAL RESULT: AN ERROR OCCURRED ---")
        print(f"Error Type: {type(e).__name__}")
        print(f"Error Details: {e}")

if __name__ == "__main__":
    test_analytics_connection()
    # Keep the script alive so Gemini doesn't see it as "disconnected"
    print("\n--- Test complete. This process will exit in 5 minutes. ---")
    time.sleep(300)

