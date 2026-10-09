<?php
require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../Db/product_images.php';

brewski_require_role(['ADMIN']);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$optionChoices = [
    'temperature' => [
        ['name' => 'Hot', 'price' => 0],
        ['name' => 'Iced', 'price' => 0],
    ],
    'size' => [
        ['name' => 'Regular', 'price' => 0],
        ['name' => 'Large', 'price' => 20],
    ],
    'sugar' => [
        ['name' => '100%', 'price' => 0],
        ['name' => '75%', 'price' => 0],
        ['name' => '50%', 'price' => 0],
        ['name' => '25%', 'price' => 0],
        ['name' => '0%', 'price' => 0],
    ],
];

$validOptions = [];
foreach ($optionChoices as $group => $choices) {
    foreach ($choices as $choice) {
        $validOptions[$group . ':' . $choice['name']] = [
            'group' => $group,
            'name' => $choice['name'],
            'price' => $choice['price'],
        ];
    }
}

if (empty($_SESSION['catalog_csrf'])) {
    $_SESSION['catalog_csrf'] = bin2hex(random_bytes(32));
}

$imagesDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'images'
    . DIRECTORY_SEPARATOR . 'menu';
$formAction = htmlspecialchars(
    str_replace(' ', '%20', $_SERVER['SCRIPT_NAME'] ?? ''),
    ENT_QUOTES,
    'UTF-8'
);
$flash = $_SESSION['catalog_flash'] ?? null;
unset($_SESSION['catalog_flash']);
$pageError = '';
$categories = [];
$products = [];
$addOns = [];
$connection = null;

