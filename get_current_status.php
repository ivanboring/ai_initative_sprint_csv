<?php
/**
 * Drupal.org Issue Status Collector
 *
 * Collects issues from Drupal.org API based on a sprint start date and taxonomy tag.
 * Creates two CSV files:
 * 1. Issues CSV - Contains issue details with status and timing information
 * 2. Contributors CSV - Contains issue details with contributor usernames
 */

// Configuration
define('API_BASE_URL', 'https://www.drupal.org/api-d7');
define('API_DELAY_SECONDS', 1); // Delay between API calls to not overload server
define('API_PAGE_LIMIT', 50); // Max items per page

// Drupal issue status codes
define('STATUS_LABELS', [
    1 => 'Active',
    2 => 'Fixed',
    3 => 'Closed (duplicate)',
    4 => 'Postponed',
    5 => 'Closed (won\'t fix)',
    6 => 'Closed (works as designed)',
    7 => 'Closed (fixed)',
    8 => 'Needs review',
    13 => 'Needs work',
    14 => 'RTBC',
    15 => 'Patch (to be ported)',
    16 => 'Postponed (maintainer needs more info)',
    18 => 'Closed (outdated)',
]);

// Status codes we want to track timing for
define('STATUS_NEEDS_REVIEW', 8);
define('STATUS_NEEDS_WORK', 13);
define('STATUS_RTBC', 14);
define('STATUS_FIXED', 2);

/**
 * Make an API request with delay
 *
 * @param string $url The URL to fetch
 * @return array|null Decoded JSON response or null on error
 */
function apiRequest($url) {
    static $firstCall = true;

    // Add delay between calls (skip first call)
    if (!$firstCall) {
        echo "  Waiting " . API_DELAY_SECONDS . " second(s) before next API call...\n";
        sleep(API_DELAY_SECONDS);
    }
    $firstCall = false;

    echo "  Fetching: $url\n";

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'Accept: application/json',
                'User-Agent: DrupalSprintStatusCollector/1.0 (Sprint tracking tool)',
            ],
            'timeout' => 30,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        echo "  ERROR: Failed to fetch URL\n";
        return null;
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "  ERROR: Failed to parse JSON - " . json_last_error_msg() . "\n";
        return null;
    }

    return $data;
}

/**
 * Get all issues matching the taxonomy tag, filtering by sprint start date
 *
 * @param string $sprintStartDate Sprint start date (YYYY-MM-DD format)
 * @param int $taxonomyId The taxonomy term ID for the tag
 * @return array List of issue data
 */
function fetchAllIssues($sprintStartDate, $taxonomyId) {
    $sprintStartTimestamp = strtotime($sprintStartDate);

    if ($sprintStartTimestamp === false) {
        echo "ERROR: Invalid sprint start date format. Use YYYY-MM-DD.\n";
        return [];
    }

    echo "Sprint start date: $sprintStartDate (timestamp: $sprintStartTimestamp)\n";
    echo "Fetching issues with taxonomy ID: $taxonomyId\n\n";

    $issues = [];
    $page = 0;
    $hasMorePages = true;

    // Build initial URL - sort by changed DESC to get most recent first
    $baseUrl = API_BASE_URL . "/node.json?type=project_issue&taxonomy_vocabulary_9=$taxonomyId&limit=" . API_PAGE_LIMIT . "&sort=changed&direction=DESC";

    while ($hasMorePages) {
        $url = $baseUrl . "&page=$page";
        $response = apiRequest($url);

        if ($response === null) {
            echo "ERROR: Failed to fetch page $page\n";
            break;
        }

        if (!isset($response['list']) || empty($response['list'])) {
            echo "No more issues found on page $page\n";
            break;
        }

        $pageIssueCount = 0;
        $oldestOnPage = PHP_INT_MAX;

        foreach ($response['list'] as $issue) {
            $changedTimestamp = (int)($issue['changed'] ?? 0);
            $createdTimestamp = (int)($issue['created'] ?? 0);

            // Track oldest item on this page
            if ($changedTimestamp < $oldestOnPage) {
                $oldestOnPage = $changedTimestamp;
            }

            // Include if created OR changed after sprint start
            if ($changedTimestamp >= $sprintStartTimestamp || $createdTimestamp >= $sprintStartTimestamp) {
                $issues[] = $issue;
                $pageIssueCount++;
            }
        }

        echo "  Page $page: Found $pageIssueCount matching issues (oldest changed: " . date('Y-m-d H:i:s', $oldestOnPage) . ")\n";

        // Check if we should continue to next page
        // If oldest item on this page is still newer than sprint start, there might be more
        // Also check if there's a 'next' link
        if (isset($response['next'])) {
            // If the oldest item on this page is older than sprint start,
            // we've gone past our date range - stop fetching
            if ($oldestOnPage < $sprintStartTimestamp) {
                echo "  Reached issues older than sprint start date. Stopping pagination.\n";
                $hasMorePages = false;
            } else {
                $page++;
            }
        } else {
            echo "  No more pages available.\n";
            $hasMorePages = false;
        }
    }

    echo "\nTotal issues collected: " . count($issues) . "\n\n";
    return $issues;
}

