# Touch Signature to SVG, PNG or JPG - Complete Form Integration

A comprehensive signature pad integration example that allows users to draw digital signatures and submit them through web forms. This example builds upon the [signature_pad library](https://github.com/szimek/signature_pad) to provide a complete form submission system with database storage and file management.

![Signature Pad Integration](https://nikimolnar.uk/github/img/sigpad_og.jpg)

## 🚀 Features

- **Multiple Signature Methods**: Draw signatures or type names
- **Multiple Export Formats**: PNG, JPG, SVG support
- **Complete Form Integration**: Ready-to-use form with validation
- **Database Storage**: Secure data storage with PDO
- **File Management**: Automatic file naming and organization
- **Responsive Design**: Works on desktop and mobile devices
- **Security Features**: Input validation, SQL injection prevention, file validation
- **Error Handling**: Comprehensive error logging and user feedback

## 📋 Requirements

- **PHP 7.4+** with PDO MySQL extension
- **MySQL 5.7+** or **MariaDB 10.2+**
- **Web server** (Apache, Nginx, or similar)
- **Modern web browser** with HTML5 Canvas support

## 🛠️ Installation & Setup

### Step 1: Download Files

Clone or download this repository and copy these files to your web directory:

```
your-website/
├── example.html (or rename to your preferred name, .e.g index.html which will be used from now on)
├── submit-agreement.php
├── uploads/ (you need to create this)
└── signature_form_errors.log (auto-created)
```

### Step 2: Database Setup

#### 2.1 Create Database

Create a new MySQL database for your application:

```sql
CREATE DATABASE signature_forms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

#### 2.2 Create Database User (Optional but Recommended)

```sql
CREATE USER 'signature_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON signature_forms.* TO 'signature_user'@'localhost';
FLUSH PRIVILEGES;
```

#### 2.3 Create Table (Optional - Auto-Created by Script)

The PHP script will automatically create the table, but you can create it manually if preferred:

```sql
USE signature_forms;

CREATE TABLE form_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    company VARCHAR(255),
    signature_method ENUM('drawn', 'typed') NOT NULL,
    signature_data TEXT,
    signature_file_png VARCHAR(255),
    signature_file_jpg VARCHAR(255),
    signature_file_svg VARCHAR(255),
    agree_terms BOOLEAN NOT NULL DEFAULT 0,
    ip_address VARCHAR(45),
    user_agent TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_submitted_at (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Step 3: Configure PHP Script

#### 3.1 Edit Database Connection

Open `submit-agreement.php` and update the database configuration (lines 25-30):

```php
// Database Configuration
$db_host = 'localhost';                    // Your database host
$db_name = 'signature_forms';              // Your database name
$db_user = 'signature_user';               // Your database username
$db_pass = 'your_secure_password';         // Your database password
$db_charset = 'utf8mb4';                   // Keep as utf8mb4
```

#### 3.2 Configure File Upload Settings

Update the file upload configuration (lines 32-37):

```php
// File Upload Configuration
$upload_path = 'uploads/signatures/';      // Relative path from PHP script
$max_file_size = 5 * 1024 * 1024;         // 5MB max file size
$allowed_formats = ['png', 'jpg', 'jpeg', 'svg'];  // Allowed formats
$default_format = 'png';                   // Default format for form submissions
$save_multiple_formats = false;            // Set true to save all formats
```

#### 3.3 Configure Application Settings

Adjust application behavior (lines 39-42):

```php
// Application Settings
$require_signature = true;        // Make signature mandatory
$log_submissions = true;          // Enable logging
$debug_mode = false;              // Set true for development only
```

### Step 4: Create Upload Directory

#### 4.1 Create Directory Structure

```bash
# Navigate to your web directory
cd /path/to/your/website

# Create upload directories
mkdir -p uploads/signatures

# Set proper permissions
chmod 755 uploads
chmod 755 uploads/signatures
```

#### 4.2 Alternative: Create via PHP

If you can't use command line, create this PHP script temporarily:

```php
<?php
$upload_dir = 'uploads/signatures';
if (!file_exists($upload_dir)) {
    if (mkdir($upload_dir, 0755, true)) {
        echo "Upload directory created successfully!";
    } else {
        echo "Failed to create upload directory.";
    }
} else {
    echo "Upload directory already exists.";
}
?>
```

### Step 5: Update HTML Form

#### 5.1 Update Form Action

In your HTML form, update the form submission URL in the JavaScript (around line 450):

```javascript
fetch('/submit-agreement.php', {  // Update this path if needed
    method: 'POST',
    body: formData
})
```

#### 5.2 Customize Form Fields (Optional)

Modify the form fields in the HTML to match your requirements:

```html
<!-- Add, remove, or modify these fields as needed -->
<div class="form-group">
    <label for="fullName">Full Name *</label>
    <input type="text" class="form-control" id="fullName" name="fullName" required>
</div>
<!-- Add your custom fields here -->
```

### Step 6: Security Configuration

#### 6.1 Web Server Security (Apache)

Create a `.htaccess` file in the uploads directory:

```apache
# uploads/.htaccess
# Prevent direct access to uploaded files
<Files "*">
    Order Deny,Allow
    Deny from all
</Files>

# Allow only image files to be accessed
<FilesMatch "\.(png|jpg|jpeg|svg)$">
    Order Allow,Deny
    Allow from all
</FilesMatch>

# Prevent PHP execution in uploads directory
php_flag engine off
```

#### 6.2 Web Server Security (Nginx)

Add to your Nginx configuration:

```nginx
# Prevent access to uploads directory
location /uploads/ {
    location ~* \.(png|jpg|jpeg|svg)$ {
        # Allow image files
    }
    location ~* \.(php|php5|phtml)$ {
        deny all;
    }
}
```

### Step 7: Testing

#### 7.1 Test Database Connection

Create a temporary test file:

```php
<?php
// test-db.php
$db_host = 'localhost';
$db_name = 'signature_forms';
$db_user = 'signature_user';
$db_pass = 'your_secure_password';

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    echo "Database connection successful!";
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>
```

#### 7.2 Test Form Submission

1. Open your HTML form in a web browser
2. Fill out all required fields
3. Either draw a signature or type your name
4. Submit the form
5. Check for success message
6. Verify data in database and uploaded files

#### 7.3 Check Error Logs

Monitor these files for any issues:

```bash
# Check PHP error log
tail -f /var/log/php_errors.log

# Check custom application logs
tail -f signature_form_errors.log
tail -f signature_form_success.log
```

## 🔧 Customization Options

### Signature Pad Settings

Modify the signature pad behavior in the HTML JavaScript:

```javascript
window.signaturePad = new SignaturePad(canvas, {
    backgroundColor: 'rgb(255, 255, 255)',  // Background color
    penColor: 'rgb(0, 0, 0)',              // Pen color
    minWidth: 1,                            // Minimum stroke width
    maxWidth: 3,                            // Maximum stroke width
    velocityFilterWeight: 0.7,              // Stroke smoothing
    minDistance: 5                          // Minimum distance between points
});
```

### Canvas Size

Adjust the signature canvas size in the CSS:

```css
.signature-pad canvas {
    height: 200px; /* Change this value */
    width: 100%;
}
```

### File Format Selection

Enable multiple format saving:

```php
// In submit-agreement.php
$save_multiple_formats = true;  // Saves PNG, JPG, and SVG
```

### Form Validation

Add custom validation to the PHP script:

```php
// Add to validateInput() function
if (empty($data['company']) && $require_company) {
    $errors[] = "Company name is required.";
}
```

## 📁 File Structure

Your final directory structure should look like:

```
your-website/
├── index.html                     # Main form page
├── submit-agreement.php           # Form processor
├── uploads/
│   ├── .htaccess                 # Security config
│   └── signatures/               # Signature files
│       ├── signature_1_20240101123456.png
│       └── signature_1_20240101123456.svg
├── signature_form_errors.log     # Error log (auto-created)
└── signature_form_success.log    # Success log (auto-created)
```

## 🗄️ Database Schema

The form submissions table structure:

| Field | Type | Description |
|-------|------|-------------|
| `id` | INT (PK) | Auto-incrementing primary key |
| `full_name` | VARCHAR(255) | User's full name |
| `email` | VARCHAR(255) | User's email address |
| `company` | VARCHAR(255) | Company name (optional) |
| `signature_method` | ENUM | 'drawn' or 'typed' |
| `signature_data` | TEXT | Text signature data |
| `signature_file_png` | VARCHAR(255) | PNG filename |
| `signature_file_jpg` | VARCHAR(255) | JPG filename |
| `signature_file_svg` | VARCHAR(255) | SVG filename |
| `agree_terms` | BOOLEAN | Terms agreement status |
| `ip_address` | VARCHAR(45) | User's IP address |
| `user_agent` | TEXT | Browser user agent |
| `submitted_at` | TIMESTAMP | Submission timestamp |

## 🔒 Security Considerations

### File Upload Security

- **File type validation**: Only allows PNG, JPG, SVG formats
- **File size limits**: Configurable maximum file size
- **Unique filenames**: Prevents file conflicts and overwrites
- **Directory protection**: Prevents direct file access
- **No script execution**: PHP execution disabled in uploads directory

### Database Security

- **Prepared statements**: Prevents SQL injection
- **Input validation**: Validates all user input
- **Error handling**: Doesn't expose sensitive information

### Additional Recommendations

1. **Use HTTPS**: Always serve forms over HTTPS
2. **Rate limiting**: Implement rate limiting for form submissions
3. **CSRF protection**: Add CSRF tokens to forms
4. **File scanning**: Consider virus scanning for uploaded files
5. **Backup strategy**: Regular database and file backups

## 🐛 Troubleshooting

### Common Issues

#### "Database connection error"
- Check database credentials in PHP script
- Verify database server is running
- Ensure PHP PDO MySQL extension is installed

#### "Upload directory setup failed"
- Check directory permissions (should be 755)
- Verify web server has write access
- Check available disk space

#### "SignaturePad not loaded"
- Check internet connection (CDN dependency)
- Verify JavaScript console for errors
- Try the local fallback option

#### Signature not saving
- Check upload directory permissions
- Verify file size limits
- Check error logs for detailed messages

### Debug Mode

Enable debug mode for detailed error information:

```php
$debug_mode = true;  // In submit-agreement.php
```

**⚠️ Warning**: Never enable debug mode in production!

### Log Files

Check these log files for troubleshooting:

- `signature_form_errors.log` - Application errors
- `signature_form_success.log` - Successful submissions
- Server error logs (location varies by setup)

## 📚 Advanced Usage

### Custom Email Notifications

Add email functionality to the PHP script:

```php
// After successful submission
$to = $email;
$subject = "Form Submission Confirmation";
$message = "Thank you for your submission, {$full_name}!";
$headers = "From: noreply@yoursite.com\r\n";
mail($to, $subject, $message, $headers);
```

### File Download Feature

Create a download script for accessing signature files:

```php
// download-signature.php
$submission_id = $_GET['id'];
$format = $_GET['format']; // png, jpg, or svg

$paths = getSignatureFilePaths($submission_id);
if (isset($paths[$format])) {
    $file_path = $paths[$format];
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="signature.' . $format . '"');
    readfile($file_path);
}
```

### Integration with Popular CMSs

#### WordPress Integration
- Place files in active theme directory
- Use WordPress database functions instead of direct PDO
- Add proper nonces for CSRF protection

#### Custom Framework Integration
- Adapt database configuration to your framework's ORM
- Use framework's validation and error handling
- Integrate with existing user authentication

## 📄 License

This example builds upon the MIT-licensed [signature_pad library](https://github.com/szimek/signature_pad). The integration code provided here is also available under the MIT license.

## 🤝 Contributing

Feel free to submit issues, fork the repository, and create pull requests for any improvements.

## 📞 Support

For issues related to:
- **Signature Pad Library**: Visit the [original repository](https://github.com/szimek/signature_pad)
- **Integration Code**: Check the troubleshooting section above or create an issue

---

**Happy coding! 🎨✍️**