function catalog_remove_uploaded_image(?string $imagePath, string $imagesDirectory): void
{
    if ($imagePath !== null && strpos($imagePath, 'menu/') === 0) {
        $path = $imagesDirectory . DIRECTORY_SEPARATOR . basename($imagePath);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

try {
    $connection = new mysqli('localhost', 'root', '', 'brewski_db');
    $connection->set_charset('utf8mb4');
    brewski_ensure_product_image_columns($connection);
    $connection->query(
        "CREATE TABLE IF NOT EXISTS product_customization_options (
            product_customization_option_id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            option_group VARCHAR(20) NOT NULL,
            option_name VARCHAR(100) NOT NULL,
            additional_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            max_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 99,
            CONSTRAINT fk_product_customization_options_product
                FOREIGN KEY (product_id)
                REFERENCES products(product_id)
                ON UPDATE CASCADE
                ON DELETE CASCADE,
            CONSTRAINT unique_product_customization_option
                UNIQUE (product_id, option_group, option_name)
        )"
    );
    $connection->query(
        "CREATE TABLE IF NOT EXISTS catalog_addons (
            addon_id INT AUTO_INCREMENT PRIMARY KEY,
            addon_name VARCHAR(100) NOT NULL UNIQUE,
            additional_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            max_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 99
        )"
    );
    $connection->query(
        "CREATE TABLE IF NOT EXISTS product_addons (
            product_id INT NOT NULL,
            addon_id INT NOT NULL,
            additional_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            max_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 99,
            PRIMARY KEY (product_id, addon_id),
            CONSTRAINT fk_product_addons_product
                FOREIGN KEY (product_id) REFERENCES products(product_id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_product_addons_addon
                FOREIGN KEY (addon_id) REFERENCES catalog_addons(addon_id)
                ON UPDATE CASCADE ON DELETE CASCADE
        )"
    );
    $maxQuantityColumn = $connection->query(
        "SHOW COLUMNS FROM product_customization_options LIKE 'max_quantity'"
    );
    if ($maxQuantityColumn->num_rows === 0) {
        $connection->query(
            'ALTER TABLE product_customization_options
             ADD COLUMN max_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 99'
        );
    }
    $legacyAddOns = $connection->query(
        "SELECT option_name, MIN(additional_price) AS additional_price,
                MAX(max_quantity) AS max_quantity
         FROM product_customization_options
         WHERE option_group = 'addon'
         GROUP BY option_name"
    );
    $addOnInsert = $connection->prepare(
        'INSERT IGNORE INTO catalog_addons (addon_name, additional_price, max_quantity)
         VALUES (?, ?, ?)'
    );
    while ($legacyAddOn = $legacyAddOns->fetch_assoc()) {
        $legacyName = $legacyAddOn['option_name'];
        $legacyPrice = (float) $legacyAddOn['additional_price'];
        $legacyMaximum = (int) $legacyAddOn['max_quantity'];
        $addOnInsert->bind_param('sdi', $legacyName, $legacyPrice, $legacyMaximum);
        $addOnInsert->execute();
    }
    $addOnInsert->close();

    $legacyLinks = $connection->query(
        "SELECT old.product_id, addons.addon_id, old.additional_price, old.max_quantity
         FROM product_customization_options old
         INNER JOIN catalog_addons addons ON addons.addon_name = old.option_name
         WHERE old.option_group = 'addon'"
    );
    $linkInsert = $connection->prepare(
        'INSERT IGNORE INTO product_addons
         (product_id, addon_id, additional_price, max_quantity)
         VALUES (?, ?, ?, ?)'
    );
    while ($legacyLink = $legacyLinks->fetch_assoc()) {
        $legacyProductId = (int) $legacyLink['product_id'];
        $legacyAddOnId = (int) $legacyLink['addon_id'];
        $legacyPrice = (float) $legacyLink['additional_price'];
        $legacyMaximum = (int) $legacyLink['max_quantity'];
        $linkInsert->bind_param(
            'iidi',
            $legacyProductId,
            $legacyAddOnId,
            $legacyPrice,
            $legacyMaximum
        );
        $linkInsert->execute();
    }
    $linkInsert->close();
    $connection->query("DELETE FROM product_customization_options WHERE option_group = 'addon'");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $transactionOpen = false;

        try {
            $postedToken = $_POST['csrf_token'] ?? '';
            if (!is_string($postedToken) || !hash_equals($_SESSION['catalog_csrf'], $postedToken)) {
                throw new RuntimeException('Your session token expired. Reload this page and try again.');
            }

            $operation = $_POST['operation'] ?? '';

            if ($operation === 'category_create' || $operation === 'category_update') {
                $categoryNameInput = $_POST['category_name'] ?? '';
                if (!is_string($categoryNameInput)) {
                    throw new RuntimeException('Enter a valid category name.');
                }
                $categoryName = trim($categoryNameInput);
                if ($categoryName === '' || strlen($categoryName) > 100) {
                    throw new RuntimeException('Enter a category name of 1 to 100 characters.');
                }

                if ($operation === 'category_create') {
                    $statement = $connection->prepare(
                        'INSERT INTO categories (category_name) VALUES (?)'
                    );
                    $statement->bind_param('s', $categoryName);
                    $statement->execute();
                    $statement->close();
                    $message = 'Category created.';
                } else {
                    $categoryIdInput = $_POST['category_id'] ?? null;
                    $categoryId = is_string($categoryIdInput) || is_int($categoryIdInput)
                        ? filter_var($categoryIdInput, FILTER_VALIDATE_INT)
                        : false;
                    if (!$categoryId || $categoryId < 1) {
                        throw new RuntimeException('Choose a valid category to update.');
                    }
                    $statement = $connection->prepare(
                        'UPDATE categories SET category_name = ? WHERE category_id = ?'
                    );
                    $statement->bind_param('si', $categoryName, $categoryId);
                    $statement->execute();
                    if ($statement->affected_rows === 0) {
                        $exists = $connection->prepare(
                            'SELECT category_id FROM categories WHERE category_id = ?'
                        );
                        $exists->bind_param('i', $categoryId);
                        $exists->execute();
                        $exists->store_result();
                        $found = $exists->num_rows === 1;
                        $exists->close();
                        if (!$found) {
                            throw new RuntimeException('That category no longer exists.');
                        }
                    }
                    $statement->close();
                    $message = 'Category updated.';
                }
            } elseif ($operation === 'category_delete') {
                $categoryIdInput = $_POST['category_id'] ?? null;
                $categoryId = is_string($categoryIdInput) || is_int($categoryIdInput)
                    ? filter_var($categoryIdInput, FILTER_VALIDATE_INT)
                    : false;
                if (!$categoryId || $categoryId < 1) {
                    throw new RuntimeException('Choose a valid category to delete.');
                }
                $usage = $connection->prepare(
                    'SELECT COUNT(*) FROM products WHERE category_id = ?'
                );
                $usage->bind_param('i', $categoryId);
                $usage->execute();
                $usage->bind_result($productCount);
                $usage->fetch();
                $usage->close();
                if ((int) $productCount > 0) {
                    throw new RuntimeException('Move or delete this category’s products before deleting the category.');
                }
                $statement = $connection->prepare(
                    'DELETE FROM categories WHERE category_id = ?'
                );
                $statement->bind_param('i', $categoryId);
                $statement->execute();
                if ($statement->affected_rows !== 1) {
                    throw new RuntimeException('That category no longer exists.');
                }
                $statement->close();
                $message = 'Category deleted.';
            } elseif ($operation === 'addon_save') {
                $addOnIdInput = $_POST['addon_id'] ?? '0';
                $addOnId = (is_string($addOnIdInput) || is_int($addOnIdInput))
                    ? filter_var($addOnIdInput, FILTER_VALIDATE_INT)
                    : false;
                if ($addOnId === false || $addOnId < 0) {
                    throw new RuntimeException('Choose a valid add-on to save.');
                }
                $creatingAddOn = $addOnId === 0;

                $nameInput = $_POST['addon_name'] ?? '';
                $priceInput = $_POST['addon_price'] ?? '';
                $maximumInput = $_POST['addon_max_quantity'] ?? '';
                if (!is_string($nameInput) || !is_string($priceInput) || !is_string($maximumInput)) {
                    throw new RuntimeException('Enter valid add-on details.');
                }
                $addOnName = trim($nameInput);
                if ($addOnName === '' || strlen($addOnName) > 100) {
                    throw new RuntimeException('Enter an add-on name of 1 to 100 characters.');
                }
                if (!is_numeric($priceInput) || (float) $priceInput < 0 || (float) $priceInput > 99999999.99) {
                    throw new RuntimeException('Enter a valid non-negative add-on price.');
                }
                $addOnPrice = (float) $priceInput;
                $addOnMaxQuantity = filter_var($maximumInput, FILTER_VALIDATE_INT);
                if ($addOnMaxQuantity === false || $addOnMaxQuantity < 1 || $addOnMaxQuantity > 99) {
                    throw new RuntimeException('Add-on quantity limits must be between 1 and 99.');
                }

                $assignedProducts = $_POST['product_ids'] ?? [];
                if (!is_array($assignedProducts)) {
                    throw new RuntimeException('Choose valid drinks for this add-on.');
                }
                $validProductIds = [];
                foreach ($assignedProducts as $assignedProductId) {
                    if (!is_string($assignedProductId) && !is_int($assignedProductId)) {
                        throw new RuntimeException('Choose only listed drinks.');
                    }
                    $validatedProductId = filter_var($assignedProductId, FILTER_VALIDATE_INT);
                    if (!$validatedProductId || $validatedProductId < 1) {
                        throw new RuntimeException('Choose only listed drinks.');
                    }
                    $validProductIds[] = $validatedProductId;
                }
                $validProductIds = array_values(array_unique($validProductIds));
                if ($validProductIds) {
                    $productIdList = implode(',', $validProductIds);
                    $productCheck = $connection->query(
                        'SELECT COUNT(*) AS product_count FROM products WHERE product_id IN (' .
                        $productIdList . ')'
                    );
                    $foundProducts = (int) $productCheck->fetch_assoc()['product_count'];
                    $productCheck->free();
                    if ($foundProducts !== count($validProductIds)) {
                        throw new RuntimeException('One or more selected drinks no longer exist.');
                    }
                }

                $connection->begin_transaction();
                $transactionOpen = true;
                if ($addOnId > 0) {
                    $saveAddOn = $connection->prepare(
                        'UPDATE catalog_addons SET addon_name = ?, additional_price = ?, max_quantity = ?
                         WHERE addon_id = ?'
                    );
                    $saveAddOn->bind_param('sdii', $addOnName, $addOnPrice, $addOnMaxQuantity, $addOnId);
                    $saveAddOn->execute();
                    if ($saveAddOn->affected_rows === 0) {
                        $exists = $connection->prepare('SELECT addon_id FROM catalog_addons WHERE addon_id = ?');
                        $exists->bind_param('i', $addOnId);
                        $exists->execute();
                        $exists->store_result();
                        $found = $exists->num_rows === 1;
                        $exists->close();
                        if (!$found) {
                            throw new RuntimeException('That add-on no longer exists.');
                        }
                    }
                    $saveAddOn->close();
                } else {
                    $saveAddOn = $connection->prepare(
                        'INSERT INTO catalog_addons (addon_name, additional_price, max_quantity)
                         VALUES (?, ?, ?)'
                    );
                    $saveAddOn->bind_param('sdi', $addOnName, $addOnPrice, $addOnMaxQuantity);
                    $saveAddOn->execute();
                    $addOnId = (int) $connection->insert_id;
                    $saveAddOn->close();
                }

                $clearAssignments = $connection->prepare('DELETE FROM product_addons WHERE addon_id = ?');
                $clearAssignments->bind_param('i', $addOnId);
                $clearAssignments->execute();
                $clearAssignments->close();
                $assignAddOn = $connection->prepare(
                    'INSERT INTO product_addons
                     (product_id, addon_id, additional_price, max_quantity)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ($validProductIds as $assignedProductId) {
                    $assignAddOn->bind_param(
                        'iidi',
                        $assignedProductId,
                        $addOnId,
                        $addOnPrice,
                        $addOnMaxQuantity
                    );
                    $assignAddOn->execute();
                }
                $assignAddOn->close();
                $connection->commit();
                $transactionOpen = false;
                $message = $creatingAddOn ? 'Add-on created.' : 'Add-on updated.';
            } elseif ($operation === 'addon_delete') {
                $addOnIdInput = $_POST['addon_id'] ?? null;
                $addOnId = (is_string($addOnIdInput) || is_int($addOnIdInput))
                    ? filter_var($addOnIdInput, FILTER_VALIDATE_INT)
                    : false;
                if (!$addOnId || $addOnId < 1) {
                    throw new RuntimeException('Choose a valid add-on to delete.');
                }
                $deleteAddOn = $connection->prepare('DELETE FROM catalog_addons WHERE addon_id = ?');
                $deleteAddOn->bind_param('i', $addOnId);
                $deleteAddOn->execute();
                if ($deleteAddOn->affected_rows !== 1) {
                    throw new RuntimeException('That add-on no longer exists.');
                }
                $deleteAddOn->close();
                $message = 'Add-on deleted.';
            } elseif ($operation === 'product_save') {
                $productIdInput = $_POST['product_id'] ?? null;
                $productId = is_string($productIdInput) || is_int($productIdInput)
                    ? filter_var($productIdInput, FILTER_VALIDATE_INT)
                    : false;
                if ($productId === false || $productId < 0) {
                    throw new RuntimeException('Invalid product selection.');
                }

                $productNameInput = $_POST['product_name'] ?? '';
                $categoryIdInput = $_POST['category_id'] ?? null;
                $priceValue = $_POST['price'] ?? '';
                if (!is_string($productNameInput)
                    || (!is_string($categoryIdInput) && !is_int($categoryIdInput))
                    || !is_string($priceValue)) {
                    throw new RuntimeException('Enter valid product details.');
                }
                $productName = trim($productNameInput);
                $categoryId = filter_var($categoryIdInput, FILTER_VALIDATE_INT);
                $priceInput = trim($priceValue);
                if ($productName === '' || strlen($productName) > 150) {
                    throw new RuntimeException('Enter a product name of 1 to 150 characters.');
                }
                if (!$categoryId || $categoryId < 1) {
                    throw new RuntimeException('Choose a category for this product.');
                }
                if (!is_numeric($priceInput) || (float) $priceInput < 0 || (float) $priceInput > 99999999.99) {
                    throw new RuntimeException('Enter a valid non-negative price.');
                }
                $price = (float) $priceInput;

                $categoryCheck = $connection->prepare(
                    'SELECT category_id FROM categories WHERE category_id = ?'
                );
                $categoryCheck->bind_param('i', $categoryId);
                $categoryCheck->execute();
                $categoryCheck->store_result();
                $categoryExists = $categoryCheck->num_rows === 1;
                $categoryCheck->close();
                if (!$categoryExists) {
                    throw new RuntimeException('The selected category no longer exists.');
                }

                $selectedOptions = $_POST['customizations'] ?? [];
                if (!is_array($selectedOptions)) {
                    throw new RuntimeException('Invalid customization selection.');
                }
                foreach ($selectedOptions as $selectedOption) {
                    if (!is_string($selectedOption)) {
                        throw new RuntimeException('Choose only listed customization options.');
                    }
                }

                $customSizeNames = $_POST['custom_size_name'] ?? [];
                $customSizePrices = $_POST['custom_size_price'] ?? [];
                if (!is_array($customSizeNames) || !is_array($customSizePrices)) {
                    throw new RuntimeException('Enter valid custom sizes.');
                }
                $customSizeNames = array_values($customSizeNames);
                $customSizePrices = array_values($customSizePrices);
                if (count($customSizeNames) !== count($customSizePrices)) {
                    throw new RuntimeException('Enter a price for each custom size.');
                }

                $sizeNames = ['regular' => true, 'large' => true];
                $customSizeOptionPrices = [];
                foreach ($customSizeNames as $index => $customSizeNameInput) {
                    $customSizePriceInput = $customSizePrices[$index];
                    if (!is_string($customSizeNameInput)
                        || (!is_string($customSizePriceInput) && !is_int($customSizePriceInput))) {
                        throw new RuntimeException('Enter valid custom size names and prices.');
                    }

                    $customSizeName = trim($customSizeNameInput);
                    $customSizePrice = trim((string) $customSizePriceInput);
                    if ($customSizeName === '' && $customSizePrice === '') {
                        continue;
                    }
                    if ($customSizeName === '' || strlen($customSizeName) > 50
                        || strpos($customSizeName, ':') !== false) {
                        throw new RuntimeException('Enter a size name of 1 to 50 characters.');
                    }
                    $normalizedSizeName = strtolower($customSizeName);
                    if (isset($sizeNames[$normalizedSizeName])) {
                        throw new RuntimeException('Size names must be unique for each beverage.');
                    }
                    if (!is_numeric($customSizePrice)
                        || (float) $customSizePrice < 0
                        || (float) $customSizePrice > 99999999.99) {
                        throw new RuntimeException('Enter a valid non-negative price for each custom size.');
                    }

                    $sizeNames[$normalizedSizeName] = true;
                    $optionKey = 'size:' . $customSizeName;
                    $validOptions[$optionKey] = [
                        'group' => 'size',
                        'name' => $customSizeName,
                        'price' => (float) $customSizePrice,
                    ];
                    $customSizeOptionPrices[$optionKey] = $customSizePrice;
                    $selectedOptions[] = $optionKey;
                }

                $selectedOptions = array_values(array_unique($selectedOptions));
                foreach ($selectedOptions as $selectedOption) {
                    if (!isset($validOptions[$selectedOption])) {
                        throw new RuntimeException('Choose only listed customization options.');
                    }
                }
                $optionPrices = $_POST['option_price'] ?? [];
                if (!is_array($optionPrices)) {
                    throw new RuntimeException('Invalid customization prices.');
                }
                $optionPrices = array_merge($optionPrices, $customSizeOptionPrices);
                foreach ($optionPrices as $optionKey => $optionPrice) {
                    if (!is_string($optionKey)
                        || !isset($validOptions[$optionKey])
                        || $validOptions[$optionKey]['group'] !== 'size'
                        || $validOptions[$optionKey]['name'] === 'Regular'
                        || (!is_string($optionPrice) && !is_int($optionPrice))
                        || !is_numeric($optionPrice)
                        || (float) $optionPrice < 0
                        || (float) $optionPrice > 99999999.99) {
                        throw new RuntimeException('Enter valid non-negative prices for size options.');
                    }
                    $validOptions[$optionKey]['price'] = (float) $optionPrice;
                }
                foreach ($selectedOptions as $selectedOption) {
                    if ($validOptions[$selectedOption]['group'] === 'size'
                        && $validOptions[$selectedOption]['name'] !== 'Regular'
                        && !array_key_exists($selectedOption, $optionPrices)) {
                        throw new RuntimeException('Enter a price for every enabled size.');
                    }
                }

                $existingImagePath = null;
                if ($productId > 0) {
                    $existing = $connection->prepare(
                        'SELECT image_path FROM products WHERE product_id = ?'
                    );
                    $existing->bind_param('i', $productId);
                    $existing->execute();
                    $existing->bind_result($existingImagePath);
                    if (!$existing->fetch()) {
                        $existing->close();
                        throw new RuntimeException('That product no longer exists.');
                    }
                    $existing->close();
                }

                $imageData = null;
                $imageMimeType = null;
                $upload = $_FILES['product_image'] ?? null;
                if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
                    if ($upload['error'] === UPLOAD_ERR_INI_SIZE
                        || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
                        throw new RuntimeException('The image exceeds the server upload limit. Choose a smaller image.');
                    }
                    if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 5 * 1024 * 1024) {
                        throw new RuntimeException('Choose an image smaller than 5 MB.');
                    }

                    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $fileInfo->file($upload['tmp_name']);
                    $allowedMimeTypes = [
                        'image/jpeg' => true,
                        'image/png' => true,
                        'image/webp' => true,
                    ];
                    if (!isset($allowedMimeTypes[$mimeType])) {
                        throw new RuntimeException('Upload a JPG, PNG, or WebP image.');
                    }
                    if (!is_uploaded_file($upload['tmp_name'])) {
                        throw new RuntimeException('The product image upload could not be verified.');
                    }
                    $imageData = file_get_contents($upload['tmp_name']);
                    if ($imageData === false) {
                        throw new RuntimeException('The product image could not be read.');
                    }
                    $imageMimeType = $mimeType;
                } elseif ($productId === 0) {
                    throw new RuntimeException('Choose a product image.');
                }

                $connection->begin_transaction();
                $transactionOpen = true;
                if ($productId > 0) {
                    if ($imageData !== null) {
                        $statement = $connection->prepare(
                            'UPDATE products
                             SET category_id = ?, product_name = ?, price = ?, image_path = NULL,
                                 image_data = ?, image_mime_type = ?, availability = 1
                             WHERE product_id = ?'
                        );
                        $statement->bind_param(
                            'isdssi',
                            $categoryId,
                            $productName,
                            $price,
                            $imageData,
                            $imageMimeType,
                            $productId
                        );
                    } else {
                        $statement = $connection->prepare(
                            'UPDATE products
                             SET category_id = ?, product_name = ?, price = ?, availability = 1
                             WHERE product_id = ?'
                        );
                        $statement->bind_param('isdi', $categoryId, $productName, $price, $productId);
                    }
                    $statement->execute();
                    $statement->close();
                    $savedProductId = $productId;
                } else {
                    $statement = $connection->prepare(
                        'INSERT INTO products
                         (category_id, product_name, price, image_path, image_data, image_mime_type, availability)
                         VALUES (?, ?, ?, NULL, ?, ?, 1)'
                    );
                    $statement->bind_param('isdss', $categoryId, $productName, $price, $imageData, $imageMimeType);
                    $statement->execute();
                    $savedProductId = (int) $connection->insert_id;
                    $statement->close();
                }

                $clearOptions = $connection->prepare(
                    'DELETE FROM product_customization_options WHERE product_id = ?'
                );
                $clearOptions->bind_param('i', $savedProductId);
                $clearOptions->execute();
                $clearOptions->close();

                $addOption = $connection->prepare(
                    'INSERT INTO product_customization_options
                     (product_id, option_group, option_name, additional_price, max_quantity)
                     VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($selectedOptions as $selectedOption) {
                    $option = $validOptions[$selectedOption];
                    $maxQuantity = 99;
                    $addOption->bind_param(
                        'issdi',
                        $savedProductId,
                        $option['group'],
                        $option['name'],
                        $option['price'],
                        $maxQuantity
                    );
                    $addOption->execute();
                }
                $addOption->close();
                $connection->commit();
                $transactionOpen = false;

                if ($imageData !== null) {
                    catalog_remove_uploaded_image($existingImagePath, $imagesDirectory);
                }
                $message = $productId > 0 ? 'Product updated.' : 'Product created.';
            } elseif ($operation === 'product_delete') {
                $productIdInput = $_POST['product_id'] ?? null;
                $productId = is_string($productIdInput) || is_int($productIdInput)
                    ? filter_var($productIdInput, FILTER_VALIDATE_INT)
                    : false;
                if (!$productId || $productId < 1) {
                    throw new RuntimeException('Choose a valid product to delete.');
                }
                $existing = $connection->prepare(
                    'SELECT image_path FROM products WHERE product_id = ?'
                );
                $existing->bind_param('i', $productId);
                $existing->execute();
                $existing->bind_result($oldImagePath);
                if (!$existing->fetch()) {
                    $existing->close();
                    throw new RuntimeException('That product no longer exists.');
                }
                $existing->close();

                $statement = $connection->prepare(
                    'DELETE FROM products WHERE product_id = ?'
                );
                $statement->bind_param('i', $productId);
                $statement->execute();
                $statement->close();
                catalog_remove_uploaded_image($oldImagePath, $imagesDirectory);
                $message = 'Product deleted.';
            } else {
                throw new RuntimeException('Choose a valid catalog action.');
            }

            $_SESSION['catalog_flash'] = ['type' => 'success', 'message' => $message];
        } catch (RuntimeException $error) {
            if ($transactionOpen) {
                $connection->rollback();
            }
            $_SESSION['catalog_flash'] = ['type' => 'error', 'message' => $error->getMessage()];
        } catch (mysqli_sql_exception $error) {
            if ($transactionOpen) {
                $connection->rollback();
            }
            error_log('Catalog database error: ' . $error->getMessage());
            if ($operation === 'category_create' || $operation === 'category_update') {
                $message = 'That category name may already exist.';
            } elseif ($operation === 'addon_save') {
                $message = 'That add-on name may already exist, or the changes could not be saved.';
            } elseif ($operation === 'category_delete') {
                $message = 'The category could not be deleted because it is still in use.';
            } elseif ($operation === 'product_delete') {
                $message = 'The product could not be deleted because an order already uses it.';
            } else {
                $message = 'The catalog change could not be saved. Please try again.';
            }
            $_SESSION['catalog_flash'] = ['type' => 'error', 'message' => $message];
        }

        header('Location: ' . $formAction, true, 303);
        exit;
    }

    $categoryResult = $connection->query(
        'SELECT c.category_id, c.category_name, COUNT(p.product_id) AS product_count
         FROM categories c
         LEFT JOIN products p ON p.category_id = c.category_id
         GROUP BY c.category_id, c.category_name
         ORDER BY c.category_name'
    );
    while ($category = $categoryResult->fetch_assoc()) {
        $categories[] = $category;
    }

    $productResult = $connection->query(
        'SELECT p.product_id, p.category_id, p.product_name, p.price, p.image_path,
                p.image_mime_type,
                c.category_name
         FROM products p
         LEFT JOIN categories c ON c.category_id = p.category_id
         ORDER BY c.category_name IS NULL, c.category_name, p.product_name'
    );
    while ($product = $productResult->fetch_assoc()) {
        $product['customizations'] = [];
        $product['option_prices'] = [];
        $product['addons'] = [];
        $products[(int) $product['product_id']] = $product;
    }
    if ($products) {
        $optionResult = $connection->query(
            'SELECT product_id, option_group, option_name, additional_price, max_quantity
             FROM product_customization_options
             ORDER BY product_customization_option_id'
        );
        while ($option = $optionResult->fetch_assoc()) {
            $productId = (int) $option['product_id'];
            if (isset($products[$productId])) {
                $products[$productId]['customizations'][] =
                    $option['option_group'] . ':' . $option['option_name'];
                $products[$productId]['option_prices'][$option['option_group'] . ':' . $option['option_name']] =
                    (float) $option['additional_price'];
            }
        }
    }
    $addOnResult = $connection->query(
        'SELECT addon_id, addon_name, additional_price, max_quantity
         FROM catalog_addons ORDER BY addon_name'
    );
    while ($addOn = $addOnResult->fetch_assoc()) {
        $addOn['products'] = [];
        $addOns[(int) $addOn['addon_id']] = $addOn;
    }
    $assignmentResult = $connection->query(
        'SELECT product_id, addon_id FROM product_addons ORDER BY product_id'
    );
    while ($assignment = $assignmentResult->fetch_assoc()) {
        $productId = (int) $assignment['product_id'];
        $addOnId = (int) $assignment['addon_id'];
        if (isset($products[$productId], $addOns[$addOnId])) {
            $products[$productId]['addons'][] = $addOnId;
            $addOns[$addOnId]['products'][] = $productId;
        }
    }
} catch (mysqli_sql_exception $error) {
    error_log('Catalog page error: ' . $error->getMessage());
    $pageError = 'The catalog could not be loaded. Check the database connection and catalog schema.';
}

