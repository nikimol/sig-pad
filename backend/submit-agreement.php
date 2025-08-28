<?php
/**
 * =============================================================================
 * SIGNATURE FORM SUBMISSION HANDLER
 * =============================================================================
 * 
 * This PHP script handles form submissions from the signature pad form.
 * It processes both drawn signatures (as base64 images) and typed signatures,
 * saves them appropriately, and stores form data in a database.
 * 
 * Features:
 * - Handles both drawn and typed signatures
 * - Converts base64 images to files
 * - Database storage with PDO
 * - File upload management
 * - Error handling and validation
 * - JSON response for AJAX submissions
 */

// =============================================================================
// CONFIGURATION VARIABLES
// Customize these settings for your environment
// =============================================================================

// Database Configuration
$db_host = 'localhost';           // Database host
$db_name = 'your_database_name';  // Database name
$db_user = 'your_username';       // Database username
$db_pass = 'your_password';       // Database password
$db_charset = 'utf8mb4';          // Database charset

// File Upload Configuration
$upload_path = 'uploads/signatures/';  // Path to store signature files (relative to this script)
$max_file_size = 5 * 1024 * 1024;     // Maximum file size (5MB)
$allowed_formats = ['png', 'webp', 'svg']; // Allowed signature formats (optimal for signatures)
$default_format = 'png';               // Default format for form submissions
$save_multiple_formats = false;        // Set to true to save signature in all formats

// Application Settings
$require_signature = true;        // Whether signature is mandatory
$log_submissions = true;          // Whether to log all submissions
$debug_mode = false;              // Set to true for debugging (shows detailed errors)

// =============================================================================
// ERROR HANDLING AND RESPONSE FUNCTIONS
// =============================================================================

/**
 * Sends a JSON response and exits
 */
function sendResponse($success, $message, $data = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

/**
 * Logs errors and debug information
 */
function logError($message, $context = []) {
    global $log_submissions, $debug_mode;
    
    if ($log_submissions) {
        $log_entry = date('Y-m-d H:i:s') . " - " . $message;
        if (!empty($context)) {
            $log_entry .= " - Context: " . json_encode($context);
        }
        error_log($log_entry . "\n", 3, 'signature_form_errors.log');
    }
    
    if ($debug_mode) {
        sendResponse(false, $message, $context);
    }
}

// =============================================================================
// DATABASE CONNECTION
// =============================================================================

try {
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    logError("Database connection failed", ['error' => $e->getMessage()]);
    sendResponse(false, "Database connection error. Please try again later.");
}

// =============================================================================
// CREATE DATABASE TABLE IF NOT EXISTS
// =============================================================================

try {
    $sql = "CREATE TABLE IF NOT EXISTS form_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        company VARCHAR(255),
        signature_method ENUM('drawn', 'typed') NOT NULL,
        signature_data TEXT,
        signature_file_png VARCHAR(255),
        signature_file_webp VARCHAR(255),
        signature_file_svg VARCHAR(255),
        agree_terms BOOLEAN NOT NULL DEFAULT 0,
        ip_address VARCHAR(45),
        user_agent TEXT,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_submitted_at (submitted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql);
} catch (PDOException $e) {
    logError("Table creation failed", ['error' => $e->getMessage()]);
    sendResponse(false, "Database setup error. Please contact support.");
}

// =============================================================================
// ENSURE UPLOAD DIRECTORY EXISTS
// =============================================================================

if (!file_exists($upload_path)) {
    if (!mkdir($upload_path, 0755, true)) {
        logError("Failed to create upload directory", ['path' => $upload_path]);
        sendResponse(false, "Upload directory setup failed.");
    }
}

// =============================================================================
// VALIDATE REQUEST METHOD
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Invalid request method. Only POST requests are allowed.");
}

// =============================================================================
// INPUT VALIDATION AND SANITIZATION
// =============================================================================

/**
 * Validates and sanitizes form input
 */
