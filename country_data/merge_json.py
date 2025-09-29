
import json
import os

# Get the absolute path of the directory containing the script
dir_path = os.path.dirname(os.path.realpath(__file__))

# Define file paths
emea_file = os.path.join(dir_path, 'emea.json')
americas_file = os.path.join(dir_path, 'americas.json')
apac_file = os.path.join(dir_path, 'apac.json')
output_file = os.path.join(dir_path, 'merged.json')

# Read and decode the JSON files
try:
    with open(emea_file, 'r') as f:
        emea_data = json.load(f)
    with open(americas_file, 'r') as f:
        americas_data = json.load(f)
    with open(apac_file, 'r') as f:
        apac_data = json.load(f)
except FileNotFoundError as e:
    print(f"Error: {e.filename} not found.")
    exit()
except json.JSONDecodeError as e:
    print(f"Error decoding JSON from a file: {e}")
    exit()

# Merge the data
merged_data = {**emea_data, **americas_data, **apac_data}

# Sort the merged data by country key alphabetically
sorted_merged_data = dict(sorted(merged_data.items()))

# Write the merged data to the output file
try:
    with open(output_file, 'w') as f:
        json.dump(sorted_merged_data, f, indent=2, ensure_ascii=False)
    print("Successfully merged emea.json, americas.json, and apac.json into merged.json.")
except IOError as e:
    print(f"Error writing to {output_file}: {e}")