if ($connection) {
    $connection->close();
}

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$catalogIcon = static function (string $name): string {
    $paths = [
        'layers' => [
            'M12 2 2 7l10 5 10-5-10-5z',
            'm2 12 10 5 10-5',
            'm2 17 10 5 10-5',
        ],
        'plus' => ['M12 5v14', 'M5 12h14'],
        'sparkles' => [
            'm12 3 1.9 5.8L20 11l-6.1 2.2L12 19l-1.9-5.8L4 11l6.1-2.2L12 3z',
            'M19 14v4',
            'M17 16h4',
        ],
        'coffee' => [
            'M4 8h14v8a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V8z',
            'M18 10h1a3 3 0 0 1 0 6h-1',
            'M2 22h20',
            'M10 2v3',
            'M14 2v3',
        ],
        'trash-2' => [
            'M3 6h18',
            'M8 6V4h8v2',
            'm19 6-1 14H6L5 6',
            'M10 11v5',
            'M14 11v5',
        ],
        'pencil' => [
            'M12 20h9',
            'M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z',
        ],
        'check' => ['m20 6-11 11-5-5'],
    ];

    if (!isset($paths[$name])) {
        throw new InvalidArgumentException('Unknown catalog icon.');
    }

    $iconPaths = implode('', array_map(
        static fn (string $path): string => '<path d="' . $path . '"></path>',
        $paths[$name]
    ));

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
        . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round"'
        . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $iconPaths . '</svg>';
};
?>

