<?php

function uploadProductImage($file) {
    $target_dir = dirname(__DIR__) . "/assets/uploads/products/";
    
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $new_filename = uniqid() . '.' . $file_extension;
    $target_file = $target_dir . $new_filename;
    
    $check = getimagesize($file["tmp_name"]);
    if ($check === false) {
        return [false, "File is not an image."];
    }
    
    if ($file["size"] > 5000000) {
        return [false, "File is too large. Maximum size is 5MB."];
    }
    
    if (!in_array($file_extension, ["jpg", "jpeg", "png", "gif"])) {
        return [false, "Only JPG, JPEG, PNG & GIF files are allowed."];
    }
    
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return [true, "/assets/uploads/products/" . $new_filename];
    }
    
    return [false, "Failed to upload file."];
}

function extractColors($imagePath) {
    $colors = [];
    $serverPath = __DIR__ . "/.." . parse_url($imagePath, PHP_URL_PATH);
    
    if (!file_exists($serverPath)) {
        return [
            'primary' => '#2E4412',
            'secondary' => '#F6C500',
            'accent' => '#F78C56'
        ];
    }
    
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        return [
            'primary' => '#2E4412',
            'secondary' => '#F6C500',
            'accent' => '#F78C56'
        ];
    }

    $image = null;
    $extension = strtolower(pathinfo($serverPath, PATHINFO_EXTENSION));

    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            $image = @imagecreatefromjpeg($serverPath);
            break;
        case 'png':
            $image = @imagecreatefrompng($serverPath);
            break;
        case 'gif':
            $image = @imagecreatefromgif($serverPath);
            break;
        default:
            return [
                'primary' => '#2E4412',
                'secondary' => '#F6C500',
                'accent' => '#F78C56'
            ];
    }
    
    if (!$image) {
        return null;
    }
    
    $width = imagesx($image);
    $height = imagesy($image);
    $scale = 50; // Sample every 50th pixel
    
    $colors_count = [];
    
    for ($x = 0; $x < $width; $x += $scale) {
        for ($y = 0; $y < $height; $y += $scale) {
            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            
            // Convert to hex and count occurrences
            $hex = sprintf("#%02x%02x%02x", $r, $g, $b);
            if (!isset($colors_count[$hex])) {
                $colors_count[$hex] = 1;
            } else {
                $colors_count[$hex]++;
            }
        }
    }
    
    arsort($colors_count);
    
    $dominant_colors = array_keys(array_slice($colors_count, 0, 3));
    
    imagedestroy($image);
    
    return [
        'primary' => $dominant_colors[0] ?? '#2E4412',
        'secondary' => $dominant_colors[1] ?? '#F6C500',
        'accent' => $dominant_colors[2] ?? '#F78C56'
    ];
}