<?php

function brewski_load_catalog(): array
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new mysqli('localhost', 'root', '', 'brewski_db');
    $connection->set_charset('utf8mb4');

    try {
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
        $maxQuantityColumn = $connection->query(
            "SHOW COLUMNS FROM product_customization_options LIKE 'max_quantity'"
        );
        if ($maxQuantityColumn->num_rows === 0) {
            $connection->query(
                'ALTER TABLE product_customization_options
                 ADD COLUMN max_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 99'
            );
        }
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
            $linkInsert->bind_param('iidi', $legacyProductId, $legacyAddOnId, $legacyPrice, $legacyMaximum);
            $linkInsert->execute();
        }
        $linkInsert->close();
        $connection->query("DELETE FROM product_customization_options WHERE option_group = 'addon'");

        $categories = [];
        $categoryResult = $connection->query(
            'SELECT category_id, category_name FROM categories ORDER BY category_name'
        );

        while ($category = $categoryResult->fetch_assoc()) {
            $categories[] = $category;
        }

        $products = [];
        $productResult = $connection->query(
            'SELECT p.product_id, p.category_id, c.category_name, p.product_name,
                    p.price, p.image_path
             FROM products p
             INNER JOIN categories c ON c.category_id = p.category_id
             WHERE p.availability = 1
             ORDER BY c.category_name, p.product_name'
        );

        while ($product = $productResult->fetch_assoc()) {
            $product['customizations'] = [];
            $products[(int) $product['product_id']] = $product;
        }

        if ($products) {
            $optionResult = $connection->query(
                'SELECT product_id, option_group, option_name, additional_price, max_quantity
                 FROM product_customization_options
                 WHERE option_group <> \'addon\'
                 ORDER BY product_id, product_customization_option_id'
            );

            while ($option = $optionResult->fetch_assoc()) {
                $productId = (int) $option['product_id'];
                if (isset($products[$productId])) {
                    $products[$productId]['customizations'][] = [
                        'group' => $option['option_group'],
                        'name' => $option['option_name'],
                        'price' => (float) $option['additional_price'],
                        'maxQuantity' => (int) $option['max_quantity'],
                    ];
                }
            }

            $addOnResult = $connection->query(
                'SELECT assignments.product_id, addons.addon_name,
                        assignments.additional_price, assignments.max_quantity
                 FROM product_addons assignments
                 INNER JOIN catalog_addons addons ON addons.addon_id = assignments.addon_id
                 INNER JOIN products products ON products.product_id = assignments.product_id
                 WHERE products.availability = 1
                 ORDER BY assignments.product_id, addons.addon_name'
            );
            while ($addOn = $addOnResult->fetch_assoc()) {
                $productId = (int) $addOn['product_id'];
                if (isset($products[$productId])) {
                    $products[$productId]['customizations'][] = [
                        'group' => 'addon',
                        'name' => $addOn['addon_name'],
                        'price' => (float) $addOn['additional_price'],
                        'maxQuantity' => (int) $addOn['max_quantity'],
                    ];
                }
            }
        }

        return [
            'categories' => $categories,
            'products' => array_values($products),
        ];
    } finally {
        $connection->close();
    }
}
