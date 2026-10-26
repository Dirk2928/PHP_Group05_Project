<?php














const BREWSKI_AVATAR_MAX_BYTES  = 5242880;
const BREWSKI_AVATAR_MAX_DIM    = 1000;
const BREWSKI_AVATAR_MAX_PIXELS = 1000000;
const BREWSKI_AVATAR_ALLOWED    = [
    'image/jpeg' => true,
    'image/png'  => true,
    'image/webp' => true,
];

function brewski_ensure_user_avatar_columns(mysqli $connection): void
{
    $columns = $connection->query("SHOW COLUMNS FROM users LIKE 'avatar_data'");

    if ($columns->num_rows === 0) {
        $connection->query('ALTER TABLE users ADD COLUMN avatar_data LONGBLOB NULL AFTER email');
    }

    $columns = $connection->query("SHOW COLUMNS FROM users LIKE 'avatar_mime_type'");

    if ($columns->num_rows === 0) {
        $connection->query('ALTER TABLE users ADD COLUMN avatar_mime_type VARCHAR(32) NULL AFTER avatar_data');
    }
}





function brewski_read_avatar_upload(array $upload): array
{
    switch ($upload['error'] ?? UPLOAD_ERR_NO_FILE) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RuntimeException('The image exceeds the upload limit. Choose a smaller image.');
        case UPLOAD_ERR_PARTIAL:
            throw new RuntimeException('The upload was interrupted. Please try again.');
        case UPLOAD_ERR_NO_FILE:
            throw new RuntimeException('Choose an image to upload.');
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            error_log('Avatar upload failure. Code: ' . $upload['error']);
            throw new RuntimeException('The image could not be uploaded. Please try again.');
        default:
            throw new RuntimeException('The image upload could not be completed.');
    }

    if (($upload['size'] ?? 0) <= 0 || $upload['size'] > BREWSKI_AVATAR_MAX_BYTES) {
        throw new RuntimeException('Choose an image no larger than 5 MB.');
    }

    if (!is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('The upload could not be verified.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($upload['tmp_name']);
    if (!isset(BREWSKI_AVATAR_ALLOWED[$mimeType])) {
        throw new RuntimeException('Upload a JPG, PNG, or WebP image.');
    }

    $imageInfo = @getimagesize($upload['tmp_name']);
    if ($imageInfo === false) {
        throw new RuntimeException('The file is not a valid image.');
    }

    [$width, $height] = $imageInfo;

    if ($width < 1 || $height < 1) {
        throw new RuntimeException('The image has invalid dimensions.');
    }
    if ($width > BREWSKI_AVATAR_MAX_DIM || $height > BREWSKI_AVATAR_MAX_DIM) {
        throw new RuntimeException('The image is too large. Maximum ' . BREWSKI_AVATAR_MAX_DIM . '×' . BREWSKI_AVATAR_MAX_DIM . ' pixels.');
    }
    if (($width * $height) > BREWSKI_AVATAR_MAX_PIXELS) {
        throw new RuntimeException('The image has too many pixels. Please resize and try again.');
    }

    $detectedFromImage = $imageInfo['mime'] ?? '';
    if ($detectedFromImage !== $mimeType) {
        throw new RuntimeException('The image appears to be corrupted or is not a valid image file.');
    }

    $data = file_get_contents($upload['tmp_name']);
    if ($data === false || strlen($data) === 0) {
        throw new RuntimeException('The image could not be read.');
    }

    return ['data' => $data, 'mime' => $mimeType];
}




function brewski_user_has_avatar(mysqli $connection, int $userId): bool
{
    $stmt = $connection->prepare(
        'SELECT avatar_mime_type FROM users WHERE user_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($mime);
    $has = $stmt->fetch() && !empty($mime);
    $stmt->close();

    return $has;
}