function validateInput(array $data): array {
    global $require_signature;
    
    $errors = [];
    
    // Required fields validation
    if (empty($data['fullName'])) {
        $errors[] = "Full name is required.";
    }
    
    if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Valid email address is required.";
    }
    
    if (!isset($data['agreeTerms']) || $data['agreeTerms'] !== 'on') {
        $errors[] = "You must agree to the terms and conditions.";
    }
    
    // Signature validation
    if ($require_signature) {
        $signature_method = $data['signatureMethod'] ?? '';
        $signature_data = $data['signatureData'] ?? '';
        
        if (empty($signature_data)) {
            $errors[] = "Signature is required.";
        } elseif ($signature_method === 'drawn') {
            // Validate base64 image data
            if (!preg_match('/^data:image\/(png|webp);base64,/', $signature_data)) {
                $errors[] = "Invalid signature image format.";
            }
        } elseif ($signature_method === 'typed') {
            // Validate typed signature
            if (strlen(trim($signature_data)) < 2) {
                $errors[] = "Typed signature must be at least 2 characters long.";
            }
        } else {
            $errors[] = "Invalid signature method.";
        }
    }
    
    return $errors;
}

// =============================================================================
// SIGNATURE FILE PROCESSING
// =============================================================================

/**
 * Processes and saves signature files from base64 data in multiple formats
 */
function processSignatureFiles(string $base64_data, string $user_id, array $formats = ['png']): array {
    global $upload_path, $max_file_size, $allowed_formats;
    
    $saved_files = [];
    
    try {
        // Handle SVG format differently as it's XML-based
        if (str_starts_with($base64_data, 'data:image/svg+xml')) {
            return processSVGSignature($base64_data, $user_id);
        }
        
        // Extract image data and format for PNG/WebP
        if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64_data, $matches)) {
            throw new Exception("Invalid base64 image format");
        }
        
        $source_format = strtolower($matches[1]);
        $image_data = base64_decode($matches[2]);
        
        // Validate file size
        if (strlen($image_data) > $max_file_size) {
            throw new Exception("Signature file too large");
        }
        
        // Create image resource from data
        $image_resource = imagecreatefromstring($image_data);
        if (!$image_resource) {
            throw new Exception("Failed to create image from data");
        }
        
        // Process each requested format
        foreach ($formats as $format) {
            $format = strtolower($format);
            
            if (!in_array($format, $allowed_formats)) {
                continue; // Skip unsupported formats
            }
            
            // Generate filename
            $filename = 'signature_' . $user_id . '_' . date('YmdHis') . '_' . uniqid() . '.' . $format;
            $file_path = $upload_path . $filename;
            
            $success = false;
            
            // Save in requested format
            match ($format) {
                'png' => (function() use ($image_resource, $file_path, &$success) {
                    // Enable alpha blending for transparency
                    imagealphablending($image_resource, false);
                    imagesavealpha($image_resource, true);
                    $success = imagepng($image_resource, $file_path, 9); // Max compression
                })(),
                'webp' => (function() use ($image_resource, $file_path, &$success) {
                    // WebP with transparency support
                    imagealphablending($image_resource, false);
                    imagesavealpha($image_resource, true);
                    $success = imagewebp($image_resource, $file_path, 90); // 90% quality
                })(),
                default => null
            };
            
            if ($success) {
                $saved_files[$format] = $filename;
            }
        }
        
        // Clean up
        imagedestroy($image_resource);
        
        if (empty($saved_files)) {
            throw new Exception("Failed to save signature in any format");
        }
        
        return $saved_files;
        
    } catch (Exception $e) {
        // Clean up any partially saved files
        foreach ($saved_files as $filename) {
            if (file_exists($upload_path . $filename)) {
                unlink($upload_path . $filename);
            }
        }
        
        logError("Signature file processing failed", [
            'error' => $e->getMessage(),
            'user_id' => $user_id,
            'formats' => $formats
        ]);
        throw $e;
    }
}

/**
 * Processes SVG signature data
 */
