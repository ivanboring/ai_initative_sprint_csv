# AI Initiative Sprint CSV

This project contains a PHP script to fetch and generate CSV reports on the current status of issues tagged for the AI Initiative Sprint on Drupal.org.

## Install
1. Clone this repository.
2. Run `composer install` to install dependencies.
3. Install chromium or chrome on your system, this is required for the headless browser to work. On Ubuntu you can run:
   ```bash
   sudo apt install chromium-browser
   ```

## Usage

Run the script from the command line, providing the sprint start date and optionally the taxonomy ID for the AI Initiative Sprint tag.

```bash
php get_current_status.php <sprint_start_date> [taxonomy_id]
```

Note that taxonomy id is optional and already defaults to the AI Initiative Sprint tag ID.

Example if the sprint starts on January 1, 2025:

```bash
php get_current_status.php 2026-01-26 2026-01-27
```

## Output

The output CSV files will be generated in the `status_files` directory with filenames indicating the date and time of generation.
