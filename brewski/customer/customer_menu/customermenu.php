<?php

require __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../partials/catalog.php';

$pageTitle = 'Menu | brewski';
$active = 'menu';
$extraStyles = ['menu.css'];

$catalogError = '';
$categories = [];
$products = [];
try {
    $catalog = brewski_load_catalog();
    $categories = $catalog['categories'];
    $products = $catalog['products'];
} catch (mysqli_sql_exception $error) {
    error_log('Customer menu catalog error: ' . $error->getMessage());
    $catalogError = 'The menu is temporarily unavailable. Please try again later.';
}

require __DIR__ . '/../partials/header.php';

?>

    <main class="main-content">

        <section class="page-header">

            <div>

                <h1>Our Menu</h1>

                <p>Find your perfect brew from our selection.</p>

            </div>

        </section>

        <section class="filter-section">

            <div class="filter-group">

                <h3>Category</h3>

                <div
                    class="filter-buttons"
                    id="category-filters"
                >

                    <button class="filter-btn active" data-filter="all">All</button>
                    <?php foreach ($categories as $category): ?>
                        <button class="filter-btn" data-filter="<?= (int) $category['category_id'] ?>">
                            <?= e($category['category_name']) ?>
                        </button>
                    <?php endforeach; ?>

                </div>

            </div>

        </section>

        <?php if ($catalogError !== ''): ?>
            <p class="catalog-notice" role="alert"><?= e($catalogError) ?></p>
        <?php endif; ?>

        <section class="category">

            <div
                class="product-grid"
                id="menu-grid"
            >

                <?php foreach ($products as $product): ?>

                    <?php
                    $image = '../../images/' . ($product['image_path'] ?: 'brewskilogo.png');
                    $customizations = json_encode(
                        $product['customizations'],
                        JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP
                    );
                    ?>

                    <div
                        class="product-card"
                        data-id="<?= (int) $product['product_id'] ?>"
                        data-name="<?= e($product['product_name']) ?>"
                        data-image="<?= e($image) ?>"
                        data-base-price="<?= e($product['price']) ?>"
                        data-category="<?= (int) $product['category_id'] ?>"
                        data-options="<?= e($customizations) ?>"
                    >

                        <div class="product-card__image">

                            <img
                                src="<?= e($image) ?>"
                                alt="<?= e($product['product_name']) ?>"
                            >

                        </div>

                        <div class="product-card__info">

                            <h3><?= e($product['product_name']) ?></h3>

                            <p class="price">₱<?= number_format((float) $product['price'], 2) ?></p>

                            <button class="btn-add">

                                <i data-lucide="plus-circle"></i> Add to Cart

                            </button>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

            <div
                id="no-results"
                class="no-results"
                style="display: none;"
            >

                <i data-lucide="coffee"></i>

                <p><?= $products ? 'No drinks found matching your filters.' : 'No beverages are available yet.' ?></p>

            </div>

        </section>

    </main>

<?php require __DIR__ . '/../partials/modal.php'; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