<section class="product-management-page">
    <header class="page-header">
        <div>
            <span class="catalog-eyebrow">CATALOG OPERATIONS</span>
            <h1>Menu Management</h1>
            <p class="subtitle">Create categories and manage customer-facing beverages.</p>
        </div>
        <nav class="catalog-shortcuts" aria-label="Menu management sections">
            <a href="#catalog-categories"><?= $catalogIcon('layers') ?>Categories</a>
            <a href="#catalog-create"><?= $catalogIcon('plus') ?>Add beverage</a>
            <a href="#catalog-addons"><?= $catalogIcon('sparkles') ?>Add-ons</a>
            <a href="#catalog-items"><?= $catalogIcon('coffee') ?>Menu items</a>
        </nav>
    </header>

    <div class="catalog-overview" aria-label="Catalog overview">
        <article class="catalog-overview-card">
            <span class="catalog-overview-icon"><?= $catalogIcon('layers') ?></span>
            <span class="catalog-overview-copy">
                <span class="catalog-overview-label">Categories</span>
                <strong><?= count($categories) ?></strong>
            </span>
        </article>
        <article class="catalog-overview-card">
            <span class="catalog-overview-icon"><?= $catalogIcon('coffee') ?></span>
            <span class="catalog-overview-copy">
                <span class="catalog-overview-label">Menu items</span>
                <strong><?= count($products) ?></strong>
            </span>
        </article>
        <article class="catalog-overview-card">
            <span class="catalog-overview-icon"><?= $catalogIcon('sparkles') ?></span>
            <span class="catalog-overview-copy">
                <span class="catalog-overview-label">Add-ons</span>
                <strong><?= count($addOns) ?></strong>
            </span>
        </article>
    </div>

    <?php if ($flash): ?>
        <p class="catalog-message catalog-message--<?= $escape($flash['type']) ?>" role="<?= ($flash['type'] ?? '') === 'error' ? 'alert' : 'status' ?>">
            <?= $escape($flash['message']) ?>
        </p>
    <?php endif; ?>
    <?php if ($pageError !== ''): ?>
        <p class="catalog-message catalog-message--error" role="alert"><?= $escape($pageError) ?></p>
    <?php endif; ?>

    <section class="catalog-panel" id="catalog-categories">
        <h2><?= $catalogIcon('layers') ?>Categories</h2>
        <form class="catalog-form catalog-category-create" method="post" action="<?= $formAction ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['catalog_csrf']) ?>">
            <input type="hidden" name="operation" value="category_create">
            <label>
                Category name
                <input type="text" name="category_name" maxlength="100" required>
            </label>
            <button type="submit" class="catalog-button"><?= $catalogIcon('plus') ?>Add category</button>
        </form>

        <?php if ($categories): ?>
            <div class="catalog-table-wrap">
                <table class="catalog-table">
                    <thead><tr><th>Category</th><th>Products</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $category): ?>
                        <tr>
                            <td>
                                <form class="catalog-form catalog-category-edit" method="post" action="<?= $formAction ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['catalog_csrf']) ?>">
                                    <input type="hidden" name="operation" value="category_update">
                                    <input type="hidden" name="category_id" value="<?= (int) $category['category_id'] ?>">
                                    <input type="text" name="category_name" maxlength="100" value="<?= $escape($category['category_name']) ?>" required>
                                    <button type="submit" class="catalog-button catalog-button--secondary">Save name</button>
                                </form>
                            </td>
                            <td><?= (int) $category['product_count'] ?></td>
                            <td class="catalog-actions">
                                <form class="catalog-form" method="post" action="<?= $formAction ?>" data-confirm="Delete this category?">
                                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['catalog_csrf']) ?>">
                                    <input type="hidden" name="operation" value="category_delete">
                                    <input type="hidden" name="category_id" value="<?= (int) $category['category_id'] ?>">
                                    <button type="submit" class="catalog-button catalog-button--danger catalog-icon-button" aria-label="Delete category <?= $escape($category['category_name']) ?>" title="Delete category">
                                        <?= $catalogIcon('trash-2') ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="catalog-empty">No categories yet. Create one before adding a product.</p>
        <?php endif; ?>
    </section>

    <section class="catalog-panel" id="catalog-create">
        <h2><?= $catalogIcon('coffee') ?>Add a beverage</h2>
        <?php if (!$categories): ?>
            <p class="catalog-empty">Create a category first to enable product creation.</p>
        <?php else: ?>
            <form class="catalog-form catalog-product-form" method="post" action="<?= $formAction ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['catalog_csrf']) ?>">
                <input type="hidden" name="operation" value="product_save">
                <input type="hidden" name="product_id" value="0">
                <div class="catalog-fields">
                    <label>
                        Product name
                        <input type="text" name="product_name" maxlength="150" required>
                    </label>
                    <label>
                        Category
                        <select name="category_id" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['category_id'] ?>"><?= $escape($category['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Price (₱)
                        <input type="number" name="price" min="0" max="99999999.99" step="0.01" required>
                    </label>
                    <label>
                        Product picture (JPG, PNG, or WebP; max 5 MB)
                        <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp" required>
                    </label>
                </div>
                <fieldset class="catalog-options">
                    <legend>Customizations customers may choose</legend>
                    <?php foreach ($optionChoices as $group => $choices): ?>
                        <div class="catalog-option-group" data-customization-group>
                            <strong><?= $escape(ucfirst($group)) ?></strong>
                            <label class="catalog-select-all">
                                <input type="checkbox" data-select-all-customizations>
                                Select all <?= $escape($group) ?> options
                            </label>
                            <div class="catalog-option-list">
                                <?php foreach ($choices as $choice): ?>
                                    <?php $optionKey = $group . ':' . $choice['name']; ?>
                                    <div class="catalog-option-row">
                                        <label class="catalog-option">
                                            <input type="checkbox" name="customizations[]" value="<?= $escape($optionKey) ?>" data-customization-option>
                                            <?= $escape($choice['name']) ?>
                                        </label>
                                        <?php if ($group === 'size' && $choice['name'] !== 'Regular'): ?>
                                            <label class="catalog-option-price">
                                                Extra price each (₱)
                                                <input type="number" name="option_price[<?= $escape($optionKey) ?>]" value="<?= number_format($choice['price'], 2, '.', '') ?>" min="0" max="99999999.99" step="0.01" required>
                                            </label>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($group === 'size'): ?>
                                <div class="catalog-custom-sizes" data-custom-sizes></div>
                                <button type="button" class="catalog-button catalog-button--secondary catalog-add-size" data-add-size>
                                    <?= $catalogIcon('plus') ?>Add another size
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
                <button type="submit" class="catalog-button"><?= $catalogIcon('plus') ?>Create beverage</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="catalog-panel" id="catalog-addons">
        <h2><?= $catalogIcon('sparkles') ?>Add-on management</h2>
        <p class="catalog-option-hint">Create and edit add-ons, then choose which drinks can use each one. Select all drinks to make an add-on available across the whole menu.</p>
        <?php if (!$products): ?>
            <p class="catalog-empty">Add a beverage before assigning add-ons.</p>
        <?php else: ?>
            <form class="catalog-form catalog-addon-form" method="post" action="<?= $formAction ?>">
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['catalog_csrf']) ?>">
                <input type="hidden" name="operation" value="addon_save">
                <input type="hidden" name="addon_id" value="0">
                <div class="catalog-fields">
                    <label>
                        Add-on name
                        <input type="text" name="addon_name" maxlength="100" required>
                    </label>
                    <label>
                        Extra price (₱)
                        <input type="number" name="addon_price" min="0" max="99999999.99" step="0.01" value="0.00" required>
                    </label>
                    <label>
                        Maximum quantity per drink
                        <input type="number" name="addon_max_quantity" min="1" max="99" step="1" value="99" required>
                    </label>
                </div>
                <fieldset class="catalog-options">
                    <legend>Available for drinks</legend>
                    <label class="catalog-select-all catalog-select-all--all">
                        <input type="checkbox" data-select-all-drinks>
                        Select all drinks
                    </label>
                    <div class="catalog-addon-categories">
                        <?php foreach ($categories as $category): ?>
                            <?php
                            $categoryProducts = array_filter(
                                $products,
                                static fn ($product): bool => (int) $product['category_id'] === (int) $category['category_id']
                            );
                            if (!$categoryProducts) {
                                continue;
                            }
                            ?>
                            <details class="catalog-addon-category">
                                <summary>
                                    <span><?= $escape($category['category_name']) ?></span>
                                </summary>
                                <label class="catalog-select-all catalog-select-all--category">
                                    <input type="checkbox" data-select-category>
                                    Select category
                                </label>
                                <div class="catalog-addon-drinks">
                                    <?php foreach ($categoryProducts as $product): ?>
                                        <label class="catalog-option">
                                            <input type="checkbox" name="product_ids[]" value="<?= (int) $product['product_id'] ?>" data-addon-drink>
                                            <?= $escape($product['product_name']) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <button type="submit" class="catalog-button"><?= $catalogIcon('plus') ?>Create add-on</button>
            </form>
        <?php endif; ?>

        <?php if ($addOns): ?>
            <div class="catalog-product-list">
                <?php foreach ($addOns as $addOn): ?>
                    <article class="catalog-product catalog-addon">
                        <div class="catalog-product__summary">
                            <h3><?= $escape($addOn['addon_name']) ?></h3>
                            <p class="catalog-addon-summary">
                                <span>₱<?= number_format((float) $addOn['additional_price'], 2) ?></span>
                                <span>Max <?= (int) $addOn['max_quantity'] ?> per drink</span>
                                <span>Assigned to <?= count($addOn['products']) ?> drink(s)</span>
                            </p>
                        </div>
                        <details class="catalog-product__edit">
                            <summary class="catalog-button catalog-button--secondary"><?= $catalogIcon('pencil') ?>Edit</summary>
                            <form class="catalog-form catalog-addon-form" method="post" action="<?= $formAction ?>">
                                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['catalog_csrf']) ?>">
                                <input type="hidden" name="operation" value="addon_save">
                                <input type="hidden" name="addon_id" value="<?= (int) $addOn['addon_id'] ?>">
                                <div class="catalog-fields">
                                    <label>
                                        Add-on name
                                        <input type="text" name="addon_name" maxlength="100" value="<?= $escape($addOn['addon_name']) ?>" required>
                                    </label>
                                    <label>
                                        Extra price (₱)
                                        <input type="number" name="addon_price" min="0" max="99999999.99" step="0.01" value="<?= number_format((float) $addOn['additional_price'], 2, '.', '') ?>" required>
                                    </label>
                                    <label>
                                        Maximum quantity per drink
                                        <input type="number" name="addon_max_quantity" min="1" max="99" step="1" value="<?= (int) $addOn['max_quantity'] ?>" required>
                                    </label>
                                </div>
                                <fieldset class="catalog-options">
                                    <legend>Available for drinks</legend>
                                    <label class="catalog-select-all catalog-select-all--all">
                                        <input type="checkbox" data-select-all-drinks<?= count($addOn['products']) === count($products) ? ' checked' : '' ?>>
                                        Select all drinks
                                    </label>
                                    <div class="catalog-addon-categories">
                                        <?php foreach ($categories as $category): ?>
                                            <?php
                                            $categoryProducts = array_filter(
                                                $products,
                                                static fn ($product): bool => (int) $product['category_id'] === (int) $category['category_id']
                                            );
                                            if (!$categoryProducts) {
                                                continue;
                                            }
                                            $categoryProductIds = array_map(
                                                static fn ($product): int => (int) $product['product_id'],
                                                $categoryProducts
                                            );
                                            $selectedCategoryCount = count(array_intersect($categoryProductIds, $addOn['products']));
                                            ?>
                                            <details class="catalog-addon-category">
                                                <summary>
                                                    <span><?= $escape($category['category_name']) ?></span>
                                                </summary>
                                                <label class="catalog-select-all catalog-select-all--category">
                                                    <input type="checkbox" data-select-category<?= $selectedCategoryCount === count($categoryProductIds) ? ' checked' : '' ?>>
                                                    Select category
                                                </label>
                                                <div class="catalog-addon-drinks">
                                                    <?php foreach ($categoryProducts as $product): ?>
                                                        <label class="catalog-option">
                                                            <input type="checkbox" name="product_ids[]" value="<?= (int) $product['product_id'] ?>" data-addon-drink<?= in_array((int) $product['product_id'], $addOn['products'], true) ? ' checked' : '' ?>>
                                                            <?= $escape($product['product_name']) ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                                <button type="submit" class="catalog-button"><?= $catalogIcon('check') ?>Save add-on</button>
                            </form>
                            <form class="catalog-form catalog-product-delete" method="post" action="<?= $formAction ?>" data-confirm="Delete this add-on? It will be removed from all assigned drinks.">
                                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['catalog_csrf']) ?>">
                                <input type="hidden" name="operation" value="addon_delete">
                                <input type="hidden" name="addon_id" value="<?= (int) $addOn['addon_id'] ?>">
                                <button type="submit" class="catalog-button catalog-button--danger catalog-icon-button" aria-label="Delete add-on <?= $escape($addOn['addon_name']) ?>" title="Delete add-on">
                                    <?= $catalogIcon('trash-2') ?>
                                </button>
                            </form>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="catalog-empty">No add-ons have been created yet.</p>
        <?php endif; ?>
    </section>

    <section class="catalog-panel" id="catalog-items">
        <h2><?= $catalogIcon('coffee') ?>Menu items</h2>
        <?php if (!$products): ?>
            <p class="catalog-empty">No beverages have been added yet.</p>
        <?php else: ?>
            <div class="catalog-product-list">
                <?php foreach ($products as $product): ?>
                    <?php
                    $productImage = !empty($product['image_mime_type'])
                        ? BREWSKI_BASE_URL . '/customer/product_image.php?id=' . (int) $product['product_id']
                        : '../../images/' . ($product['image_path'] ?: 'brewskilogo.png');
                    ?>
                        <article class="catalog-product">
                        <img
                            src="<?= $escape($productImage) ?>"
                            alt=""
                            class="catalog-product__image"
                        >
                        <div class="catalog-product__summary">
                            <h3><?= $escape($product['product_name']) ?></h3>
                            <p><?= $escape($product['category_name'] ?? 'Category unavailable') ?> · ₱<?= number_format((float) $product['price'], 2) ?></p>
                        </div>
                        <details class="catalog-product__edit">
                            <summary class="catalog-button catalog-button--secondary"><?= $catalogIcon('pencil') ?>Edit</summary>
                            <form class="catalog-form catalog-product-form" method="post" action="<?= $formAction ?>" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['catalog_csrf']) ?>">
                                <input type="hidden" name="operation" value="product_save">
                                <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
                                <div class="catalog-fields">
                                    <label>
                                        Product name
                                        <input type="text" name="product_name" maxlength="150" value="<?= $escape($product['product_name']) ?>" required>
                                    </label>
                                    <label>
                                        Category
                                        <select name="category_id" required>
                                            <?php if (empty($product['category_name'])): ?>
                                                <option value="" selected>Category unavailable - choose a category</option>
                                            <?php endif; ?>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?= (int) $category['category_id'] ?>"<?= (int) $category['category_id'] === (int) $product['category_id'] ? ' selected' : '' ?>>
                                                    <?= $escape($category['category_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>
                                        Price (₱)
                                        <input type="number" name="price" min="0" max="99999999.99" step="0.01" value="<?= $escape($product['price']) ?>" required>
                                    </label>
                                    <label>
                                        Replace picture (optional; JPG, PNG, or WebP; max 5 MB)
                                        <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp">
                                    </label>
                                </div>
                                <fieldset class="catalog-options">
                                    <legend>Customizations customers may choose</legend>
                                    <?php foreach ($optionChoices as $group => $choices): ?>
                                        <?php
                                        $selectedGroupOptions = array_filter(
                                            $product['customizations'],
                                            static fn ($option): bool => strpos($option, $group . ':') === 0
                                        );
                                        $customSizeOptions = $group === 'size'
                                            ? array_map(
                                                static function (string $option) use ($product): array {
                                                    $optionName = substr($option, strlen('size:'));
                                                    return [
                                                        'name' => $optionName,
                                                        'price' => (float) ($product['option_prices'][$option] ?? 0),
                                                    ];
                                                },
                                                array_filter(
                                                    $selectedGroupOptions,
                                                    static fn (string $option): bool => !in_array(
                                                        substr($option, strlen('size:')),
                                                        ['Regular', 'Large'],
                                                        true
                                                    )
                                                )
                                            )
                                            : [];
                                        $selectedChoiceCount = count(array_filter(
                                            $choices,
                                            static fn ($choice): bool => in_array(
                                                $group . ':' . $choice['name'],
                                                $product['customizations'],
                                                true
                                            )
                                        ));
                                        ?>
                                        <div class="catalog-option-group" data-customization-group>
                                            <strong><?= $escape(ucfirst($group)) ?></strong>
                                            <label class="catalog-select-all">
                                                <input type="checkbox" data-select-all-customizations<?= $selectedChoiceCount === count($choices) ? ' checked' : '' ?>>
                                                Select all <?= $escape($group) ?> options
                                            </label>
                                            <div class="catalog-option-list">
                                                <?php foreach ($choices as $choice): ?>
                                                    <?php $optionKey = $group . ':' . $choice['name']; ?>
                                                    <div class="catalog-option-row">
                                                        <label class="catalog-option">
                                                            <input type="checkbox" name="customizations[]" value="<?= $escape($optionKey) ?>" data-customization-option<?= in_array($optionKey, $product['customizations'], true) ? ' checked' : '' ?>>
                                                            <?= $escape($choice['name']) ?>
                                                        </label>
                                                        <?php if ($group === 'size' && $choice['name'] !== 'Regular'): ?>
                                                            <label class="catalog-option-price">
                                                                Extra price each (₱)
                                                                <input type="number" name="option_price[<?= $escape($optionKey) ?>]" value="<?= number_format((float) ($product['option_prices'][$optionKey] ?? $choice['price']), 2, '.', '') ?>" min="0" max="99999999.99" step="0.01" required>
                                                            </label>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php if ($group === 'size'): ?>
                                                <div class="catalog-custom-sizes" data-custom-sizes>
                                                    <?php foreach ($customSizeOptions as $customSize): ?>
                                                        <div class="catalog-custom-size-row">
                                                            <label>
                                                                Size name
                                                                <input type="text" name="custom_size_name[]" maxlength="50" value="<?= $escape($customSize['name']) ?>" required>
                                                            </label>
                                                            <label>
                                                                Extra price (₱)
                                                                <input type="number" name="custom_size_price[]" min="0" max="99999999.99" step="0.01" value="<?= number_format((float) $customSize['price'], 2, '.', '') ?>" required>
                                                            </label>
                                                            <button type="button" class="catalog-button catalog-button--danger" data-remove-size>Remove</button>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <button type="button" class="catalog-button catalog-button--secondary catalog-add-size" data-add-size>
                                                    <?= $catalogIcon('plus') ?>Add another size
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </fieldset>
                                <button type="submit" class="catalog-button"><?= $catalogIcon('check') ?>Save changes</button>
                            </form>
                            <form class="catalog-form catalog-product-delete" method="post" action="<?= $formAction ?>" data-confirm="Delete this beverage? Orders that already contain it will prevent deletion.">
                                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['catalog_csrf']) ?>">
                                <input type="hidden" name="operation" value="product_delete">
                                <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
                                <button type="submit" class="catalog-button catalog-button--danger catalog-icon-button" aria-label="Delete beverage <?= $escape($product['product_name']) ?>" title="Delete beverage">
                                    <?= $catalogIcon('trash-2') ?>
                                </button>
                            </form>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>

<script>
(function () {
    function showCatalogToast(page, message, type) {
        var existingToast = page.querySelector('.catalog-toast');
        if (existingToast) existingToast.remove();

        var toast = document.createElement('div');
        toast.className = 'catalog-toast catalog-toast--' + type;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

        var text = document.createElement('span');
        text.textContent = message;
        toast.appendChild(text);

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'catalog-toast__close';
        closeButton.setAttribute('aria-label', 'Dismiss notification');
        closeButton.textContent = '\u00d7';
        closeButton.addEventListener('click', function () {
            toast.remove();
        });
        toast.appendChild(closeButton);
        page.appendChild(toast);

        if (type === 'success') {
            window.setTimeout(function () {
                toast.remove();
            }, 8000);
        }
    }

    var initialPage = document.querySelector('.product-management-page');
    if (initialPage) {
        initialPage.querySelectorAll('.catalog-message').forEach(function (feedback) {
            showCatalogToast(
                initialPage,
                feedback.textContent.trim(),
                feedback.classList.contains('catalog-message--error') ? 'error' : 'success'
            );
            feedback.remove();
        });
    }

    if (window.brewskiCatalogFormsBound) {
        return;
    }
    window.brewskiCatalogFormsBound = true;

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.product-management-page .catalog-form');
        if (!form) return;
        event.preventDefault();

        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;

        var submitButton = form.querySelector('[type="submit"]');
        if (submitButton) submitButton.disabled = true;

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) throw new Error('The catalog could not be updated. Please try again.');
            return response.text();
        }).then(function (html) {
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var updatedPage = parsed.querySelector('.product-management-page');
            var currentPage = document.querySelector('.product-management-page');
            if (!updatedPage || !currentPage) {
                throw new Error('The server returned an unexpected catalog response.');
            }

            var feedback = updatedPage.querySelector('.catalog-message');
            var feedbackMessage = feedback ? feedback.textContent.trim() : '';
            var feedbackType = feedback && feedback.classList.contains('catalog-message--error')
                ? 'error'
                : 'success';
            if (feedback) feedback.remove();
            currentPage.replaceWith(updatedPage);
            if (feedbackMessage) showCatalogToast(updatedPage, feedbackMessage, feedbackType);
        }).catch(function (error) {
            var page = document.querySelector('.product-management-page');
            if (page) {
                showCatalogToast(page, error.message, 'error');
            } else {
                window.alert(error.message);
            }
            if (submitButton) submitButton.disabled = false;
        });
    });

    document.addEventListener('click', function (event) {
        var addSizeButton = event.target.closest('[data-add-size]');
        if (addSizeButton) {
            var sizeGroup = addSizeButton.closest('[data-customization-group]');
            var sizeList = sizeGroup && sizeGroup.querySelector('[data-custom-sizes]');
            if (!sizeList) return;

            var row = document.createElement('div');
            row.className = 'catalog-custom-size-row';
            row.innerHTML =
                '<label>Size name<input type="text" name="custom_size_name[]" maxlength="50" required></label>' +
                '<label>Extra price (₱)<input type="number" name="custom_size_price[]" min="0" max="99999999.99" step="0.01" value="0.00" required></label>' +
                '<button type="button" class="catalog-button catalog-button--danger" data-remove-size>Remove</button>';
            sizeList.appendChild(row);
            row.querySelector('input[type="text"]').focus();
            return;
        }

        var removeSizeButton = event.target.closest('[data-remove-size]');
        if (removeSizeButton) {
            var sizeRow = removeSizeButton.closest('.catalog-custom-size-row');
            if (sizeRow) sizeRow.remove();
        }
    });

    document.addEventListener('change', function (event) {
        var customizationSelectAll = event.target.closest('[data-select-all-customizations]');
        if (customizationSelectAll) {
            var customizationGroup = customizationSelectAll.closest('[data-customization-group]');
            if (!customizationGroup) return;
            customizationGroup.querySelectorAll('[data-customization-option]').forEach(function (checkbox) {
                checkbox.checked = customizationSelectAll.checked;
            });
            return;
        }

        var customizationOption = event.target.closest('[data-customization-option]');
        if (customizationOption) {
            var customizationGroup = customizationOption.closest('[data-customization-group]');
            var customizationSelectAll = customizationGroup && customizationGroup.querySelector('[data-select-all-customizations]');
            if (customizationSelectAll) {
                var customizationCheckboxes = customizationGroup.querySelectorAll('[data-customization-option]');
                var selectedCount = Array.prototype.filter.call(customizationCheckboxes, function (checkbox) {
                    return checkbox.checked;
                }).length;
                customizationSelectAll.checked = customizationCheckboxes.length > 0
                    && selectedCount === customizationCheckboxes.length;
                customizationSelectAll.indeterminate = selectedCount > 0
                    && selectedCount < customizationCheckboxes.length;
            }
            return;
        }

        var selectAll = event.target.closest('[data-select-all-drinks]');
        if (selectAll) {
            var form = selectAll.closest('form');
            if (!form) return;
            form.querySelectorAll('[data-addon-drink]').forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
            form.querySelectorAll('[data-select-category]').forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
                checkbox.indeterminate = false;
            });
            return;
        }

        var selectCategory = event.target.closest('[data-select-category]');
        if (selectCategory) {
            var category = selectCategory.closest('.catalog-addon-category');
            var categoryCheckboxes = category && category.querySelectorAll('[data-addon-drink]');
            if (!categoryCheckboxes) return;
            categoryCheckboxes.forEach(function (checkbox) {
                checkbox.checked = selectCategory.checked;
            });
            selectCategory.indeterminate = false;
            updateAddonSelectAll(category.closest('form'));
            return;
        }

        var drinkCheckbox = event.target.closest('[data-addon-drink]');
        if (!drinkCheckbox) return;

        var addonCategory = drinkCheckbox.closest('.catalog-addon-category');
        var categorySelectAll = addonCategory && addonCategory.querySelector('[data-select-category]');
        if (categorySelectAll) {
            updateGroupSelectAll(categorySelectAll, addonCategory.querySelectorAll('[data-addon-drink]'));
        }
        updateAddonSelectAll(drinkCheckbox.closest('form'));
    });

    function updateGroupSelectAll(selectAll, checkboxes) {
        var selectedCount = Array.prototype.filter.call(checkboxes, function (checkbox) {
            return checkbox.checked;
        }).length;
        selectAll.checked = checkboxes.length > 0 && selectedCount === checkboxes.length;
        selectAll.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
    }

    function updateAddonSelectAll(form) {
        if (!form) return;
        var allDrinks = form.querySelectorAll('[data-addon-drink]');
        var selectAll = form.querySelector('[data-select-all-drinks]');
        if (selectAll) updateGroupSelectAll(selectAll, allDrinks);
        form.querySelectorAll('.catalog-addon-category').forEach(function (category) {
            var categorySelectAll = category.querySelector('[data-select-category]');
            if (categorySelectAll) {
                updateGroupSelectAll(categorySelectAll, category.querySelectorAll('[data-addon-drink]'));
            }
        });
    }

    function refreshCatalogControls() {
        document.querySelectorAll('.product-management-page .catalog-addon-form').forEach(updateAddonSelectAll);
        document.querySelectorAll('.product-management-page [data-customization-group]').forEach(function (group) {
            var selectAll = group.querySelector('[data-select-all-customizations]');
            if (selectAll) updateGroupSelectAll(selectAll, group.querySelectorAll('[data-customization-option]'));
        });
    }
    refreshCatalogControls();
    refreshCatalogControls();
}());
</script>
