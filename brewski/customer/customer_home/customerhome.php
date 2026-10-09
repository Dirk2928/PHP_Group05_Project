<?php

require __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../partials/catalog.php';
require_once __DIR__ . '/../partials/preferences.php';

$pageTitle = 'Home | brewski';
$active = 'home';
$extraStyles = ['choice.css'];

$catalogError = '';
$sections = [];
$choicePreferences = null;

try {
    $catalog = brewski_load_catalog();
    foreach ($catalog['products'] as $product) {
        $sections[$product['category_name']][] = $product;
    }
    brewski_preference_sync($catalog);
} catch (mysqli_sql_exception $error) {
    error_log('Customer home catalog error: ' . $error->getMessage());
    $catalogError = 'The menu is temporarily unavailable. Please try again later.';
}

$choiceUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($choiceUserId > 0) {
    $choicePreferences = brewski_user_preferences($choiceUserId);
}

$choiceValues = is_array($choicePreferences)
    ? brewski_answers_from_preferences($choicePreferences)
    : [];
$choiceAutoOpen = $choiceUserId > 0
    && is_array($choicePreferences)
    && count($choicePreferences) === 0;

require __DIR__ . '/../partials/header.php';

?>

    <main class="main-content">

        <section class="greeting">

            <div class="greeting__text">

                <h1>
                    Order Up, Bro <?= $display_first_name ?>!
                </h1>

                <p>
                    New to Brewski? Let our platform recommend
                    your preferred drinks!
                    <br>
                    Click the question mark button.
                </p>

            </div>

            <button
                type="button"
                class="greeting__help"
                id="choice-open"
                aria-label="Help"
            >
                <i data-lucide="help-circle"></i>
            </button>

        </section>

        <?php if ($catalogError !== ''): ?>
            <p class="catalog-notice" role="alert"><?= e($catalogError) ?></p>
        <?php elseif (!$sections): ?>
            <p class="catalog-notice">Our beverage menu is being prepared. Please check back soon.</p>
        <?php endif; ?>

        <?php foreach ($sections as $title => $products): ?>

            <section class="category">

                <h2 class="category__title">
                    <?= e($title) ?>
                </h2>

                <div class="product-grid">

                    <?php foreach ($products as $product): ?>

                        <?php
                        $image = !empty($product['image_mime_type'])
                            ? BREWSKI_BASE_URL . '/customer/product_image.php?id=' . (int) $product['product_id']
                            : '../../images/' . ($product['image_path'] ?: 'brewskilogo.png');
                        $customizations = json_encode(
                            $product['customizations'],
                            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP
                        );
                        ?>

                        <div
                            class="product-card"
                            data-name="<?= e($product['product_name']) ?>"
                            data-image="<?= e($image) ?>"
                            data-base-price="<?= e($product['price']) ?>"
                            data-options="<?= e($customizations) ?>"
                        >

                            <div class="product-card__image">

                                <img
                                    src="<?= e($image) ?>"
                                    alt="<?= e($product['product_name']) ?>"
                                >

                            </div>

                            <div class="product-card__info">

                                <h3>
                                    <?= e($product['product_name']) ?>
                                </h3>

                                <p class="price">
                                    ₱<?= number_format((float) $product['price'], 2) ?>
                                </p>

                                <button
                                    type="button"
                                    class="btn-add"
                                >

                                    <i data-lucide="plus-circle"></i>

                                    Add to Cart

                                </button>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            </section>

        <?php endforeach; ?>

    </main>

<?php require __DIR__ . '/../partials/modal.php'; ?>

<?php require __DIR__ . '/../partials/choice-modal.php'; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
