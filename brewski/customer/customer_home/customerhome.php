<?php

require __DIR__ . '/../partials/bootstrap.php';

$pageTitle = 'Home | brewski';
$active = 'home';

/**
 * The curated rows on the landing page. Cards are rendered from these instead
 * of being hand-written, so every card carries the same data-* attributes the
 * modal and cart expect (data-image in particular — the old hand-written cards
 * had no image, so cart thumbnails came out broken).
 */
$bestSellers = [
    ['name' => 'Black Coffee',      'price' => 170, 'temp' => 'hot',  'image' => 'blackcoffee.png'],
    ['name' => 'Caramel Macchiato', 'price' => 180, 'temp' => 'hot',  'image' => 'caramel.png'],
    ['name' => 'Cafe Latte',        'price' => 170, 'temp' => 'hot',  'image' => 'cafelatte.png'],

    // NOTE: duplicate of the first entry, price and image included. Looks like
    // a copy/paste slip rather than a fourth drink — left in place because
    // removing it changes the row's contents. Confirm before deleting.
    ['name' => 'Black Coffee',      'price' => 170, 'temp' => 'hot',  'image' => 'blackcoffee.png'],
];

$mostPopular = [
    ['name' => 'Iced Coffee',     'price' => 160, 'temp' => 'iced', 'image' => 'icedcoffee.png'],
    ['name' => 'Cafe Mocha',      'price' => 185, 'temp' => 'hot',  'image' => 'mocha.png'],
    ['name' => 'Double Espresso', 'price' => 150, 'temp' => 'hot',  'image' => 'doubleepresso.png'],
    ['name' => 'Matcha Latte',    'price' => 190, 'temp' => 'hot',  'image' => 'matchalatte.png'],
];

$sections = [
    'Best sellers' => $bestSellers,
    'Most Popular' => $mostPopular,
];

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
                aria-label="Help"
            >
                <i data-lucide="help-circle"></i>
            </button>

        </section>

        <?php foreach ($sections as $title => $products): ?>

            <section class="category">

                <h2 class="category__title">
                    <?= e($title) ?>
                </h2>

                <div class="product-grid">

                    <?php foreach ($products as $product): ?>

                        <?php $image = '../../images/' . $product['image']; ?>

                        <div
                            class="product-card"
                            data-name="<?= e($product['name']) ?>"
                            data-image="<?= e($image) ?>"
                            data-base-price="<?= (int) $product['price'] ?>"
                            data-temp="<?= e($product['temp']) ?>"
                        >

                            <div class="product-card__image">

                                <img
                                    src="<?= e($image) ?>"
                                    alt="<?= e($product['name']) ?>"
                                >

                            </div>

                            <div class="product-card__info">

                                <h3>
                                    <?= e($product['name']) ?>
                                </h3>

                                <p class="price">
                                    ₱<?= (int) $product['price'] ?>
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

<?php require __DIR__ . '/../partials/footer.php'; ?>