/**
 * Fetch project details to get project name
 *
 * @param string $projectUri The project API URI
 * @return string|null Project name or null on error
 */
function fetchProjectName($projectUri) {
    static $projectCache = [];

    if (isset($projectCache[$projectUri])) {
        return $projectCache[$projectUri];
    }

    $response = apiRequest($projectUri);

    if ($response === null || !isset($response['title'])) {
        $projectCache[$projectUri] = null;
        return null;
    }

    $projectCache[$projectUri] = $response['title'];
    return $response['title'];
}

/**
 * Fetch all comments for an issue to get contributors
 *
 * @param int $nid The issue node ID
 * @param string $sprintStartDate The sprint start date for filtering comments
 * @return array List of unique usernames (commenters and author)
 */
function fetchIssueContributors($nid, $sprintStartDate) {
    $sprintStartTimestamp = strtotime($sprintStartDate);
    $contributors = [];
    $page = 0;
    $hasMorePages = true;

    while ($hasMorePages) {
        $url = API_BASE_URL . "/comment.json?node=$nid&limit=" . API_PAGE_LIMIT . "&page=$page";
        $response = apiRequest($url);

        if ($response === null || !isset($response['list']) || empty($response['list'])) {
            break;
        }

        foreach ($response['list'] as $comment) {
            // Only include comments made after sprint start date.
            $createdTimestamp = (int)($comment['created'] ?? 0);
            if ($createdTimestamp < $sprintStartTimestamp) {
                continue;
            }
            if (isset($comment['name']) && !empty($comment['name'])) {
                $contributors[$comment['name']] = true;
            }
        }

        if (isset($response['next'])) {
            $page++;
        } else {
            $hasMorePages = false;
        }
    }

    return array_keys($contributors);
}

/**
 * Get status label from status code
 *
 * @param int $statusCode The numeric status code
 * @return string Human-readable status label
 */
function getStatusLabel($statusCode) {
    return STATUS_LABELS[$statusCode] ?? "Unknown ($statusCode)";
}

/**
 * Format timestamp to readable date or return empty if not available
 *
 * @param int|null $timestamp Unix timestamp
 * @return string Formatted date or empty string
 */
function formatTimestamp($timestamp) {
    if (empty($timestamp) || $timestamp == 0) {
        return '';
    }
    return date('Y-m-d H:i:s', (int)$timestamp);
}

/**
 * Process issues and create CSV files
 *
 * @param array $issues List of issue data from API
 * @param string $issuesCsvPath Path for issues CSV output
 * @param string $contributorsCsvPath Path for contributors CSV output
 * @param string $sprintStartDate The sprint start date for filtering issues
 */
