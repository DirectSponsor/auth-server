<?php
/**
 * Trash System for User Data Deletions
 * 
 * Moves files to trash instead of direct deletion
 * Ensures deletions sync across all sites via sync-system
 * Provides 30-day recovery window for mistakes
 */

/**
 * Move a file to trash instead of deleting it
 * 
 * @param string $file_path Full path to the file
 * @param string $site_data_dir Base data directory (e.g., '/var/roflfaucet-data')
 * @return bool Success status
 */
function moveToTrash($file_path, $site_data_dir) {
    // Validate file exists
    if (!file_exists($file_path)) {
        error_log("Trash: File does not exist: $file_path");
        return false;
    }
    
    // Determine trash directory
    $trash_base = $site_data_dir . '/userdata/.trash';
    $date_dir = $trash_base . '/' . date('Y-m-d');
    
    // Get file category (balances, profiles, tokens)
    $category = basename(dirname($file_path));
    $trash_category_dir = $date_dir . '/' . $category;
    
    // Create directories if needed
    if (!is_dir($trash_category_dir)) {
        if (!mkdir($trash_category_dir, 0755, true)) {
            error_log("Trash: Failed to create trash directory: $trash_category_dir");
            return false;
        }
    }
    
    // Create unique filename with timestamp
    $original_name = basename($file_path);
    $trash_filename = $original_name . '.' . time();
    $trash_path = $trash_category_dir . '/' . $trash_filename;
    
    // Move file to trash
    if (rename($file_path, $trash_path)) {
        error_log("Trash: Moved to trash: $file_path -> $trash_path");
        return true;
    } else {
        error_log("Trash: Failed to move to trash: $file_path");
        return false;
    }
}

/**
 * Restore a file from trash
 * 
 * @param string $trash_path Path to file in trash
 * @param string $original_path Where to restore the file
 * @return bool Success status
 */
function restoreFromTrash($trash_path, $original_path) {
    // Ensure original directory exists
    $original_dir = dirname($original_path);
    if (!is_dir($original_dir)) {
        mkdir($original_dir, 0755, true);
    }
    
    // Restore file
    if (rename($trash_path, $original_path)) {
        error_log("Trash: Restored from trash: $trash_path -> $original_path");
        return true;
    } else {
        error_log("Trash: Failed to restore from trash: $trash_path");
        return false;
    }
}

/**
 * List files in trash for a specific date
 * 
 * @param string $site_data_dir Base data directory
 * @param string $date Date in Y-m-d format (default: today)
 * @return array List of trashed files with metadata
 */
function listTrashFiles($site_data_dir, $date = null) {
    $date = $date ?: date('Y-m-d');
    $trash_dir = $site_data_dir . '/userdata/.trash/' . $date;
    
    if (!is_dir($trash_dir)) {
        return [];
    }
    
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($trash_dir)
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $parts = explode('/', $file->getPath());
            $category = end($parts);
            $filename = $file->getFilename();
            
            // Extract original filename and timestamp
            if (preg_match('/^(.+)\.(\d+)$/', $filename, $matches)) {
                $original_name = $matches[1];
                $timestamp = $matches[2];
                $deleted_time = new DateTime("@$timestamp");
            } else {
                $original_name = $filename;
                $deleted_time = $file->getMTime();
            }
            
            $files[] = [
                'trash_path' => $file->getPathname(),
                'original_name' => $original_name,
                'category' => $category,
                'deleted_time' => $deleted_time->format('Y-m-d H:i:s'),
                'size' => $file->getSize()
            ];
        }
    }
    
    return $files;
}

/**
 * Get trash directory size
 * 
 * @param string $site_data_dir Base data directory
 * @return int Total size in bytes
 */
function getTrashSize($site_data_dir) {
    $trash_dir = $site_data_dir . '/userdata/.trash';
    
    if (!is_dir($trash_dir)) {
        return 0;
    }
    
    $total_size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($trash_dir)
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $total_size += $file->getSize();
        }
    }
    
    return $total_size;
}

// Example usage in existing code:
/*
// Instead of:
unlink($balance_file);

// Use:
moveToTrash($balance_file, '/var/roflfaucet-data');
*/

?>
