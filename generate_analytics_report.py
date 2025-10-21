
import google.auth
from google.analytics.data_v1beta import BetaAnalyticsDataClient
from google.analytics.data_v1beta.types import (
    DateRange,
    Dimension,
    Metric,
    RunReportRequest,
    OrderBy,
)
import os

def generate_analytics_report(property_id="384133949"):
    """
    Generates a Google Analytics report for the specified property ID.
    """
    try:
        # --- AUTHENTICATION ---
        creds_path = os.getenv("GOOGLE_APPLICATION_CREDENTIALS")
        if not creds_path:
            raise ValueError("ERROR: GOOGLE_APPLICATION_CREDENTIALS environment variable is not set.")

        credentials, project_id = google.auth.load_credentials_from_file(
            creds_path,
            scopes=["https://www.googleapis.com/auth/analytics.readonly"]
        )

        # --- DATA CLIENT ---
        data_client = BetaAnalyticsDataClient(credentials=credentials)

        # --- REPORT REQUEST ---
        request = RunReportRequest(
            property=f"properties/{property_id}",
            date_ranges=[DateRange(start_date="30daysAgo", end_date="today")],
            dimensions=[
                Dimension(name="pagePath"),
                Dimension(name="pageTitle"),
            ],
            metrics=[
                Metric(name="activeUsers"),
                Metric(name="newUsers"),
                Metric(name="bounceRate"),
                Metric(name="averageSessionDuration"),
                Metric(name="sessions"),
                Metric(name="screenPageViews"),
            ],
            order_bys=[
                OrderBy(
                    metric=OrderBy.MetricOrderBy(metric_name="screenPageViews"),
                    desc=True
                )
            ],
            limit=50
        )

        # --- RUN REPORT ---
        response = data_client.run_report(request)

        # --- PRINT REPORT ---
        print("--- Google Analytics Report ---")
        print(f"Property ID: {property_id}")
        print(f"Date Range: 30 days ago to today")
        print("-" * 30)

        # Header
        header = [header.name for header in response.dimension_headers] + [header.name for header in response.metric_headers]
        print("{:<60} {:<60} {:<15} {:<15} {:<15} {:<25} {:<15} {:<20}".format(*header))
        print("-" * 220)

        # Rows
        for row in response.rows:
            dimensions = [value.value for value in row.dimension_values]
            metrics = [value.value for value in row.metric_values]
            print("{:<60} {:<60} {:<15} {:<15} {:<15.2%} {:<25} {:<15} {:<20}".format(
                dimensions[0],
                dimensions[1],
                metrics[0],
                metrics[1],
                float(metrics[2]),
                metrics[3],
                metrics[4],
                metrics[5]
            ))

    except Exception as e:
        print(f"An error occurred: {e}")

if __name__ == "__main__":
    generate_analytics_report()
