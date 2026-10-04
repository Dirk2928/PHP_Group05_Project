<?php

require __DIR__ . '/../partials/bootstrap.php';

$pageTitle = 'Menu | brewski';
$active = 'menu';
$extraStyles = ['menu.css'];

$products = [
    [
        'id' => 1,
        'name' => 'Black Coffee',
        'price' => 170,
        'category' => 'coffee',
        'temp' => 'hot',
        'image' => 'blackcoffee.png'
    ],
    [
        'id' => 2,
        'name' => 'Caramel Macchiato',
        'price' => 180,
        'category' => 'coffee',
        'temp' => 'hot',
        'image' => 'caramel.png'
    ],
    [
        'id' => 3,
        'name' => 'Cafe Latte',
        'price' => 170,
        'category' => 'coffee',
        'temp' => 'hot',
        'image' => 'cafelatte.png'
    ],
    [
        'id' => 4,
        'name' => 'Double Espresso',
        'price' => 150,
        'category' => 'coffee',
        'temp' => 'hot',
        'image' => 'doubleepresso.png'
    ],
    [
        'id' => 5,
        'name' => 'Iced Coffee',
        'price' => 160,
        'category' => 'coffee',
        'temp' => 'iced',
        'image' => 'icedcoffee.png'
    ],

    [
        'id' => 6,
        'name' => 'Matcha Latte',
        'price' => 190,
        'category' => 'non-coffee',
        'temp' => 'hot',
        'image' => 'matchalatte.png'
    ],
    [
        'id' => 7,
        'name' => 'Chocolate Drink',
        'price' => 175,
        'category' => 'non-coffee',
        'temp' => 'hot',
        'image' => 'mocha.png'
    ],
    [
        'id' => 8,
        'name' => 'Strawberry Milk',
        'price' => 185,
        'category' => 'non-coffee',
        'temp' => 'iced',
        'image' => 'cafelatte.png'
    ],

    [
        'id' => 9,
        'name' => 'Caramel Frappe',
        'price' => 210,
        'category' => 'frappe',
        'temp' => 'iced',
        'image' => 'caramel.png'
    ],
    [
        'id' => 10,
        'name' => 'Mocha Frappe',
        'price' => 205,
        'category' => 'frappe',
        'temp' => 'iced',
        'image' => 'mocha.png'
    ],
    [
        'id' => 11,
        'name' => 'Matcha Frappe',
        'price' => 215,
        'category' => 'frappe',
        'temp' => 'iced',
        'image' => 'blackcoffee.png'
    ],

    [
        'id' => 12,
        'name' => 'Classic Milk Tea',
        'price' => 160,
        'category' => 'tea',
        'temp' => 'iced',
        'image' => 'icedcoffee.png'
    ],
    [
        'id' => 13,
        'name' => 'Peach Iced Tea',
        'price' => 150,
        'category' => 'tea',
        'temp' => 'iced',
        'image' => 'caramel.png'
    ],
    [
        'id' => 14,
        'name' => 'Hot Green Tea',
        'price' => 140,
        'category' => 'tea',
        'temp' => 'hot',
        'image' => 'matchalatte.png'
    ],
];

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
                    <button class="filter-btn" data-filter="coffee">Coffee</button>
                    <button class="filter-btn" data-filter="non-coffee">Non-Coffee</button>
                    <button class="filter-btn" data-filter="frappe">Frappe</button>
                    <button class="filter-btn" data-filter="tea">Tea</button>

                </div>

            </div>

            <div class="filter-group">

                <h3>Temperature</h3>

                <div
                    class="filter-buttons"
                    id="temp-filters"
                >

                    <button class="filter-btn active" data-filter="all">All</button>
                    <button class="filter-btn" data-filter="hot">Hot</button>
                    <button class="filter-btn" data-filter="iced">Iced</button>

                </div>

            </div>

        </section>

        <section class="category">

            <div
                class="product-grid"
                id="menu-grid"
            >

                <?php foreach ($products as $product): ?>

                    <?php $image = '../../images/' . $product['image']; ?>

                    <div
                        class="product-card"
                        data-id="<?= (int) $product['id'] ?>"
                        data-name="<?= e($product['name']) ?>"
                        data-image="<?= e($image) ?>"
                        data-base-price="<?= (int) $product['price'] ?>"
                        data-category="<?= e($product['category']) ?>"
                        data-temp="<?= e($product['temp']) ?>"
                    >

                        <div class="product-card__image">

                            <img
                                src="<?= e($image) ?>"
                                alt="<?= e($product['name']) ?>"
                            >

                        </div>

                        <div class="product-card__info">

                            <h3><?= e($product['name']) ?></h3>

                            <p class="price">₱<?= (int) $product['price'] ?></p>

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

                <p>No drinks found matching your filters.</p>

            </div>

        </section>

    </main>

<?php require __DIR__ . '/../partials/modal.php'; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
