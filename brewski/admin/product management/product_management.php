<?php

require_once __DIR__ . '/../../login-signup/session_init.php';

brewski_require_role(['ADMIN']);

?>

<section class="product-management-page">
    <div class="page-header">
        <div>
            <h1>Product Management</h1>
            <p class="subtitle">Manage the Brewski menu catalog.</p>
        </div>

        <div class="toolbar-actions" aria-label="product actions">
            <button type="button" class="btn-toolbar btn-add">Add Product</button>
            <button type="button" class="btn-toolbar btn-delete">Delete Product</button>
        </div>
    </div>

    <section class="category">
        <h2 class="category__title">Best sellers</h2>
        <div class="product-grid">
            <article class="product-card">
                <div class="product-card__image" aria-label="Black Coffee"></div>
                <div class="product-card__info">
                    <h3>Black Coffee</h3>
                    <p class="price">₱170</p>
                    <button type="button" class="btn-edit">Edit</button>
                </div>
            </article>

            <article class="product-card">
                <div class="product-card__image" aria-label="Caramel Macchiato"></div>
                <div class="product-card__info">
                    <h3>Caramel Macchiato</h3>
                    <p class="price">₱180</p>
                    <button type="button" class="btn-edit">Edit</button>
                </div>
            </article>

            <article class="product-card">
                <div class="product-card__image" aria-label="Cafe Latte"></div>
                <div class="product-card__info">
                    <h3>Cafe Latte</h3>
                    <p class="price">₱170</p>
                    <button type="button" class="btn-edit">Edit</button>
                </div>
            </article>

            <article class="product-card">
                <div class="product-card__image" aria-label="Mocha"></div>
                <div class="product-card__info">
                    <h3>Vanilla Mocha</h3>
                    <p class="price">₱185</p>
                    <button type="button" class="btn-edit">Edit</button>
                </div>
            </article>
        </div>
    </section>

    <section class="category">
        <h2 class="category__title">Most Popular</h2>
        <div class="product-grid">
            <article class="product-card">
                <div class="product-card__image" aria-label="Iced Coffee"></div>
                <div class="product-card__info">
                    <h3>Iced Coffee</h3>
                    <p class="price">₱160</p>
                    <button type="button" class="btn-edit">Edit</button>
                </div>
            </article>

            <article class="product-card">
                <div class="product-card__image" aria-label="Cafe Mocha"></div>
                <div class="product-card__info">
                    <h3>Cafe Mocha</h3>
                    <p class="price">₱185</p>
                    <button type="button" class="btn-edit">Edit</button>
                </div>
            </article>

            <article class="product-card">
                <div class="product-card__image" aria-label="Double Espresso"></div>
                <div class="product-card__info">
                    <h3>Double Espresso</h3>
                    <p class="price">₱150</p>
                    <button type="button" class="btn-edit">Edit</button>
                </div>
            </article>

            <article class="product-card">
                <div class="product-card__image" aria-label="Matcha Latte"></div>
                <div class="product-card__info">
                    <h3>Matcha Latte</h3>
                    <p class="price">₱190</p>
                    <button type="button" class="btn-edit">Edit</button>
                </div>
            </article>
        </div>
    </section>
</section>