function processSVGSignature(string $base64_data, string $user_id): array {
    global $upload_path, $max_file_size;
    
    try {
        // Extract SVG data
        if (!preg_match('/^data:image\/svg\+xml;base64,(.+)$/', $base64_data, $matches)) {
            throw new Exception("Invalid SVG base64 format");
        }
        
        $svg_data = base64_decode($matches[1]);
        
        // Validate file size
        if (strlen($svg_data) > $max_file_size) {
            throw new Exception("SVG file too large");
        }
        
        // Basic SVG validation
        if (!str_contains($svg_data, '<svg') || !str_contains($svg_data, '</svg>')) {
            throw new Exception("Invalid SVG content");
        }
        
        // Generate filename
        $filename = 'signature_' . $user_id . '_' . date('YmdHis') . '_' . uniqid() . '.svg';
        $file_path = $upload_path . $filename;
        
        // Save SVG file
        if (file_put_contents($file_path, $svg_data) === false) {
            throw new Exception("Failed to save SVG file");
        }
        
        return ['svg' => $filename];
        
    } catch (Exception $e) {
        logError("SVG signature processing failed", [
            'error' => $e->getMessage(),
            'user_id' => $user_id
        ]);
        throw $e;
    }
}

// =============================================================================
// MAIN FORM PROCESSING
// =============================================================================

try {
    // Validate input
    $validation_errors = validateInput($_POST);
    if (!empty($validation_errors)) {
        sendResponse(false, "Validation failed", ['errors' => $validation_errors]);
    }
    
    // Sanitize input data
    $full_name = trim($_POST['fullName']);
    $email = trim(strtolower($_POST['email']));
    $company = trim($_POST['company'] ?? '');
    $signature_method = $_POST['signatureMethod'] ?? 'typed';
    $signature_data = $_POST['signatureData'] ?? '';
    $agree_terms = isset($_POST['agreeTerms']) ? 1 : 0;
    
    // Additional data
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Process signature files if it's a drawn signature
    $signature_files = [];
    if ($signature_method === 'drawn' && !empty($signature_data)) {
        // Generate temporary user ID for filename (will be replaced with actual ID after insert)
        $temp_user_id = uniqid();
        
        // Determine which formats to save
        $formats_to_save = [$default_format]; // Always save default format
        
        if ($save_multiple_formats) {
            // Save in all supported formats (PNG, WebP, SVG)
            $formats_to_save = ['png', 'webp', 'svg'];
        }
        
        // Check if specific format was requested via POST
        if (isset($_POST['signatureFormat']) && in_array($_POST['signatureFormat'], $allowed_formats)) {
            $formats_to_save = [$_POST['signatureFormat']];
        }
        
        $signature_files = processSignatureFiles($signature_data, $temp_user_id, $formats_to_save);
    }
    
    // =============================================================================
    // DATABASE INSERTION
    // =============================================================================
    
    $sql = "INSERT INTO form_submissions (
        full_name, 
        email, 
        company, 
        signature_method, 
        signature_data, 
        signature_file_png,
        signature_file_webp,
        signature_file_svg,
        agree_terms, 
        ip_address, 
        user_agent
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $full_name,
        $email,
        $company,
        $signature_method,
        ($signature_method === 'typed') ? $signature_data : null, // Only store text signatures in database
        $signature_files['png'] ?? null,
        $signature_files['webp'] ?? null,
        $signature_files['svg'] ?? null,
        $agree_terms,
        $ip_address,
        $user_agent
    ]);
    
    $submission_id = $pdo->lastInsertId();
    
    // =============================================================================
    // RENAME SIGNATURE FILES WITH ACTUAL ID
    // =============================================================================
    
    if (!empty($signature_files) && $signature_method === 'drawn') {
        $updated_files = [];
        
        foreach ($signature_files as $format => $old_filename) {
            $old_path = $upload_path . $old_filename;
            $extension = pathinfo($old_filename, PATHINFO_EXTENSION);
            $new_filename = 'signature_' . $submission_id . '_' . date('YmdHis') . '.' . $extension;
            $new_path = $upload_path . $new_filename;
            
            if (rename($old_path, $new_path)) {
                $updated_files[$format] = $new_filename;
            }
        }
        
        // Update database with new filenames
        $update_fields = [];
        $update_values = [];
        
        if (isset($updated_files['png'])) {
            $update_fields[] = "signature_file_png = ?";
            $update_values[] = $updated_files['png'];
        }
        if (isset($updated_files['webp'])) {
            $update_fields[] = "signature_file_webp = ?";
            $update_values[] = $updated_files['webp'];
        }
        if (isset($updated_files['svg'])) {
            $update_fields[] = "signature_file_svg = ?";
            $update_values[] = $updated_files['svg'];
        }
        
        if (!empty($update_fields)) {
            $update_sql = "UPDATE form_submissions SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $update_values[] = $submission_id;
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute($update_values);
            $signature_files = $updated_files;
        }
    }
    
    // =============================================================================
    // LOG SUCCESS
    // =============================================================================
    
    if ($log_submissions) {
        $log_message = "Form submitted successfully - ID: {$submission_id}, Email: {$email}, Method: {$signature_method}";
        error_log(date('Y-m-d H:i:s') . " - " . $log_message . "\n", 3, 'signature_form_success.log');
    }
    
    // =============================================================================
    // SUCCESS RESPONSE
    // =============================================================================
    
    sendResponse(true, "Form submitted successfully!", [
        'submission_id' => $submission_id,
        'signature_method' => $signature_method,
        'signature_files' => $signature_files
    ]);
    
} catch (PDOException $e) {
    logError("Database error during submission", [
        'error' => $e->getMessage(),
        'email' => $_POST['email'] ?? 'unknown'
    ]);
    sendResponse(false, "Database error occurred. Please try again.");
    
} catch (Exception $e) {
    logError("General error during submission", [
        'error' => $e->getMessage(),
        'email' => $_POST['email'] ?? 'unknown'
    ]);
    sendResponse(false, "An error occurred while processing your submission. Please try again.");
}