function processAndCreateCsvs($issues, $issuesCsvPath, $contributorsCsvPath, $sprintStartDate) {
    echo "Processing " . count($issues) . " issues...\n\n";

    // Open CSV files
    $issuesCsv = fopen($issuesCsvPath, 'w');
    $contributorsCsv = fopen($contributorsCsvPath, 'w');

    if ($issuesCsv === false || $contributorsCsv === false) {
        echo "ERROR: Failed to open CSV files for writing.\n";
        return;
    }

    // Write headers
    fputcsv($issuesCsv, [
        'nid',
        'title',
        'link',
        'project_name',
        'component',
        'current_status',
        'last_time_in_review',
        'last_time_in_needs_work',
        'last_time_in_rtbc',
        'last_time_in_fixed',
        'created',
        'changed'
    ]);

    fputcsv($contributorsCsv, [
        'nid',
        'title',
        'link',
        'project_name',
        'contributors'
    ]);

    $processedCount = 0;
    $totalCount = count($issues);

    foreach ($issues as $issue) {
        $processedCount++;
        $nid = $issue['nid'] ?? '';
        $title = $issue['title'] ?? '';
        $link = $issue['url'] ?? '';

        echo "[$processedCount/$totalCount] Processing issue #$nid: $title\n";

        // Get project name
        $projectName = '';
        if (isset($issue['field_project']['uri'])) {
            $projectName = fetchProjectName($issue['field_project']['uri']);
            if ($projectName === null) {
                $projectName = '';
            }
        }

        // Get component
        $component = $issue['field_issue_component'] ?? '';

        // Get current status
        $statusCode = (int)($issue['field_issue_status'] ?? 0);
        $currentStatus = getStatusLabel($statusCode);

        // Get timestamps
        $created = formatTimestamp($issue['created'] ?? null);
        $changed = formatTimestamp($issue['changed'] ?? null);
        $lastStatusChange = $issue['field_issue_last_status_change'] ?? null;

        // Determine timing based on current status
        // Note: The API only provides the last status change time, not history
        // So we can only populate the timing for the CURRENT status
        $lastTimeInReview = '';
        $lastTimeInNeedsWork = '';
        $lastTimeInRtbc = '';
        $lastTimeInFixed = '';

        if ($lastStatusChange) {
            $formattedTime = formatTimestamp($lastStatusChange);
            switch ($statusCode) {
                case STATUS_NEEDS_REVIEW:
                    $lastTimeInReview = $formattedTime;
                    break;
                case STATUS_NEEDS_WORK:
                    $lastTimeInNeedsWork = $formattedTime;
                    break;
                case STATUS_RTBC:
                    $lastTimeInRtbc = $formattedTime;
                    break;
                case STATUS_FIXED:
                    $lastTimeInFixed = $formattedTime;
                    break;
            }
        }

        // Write to issues CSV
        fputcsv($issuesCsv, [
            $nid,
            $title,
            $link,
            $projectName,
            $component,
            $currentStatus,
            $lastTimeInReview,
            $lastTimeInNeedsWork,
            $lastTimeInRtbc,
            $lastTimeInFixed,
            $created,
            $changed
        ]);

        // Fetch contributors (commenters + author)
        $contributors = fetchIssueContributors($nid, $sprintStartDate);

        // Add issue author if available
        if (isset($issue['author']['id'])) {
            // We need to fetch author username - it's not in the node list response
            $authorUrl = API_BASE_URL . "/user/{$issue['author']['id']}.json";
            $authorData = apiRequest($authorUrl);
            if ($authorData !== null && isset($authorData['name'])) {
                $contributors[] = $authorData['name'];
            }
        }

        // Remove duplicates
        $contributors = array_unique($contributors);

        // Write to contributors CSV
        fputcsv($contributorsCsv, [
            $nid,
            $title,
            $link,
            $projectName,
            implode(', ', $contributors)
        ]);

        echo "  - Project: $projectName, Status: $currentStatus, Contributors: " . count($contributors) . "\n";
    }

    fclose($issuesCsv);
    fclose($contributorsCsv);

    echo "\nCSV files created successfully!\n";
    echo "  - Issues CSV: $issuesCsvPath\n";
    echo "  - Contributors CSV: $contributorsCsvPath\n";
}

/**
 * Main function to run the script
 *
 * @param string $sprintStartDate Sprint start date in YYYY-MM-DD format
 * @param int $taxonomyId Taxonomy term ID for the tag filter
 * @param string $outputDir Output directory for CSV files (optional)
 */
function main($sprintStartDate, $taxonomyId, $outputDir = '.') {
    echo "=== Drupal.org Issue Status Collector ===\n\n";

    // Validate inputs
    if (empty($sprintStartDate)) {
        echo "ERROR: Sprint start date is required.\n";
        echo "Usage: php get_current_status.php <sprint_start_date> [taxonomy_id]\n";
        echo "Example: php get_current_status.php 2025-01-01 205151\n";
        return;
    }

    // Create output directory if it doesn't exist
    if (!is_dir($outputDir)) {
        if (!mkdir($outputDir, 0755, true)) {
            echo "ERROR: Failed to create output directory: $outputDir\n";
            return;
        }
    }

    // Generate output file paths
    $dateStr = date('Y-m-d_His');
    $issuesCsvPath = rtrim($outputDir, '/') . "/statissues_$dateStr.csv";
    $contributorsCsvPath = rtrim($outputDir, '/') . "/contributors_$dateStr.csv";

    // Fetch all issues
    $issues = fetchAllIssues($sprintStartDate, $taxonomyId);

    if (empty($issues)) {
        echo "No issues found matching criteria.\n";
        return;
    }

    // Process issues and create CSVs
    processAndCreateCsvs($issues, $issuesCsvPath, $contributorsCsvPath, $sprintStartDate);

    echo "\n=== Done ===\n";
}

// CLI entry point
if (php_sapi_name() === 'cli') {
    $sprintStartDate = $argv[1] ?? '';
    $taxonomyId = $argv[2] ?? 205151; // AI Initiative Sprint tag ID.

    main($sprintStartDate, $taxonomyId, 'status_files');
}
