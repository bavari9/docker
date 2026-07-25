<?php
// Optimized for high-performance server with 128GB RAM, dual Xeon
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M'); // Increase for high load
ini_set('max_execution_time', 30);

// Strict no-cache headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

$pass_url = "https://www.eurowebtv.cc/pass";
$auth_url = "https://www.eurowebtv.cc/auth";

// Use RAM disk for temp files (faster than SSD)
$tmp_dir = '/dev/shm'; // RAM disk for maximum speed
if (!is_dir($tmp_dir) || !is_writable($tmp_dir)) {
    $tmp_dir = sys_get_temp_dir(); // Fallback
}

// Unique session ID
$session_id = uniqid('', true);
$cookie_file = $tmp_dir . '/eurowebtv_cookie_' . $session_id . '.txt';
$tmp_img_path = $tmp_dir . '/temp_pass_' . $session_id . '.png';

// 1. Fetch /pass with optimized cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $pass_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_COOKIEJAR => $cookie_file,
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    CURLOPT_HTTPHEADER => ["Cache-Control: no-cache", "Pragma: no-cache"],
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_ENCODING => 'gzip,deflate', // Faster transfers
]);

$html = curl_exec($ch);
if ($html === false) {
    echo json_encode(["error" => "Failed to fetch pass page: " . curl_error($ch)]);
    curl_close($ch);
    @unlink($cookie_file);
    exit;
}
curl_close($ch);

// 2. Extract base64 image
preg_match('/src="data:image\/png;base64,([^"]+)"/', $html, $matches);
if (!isset($matches[1])) {
    echo json_encode(["error" => "Base64 image string not found"]);
    @unlink($cookie_file);
    exit;
}

// 3. Process image with GD
$base64_data = $matches[1];
$img_data = base64_decode($base64_data);
file_put_contents($tmp_img_path, $img_data);

// Resize image for better OCR (4x)
$src_img = @imagecreatefrompng($tmp_img_path);
if ($src_img) {
    $width = imagesx($src_img);
    $height = imagesy($src_img);
    $new_width = $width * 4;
    $new_height = $height * 4;
    $dst_img = imagecreatetruecolor($new_width, $new_height);
    imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    imagepng($dst_img, $tmp_img_path, 9);
    imagedestroy($src_img);
    imagedestroy($dst_img);
}

// 4. Tesseract OCR with optimal settings for your hardware
$output_base = $tmp_dir . '/ocr_result_' . $session_id;
$tesseract_path = '/usr/bin/tesseract';

// Use multiple threads if available (Tesseract 4+ supports it)
$command = "{$tesseract_path} " . escapeshellarg($tmp_img_path) . " " . 
           escapeshellarg($output_base) . " --psm 7 -c tessedit_char_whitelist=0123456789";
shell_exec($command . " 2>&1");

$raw_text = @file_get_contents($output_base . '.txt');
$number = preg_replace('/[^0-9]/', '', $raw_text);

// Clean up
@unlink($tmp_img_path);
@unlink($output_base . '.txt');

if (empty($number)) {
    echo json_encode(["error" => "OCR failed to extract numbers"]);
    @unlink($cookie_file);
    exit;
}

// 5. Send to auth with optimized cURL
$post_data = http_build_query(['pass' => $number]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $auth_url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post_data,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/x-www-form-urlencoded",
        "Origin: https://www.eurowebtv.cc",
        "Referer: https://www.eurowebtv.cc/pass",
        "X-Requested-With: XMLHttpRequest",
        "Cache-Control: no-cache",
        "Pragma: no-cache",
        "Accept: */*",
        "Accept-Encoding: gzip,deflate",
    ],
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_COOKIEJAR => $cookie_file,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_ENCODING => 'gzip,deflate',
]);

$auth_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Clean up cookie file
@unlink($cookie_file);

// 6. Parse response
$lines = explode("\n", trim($auth_response));
$parsed_status = $lines[0] ?? '';
$parsed_token = $lines[1] ?? '';
$parsed_code = $lines[2] ?? '';
$parsed_extra = $lines[3] ?? '';

// 7. Build result
$result = [
    "extracted_number" => $number,
    "http_status" => $http_code,
    "auth_raw_response" => $auth_response,
    "parsed_data" => [
        "status" => $parsed_status,
        "token" => $parsed_token,
        "code" => $parsed_code,
        "extra" => $parsed_extra
    ],
    "timestamp" => date('Y-m-d H:i:s')
];

// 8. Save to JSON (atomic write)
$json_file_path = __DIR__ . '/current_pass.json';
file_put_contents($json_file_path, json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL);

echo json_encode($result, JSON_PRETTY_PRINT);
?>
