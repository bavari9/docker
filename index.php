<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 30);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$pass_url = "https://www.eurowebtv.cc/pass";
$auth_url = "https://www.eurowebtv.cc/auth";

// Use system temp directory safely on Render
$tmp_dir = sys_get_temp_dir();
$session_id = uniqid('', true);
$cookie_file = $tmp_dir . '/cookie_' . $session_id . '.txt';
$tmp_img_path = $tmp_dir . '/pass_' . $session_id . '.png';

// 1. Fetch /pass page
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
    CURLOPT_TIMEOUT => 15,
    CURLOPT_ENCODING => 'gzip,deflate',
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

// 3. Process image with GD (4x upscale for clean OCR parsing)
$img_data = base64_decode($matches[1]);
file_put_contents($tmp_img_path, $img_data);

$src_img = @imagecreatefrompng($tmp_img_path);
if ($src_img) {
    $width = imagesx($src_img);
    $height = imagesy($src_img);
    $dst_img = imagecreatetruecolor($width * 4, $height * 4);
    imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $width * 4, $height * 4, $width, $height);
    imagepng($dst_img, $tmp_img_path, 9);
    imagedestroy($src_img);
    imagedestroy($dst_img);
}

// 4. Run local Tesseract installed via Docker container
$output_base = $tmp_dir . '/ocr_' . $session_id;
$tesseract_path = '/usr/bin/tesseract';

$command = "{$tesseract_path} " . escapeshellarg($tmp_img_path) . " " . 
           escapeshellarg($output_base) . " --psm 7 -c tessedit_char_whitelist=0123456789";
shell_exec($command . " 2>&1");

$raw_text = @file_get_contents($output_base . '.txt');
$number = preg_replace('/[^0-9]/', '', $raw_text);

// Clean up temp image/txt files
@unlink($tmp_img_path);
@unlink($output_base . '.txt');

if (empty($number)) {
    echo json_encode(["error" => "OCR failed to extract numbers"]);
    @unlink($cookie_file);
    exit;
}

// 5. Send resolved code to auth endpoint
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
    ],
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_COOKIEJAR => $cookie_file,
    CURLOPT_TIMEOUT => 15,
]);

$auth_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

@unlink($cookie_file);

// 6. Output structured JSON response
$lines = explode("\n", trim($auth_response));
$result = [
    "extracted_number" => $number,
    "http_status" => $http_code,
    "auth_raw_response" => $auth_response,
    "parsed_data" => [
        "status" => $lines[0] ?? '',
        "token" => $lines[1] ?? '',
        "code" => $lines[2] ?? '',
        "extra" => $lines[3] ?? ''
    ],
    "timestamp" => date('Y-m-d H:i:s')
];

echo json_encode($result, JSON_PRETTY_PRINT);
?>
