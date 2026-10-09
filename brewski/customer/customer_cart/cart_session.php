<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';

header('Content-Type: application/json; charset=utf-8');

function cart_session_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    cart_session_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if (empty($_SESSION['user_id'])) {
    cart_session_respond(401, ['ok' => false, 'error' => 'Sign in to sync your cart.']);
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (
    !is_string($token)
    || empty($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $token)
) {
    cart_session_respond(403, ['ok' => false, 'error' => 'Invalid session token.']);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
    cart_session_respond(422, ['ok' => false, 'error' => 'Invalid cart data.']);
}

if (count($payload['items']) > 100) {
    cart_session_respond(422, ['ok' => false, 'error' => 'Your cart has too many different items.']);
}

$userId = (int) $_SESSION['user_id'];
$items = $payload['items'];

try {
    $pdo->beginTransaction();

    $cartLookup = $pdo->prepare(
        'SELECT cart_id FROM carts
         WHERE user_id = ?
         ORDER BY cart_id ASC
         LIMIT 1
         FOR UPDATE'
    );
    $cartLookup->execute([$userId]);
    $cartId = $cartLookup->fetchColumn();

    if (!$cartId) {
        $createCart = $pdo->prepare('INSERT INTO carts (user_id) VALUES (?)');
        $createCart->execute([$userId]);
        $cartId = (int) $pdo->lastInsertId();
    }

    $productById = $pdo->prepare(
        'SELECT product_id, product_name, price
         FROM products
         WHERE product_id = ? AND availability = 1'
    );
    $productByName = $pdo->prepare(
        'SELECT product_id, product_name, price
         FROM products
         WHERE product_name = ? AND availability = 1
         LIMIT 2'
    );
    $sizeLookup = $pdo->prepare('SELECT size_id FROM sizes WHERE size_name = ? LIMIT 1');
    $ensureSize = $pdo->prepare('INSERT IGNORE INTO sizes (size_name) VALUES (?)');
    $sizeOptionCount = $pdo->prepare(
        'SELECT COUNT(*)
         FROM product_customization_options
         WHERE product_id = ? AND option_group = \'size\''
    );
    $sizeOptionLookup = $pdo->prepare(
        'SELECT additional_price
         FROM product_customization_options
         WHERE product_id = ? AND option_group = \'size\' AND option_name = ?
         LIMIT 1'
    );
    $otherOptionLookup = $pdo->prepare(
        'SELECT option_name
         FROM product_customization_options
         WHERE product_id = ? AND option_group = ? AND option_name = ?
         LIMIT 1'
    );
    $addonLookup = $pdo->prepare(
        'SELECT COALESCE(assignment.additional_price, addon.additional_price) AS additional_price,
                COALESCE(assignment.max_quantity, addon.max_quantity) AS max_quantity
         FROM product_addons assignment
         INNER JOIN catalog_addons addon ON addon.addon_id = assignment.addon_id
         WHERE assignment.product_id = ? AND addon.addon_name = ?
         LIMIT 1'
    );
    $legacyAddonLookup = $pdo->prepare(
        'SELECT additional_price, max_quantity
         FROM product_customization_options
         WHERE product_id = ? AND option_group = \'addon\' AND option_name = ?
         LIMIT 1'
    );
    $insertItem = $pdo->prepare(
        'INSERT INTO cart_items (cart_id, product_id, size_id, quantity, unit_price)
         VALUES (?, ?, ?, ?, ?)'
    );

    $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([$cartId]);

    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('A cart item is invalid.');
        }

        $productId = filter_var($item['productId'] ?? 0, FILTER_VALIDATE_INT);
        $productName = trim((string) ($item['name'] ?? ''));

        if ($productId && $productId > 0) {
            $productById->execute([$productId]);
            $product = $productById->fetch();
        } elseif ($productName !== '') {
            $productByName->execute([$productName]);
            $matches = $productByName->fetchAll();
            if (count($matches) > 1) {
                throw new InvalidArgumentException('A cart item has an ambiguous product name. Refresh the menu and try again.');
            }
            $product = $matches[0] ?? false;
        } else {
            $product = false;
        }

        if (!$product) {
            throw new InvalidArgumentException('A cart item is no longer available. Refresh the menu and try again.');
        }

        $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 1 || $quantity > 99) {
            throw new InvalidArgumentException('Cart quantities must be between 1 and 99.');
        }

        $customizations = $item['customizationOptions'] ?? [];
        if (!is_array($customizations)) {
            throw new InvalidArgumentException('A cart item has invalid customization data.');
        }

        $selectedSize = trim((string) ($customizations['size'] ?? $item['size'] ?? ''));
        if ($selectedSize === '') {
            $selectedSize = 'Regular';
        }
        $unitPrice = (float) $product['price'];

        $sizeOptionCount->execute([(int) $product['product_id']]);
        $hasSizeOptions = (int) $sizeOptionCount->fetchColumn() > 0;
        $sizeOptionLookup->execute([(int) $product['product_id'], $selectedSize]);
        $sizePrice = $sizeOptionLookup->fetchColumn();
        if ($hasSizeOptions && $sizePrice === false) {
            throw new InvalidArgumentException('The selected drink size is no longer available.');
        }
        if ($sizePrice !== false) {
            $unitPrice += (float) $sizePrice;
        }

        $ensureSize->execute([$selectedSize]);
        $sizeLookup->execute([$selectedSize]);
        $sizeId = $sizeLookup->fetchColumn();
        if (!$sizeId) {
            throw new RuntimeException('The selected drink size could not be saved.');
        }

        foreach (['temperature', 'sugar'] as $optionGroup) {
            $optionName = trim((string) ($customizations[$optionGroup] ?? ''));
            if ($optionName === '') {
                continue;
            }
            $otherOptionLookup->execute([
                (int) $product['product_id'],
                $optionGroup,
                $optionName,
            ]);
            if (!$otherOptionLookup->fetchColumn()) {
                throw new InvalidArgumentException('A selected customization is no longer available.');
            }
        }

        $addons = $customizations['addons'] ?? [];
        if (!is_array($addons)) {
            throw new InvalidArgumentException('A cart item has invalid add-on data.');
        }

        foreach ($addons as $addon) {
            if (!is_array($addon)) {
                throw new InvalidArgumentException('A cart item has invalid add-on data.');
            }
            $addonName = trim((string) ($addon['name'] ?? ''));
            $addonQuantity = filter_var($addon['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($addonName === '' || $addonQuantity === false || $addonQuantity < 1) {
                throw new InvalidArgumentException('A cart item has invalid add-on data.');
            }

            $addonLookup->execute([(int) $product['product_id'], $addonName]);
            $addonPrice = $addonLookup->fetch();
            if (!$addonPrice) {
                $legacyAddonLookup->execute([(int) $product['product_id'], $addonName]);
                $addonPrice = $legacyAddonLookup->fetch();
            }
            if (!$addonPrice || $addonQuantity > (int) $addonPrice['max_quantity']) {
                throw new InvalidArgumentException('A selected add-on is no longer available in that quantity.');
            }

            $unitPrice += (float) $addonPrice['additional_price'] * $addonQuantity;
        }

        $insertItem->execute([
            (int) $cartId,
            (int) $product['product_id'],
            (int) $sizeId,
            $quantity,
            number_format($unitPrice, 2, '.', ''),
        ]);
    }

    $pdo->commit();
    cart_session_respond(200, ['ok' => true, 'items' => count($items)]);
} catch (InvalidArgumentException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cart_session_respond(422, ['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Customer cart synchronization failed: ' . $error->getMessage());
    cart_session_respond(500, ['ok' => false, 'error' => 'Could not save your cart. Please try again.']);
}