// =============================================================================
// ADDITIONAL UTILITY FUNCTIONS (for future use)
// =============================================================================

/**
 * Retrieves a form submission by ID
 */
function getSubmission(int $id): array|false {
    global $pdo;
    
    $sql = "SELECT * FROM form_submissions WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    
    return $stmt->fetch();
}

/**
 * Retrieves signature file paths for all formats
 */
function getSignatureFilePaths(int $submission_id): ?array {
    global $pdo, $upload_path;
    
    $sql = "SELECT signature_file_png, signature_file_webp, signature_file_svg FROM form_submissions WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$submission_id]);
    $result = $stmt->fetch();
    
    if (!$result) return null;
    
    $paths = [];
    foreach (['png', 'webp', 'svg'] as $format) {
        $filename = $result["signature_file_{$format}"];
        if ($filename && file_exists($upload_path . $filename)) {
            $paths[$format] = $upload_path . $filename;
        }
    }
    
    return $paths;
}

/**
 * Deletes all signature files for a submission
 */
function deleteSignatureFiles(int $submission_id): int {
    global $pdo, $upload_path;
    
    $sql = "SELECT signature_file_png, signature_file_webp, signature_file_svg FROM form_submissions WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$submission_id]);
    $result = $stmt->fetch();
    
    if (!$result) return 0;
    
    $deleted_count = 0;
    foreach (['png', 'webp', 'svg'] as $format) {
        $filename = $result["signature_file_{$format}"];
        if ($filename && file_exists($upload_path . $filename)) {
            if (unlink($upload_path . $filename)) {
                $deleted_count++;
            }
        }
    }
    
    return $deleted_count;
}

/**
 * Gets all submissions (with pagination)
 */
function getSubmissions(int $page = 1, int $per_page = 50): array {
    global $pdo;
    
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT id, full_name, email, company, signature_method, 
                   signature_file_png, signature_file_webp, signature_file_svg, submitted_at 
            FROM form_submissions 
            ORDER BY submitted_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$per_page, $offset]);
    
    return $stmt->fetchAll();
}

// =============================================================================
// EXAMPLE USAGE IN YOUR HTML FORM
// =============================================================================

/*
Update your JavaScript form submission to use this PHP handler:

fetch('/submit-agreement.php', {
    method: 'POST',
    body: formData
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        alert('Agreement submitted successfully!');
        form.reset();
        window.signaturePad.clear();
        updateSignatureStatus();
    } else {
        alert('Error: ' + data.message);
        if (data.data && data.data.errors) {
            console.log('Validation errors:', data.data.errors);
        }
    }
})
.catch(error => {
    alert('Network error. Please try again.');
    console.error('Error:', error);
});
*/

?>
