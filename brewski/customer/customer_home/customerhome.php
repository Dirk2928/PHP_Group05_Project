<?php

require __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../partials/catalog.php';
require_once __DIR__ . '/../partials/preferences.php';

$pageTitle = 'Home | brewski';
$active = 'home';
$extraStyles = ['partials/choice.css'];

$catalogError = '';
$sections = [];
$productsById = [];
$choicePreferences = null;
$choiceBestMatches = [];

try {
    $catalog = brewski_load_catalog();
    foreach ($catalog['products'] as $product) {
        $sections[$product['category_name']][] = $product;
        $productsById[(int) $product['product_id']] = $product;
    }
    brewski_preference_sync($catalog);
    $choicePreferences = null;
} catch (mysqli_sql_exception $error) {
    error_log('Customer home catalog error: ' . $error->getMessage());
    $catalogError = 'The menu is temporarily unavailable. Please try again later.';
}

$choiceUserId = (int) ($_SESSION['user_id'] ?? 0);
$choiceFirstTimer = false;
$choiceAssessmentCompleted = null;

if ($choiceUserId > 0) {
    $choicePreferences = brewski_user_preferences($choiceUserId);
    $statusConnection = brewski_preferences_connection();
    if ($statusConnection) {
        try {
            $statusStatement = $statusConnection->prepare(
                'SELECT is_first_timer, assessment_completed_at FROM users WHERE user_id = ?'
            );
            if ($statusStatement) {
                $statusStatement->bind_param('i', $choiceUserId);
                $statusStatement->execute();
                $statusStatement->bind_result($choiceFirstTimer, $choiceAssessmentCompleted);
                $statusStatement->fetch();
                $statusStatement->close();
            } else {
                error_log('Customer home status query could not be prepared: ' . $statusConnection->error);
            }
        } catch (Throwable $error) {
            error_log('Customer home status lookup error: ' . $error->getMessage());
        }
    } else {
        error_log('Customer home status lookup skipped: database connection unavailable.');
    }
    if (is_array($choicePreferences) && $choicePreferences) {
        $choiceRanking = brewski_rank_products(
            $choicePreferences,
            brewski_preference_links($catalog)
        );
        foreach ($choiceRanking as $candidate) {
            if ($candidate['status'] !== 'excluded' && $candidate['percent'] >= 50) {
                $choiceBestMatches[] = $candidate;
            }
        }
        usort($choiceBestMatches, static function (array $left, array $right): int {
            return [$right['percent'], count($right['matched'])]
                <=> [$left['percent'], count($left['matched'])];
        });

        foreach ($sections as $categoryName => $categoryProducts) {
            usort(
                $categoryProducts,
                static function (array $left, array $right) use ($choiceRanking): int {
                    $leftMatch = $choiceRanking[(int) $left['product_id']]['percent'] ?? 0;
                    $rightMatch = $choiceRanking[(int) $right['product_id']]['percent'] ?? 0;

                    return $rightMatch <=> $leftMatch;
                }
            );
            $sections[$categoryName] = $categoryProducts;
        }
    }
}

$choiceValues = is_array($choicePreferences)
    ? brewski_answers_from_preferences($choicePreferences)
    : [];
$choiceFirstTimer = (int) $choiceFirstTimer === 1
    || (empty($choiceAssessmentCompleted)
        && is_array($choicePreferences)
        && count($choicePreferences) === 0);
$choiceAutoOpen = $choiceUserId > 0
    && $choiceFirstTimer
    && empty($choiceAssessmentCompleted)
    && is_array($choicePreferences)
    && count($choicePreferences) === 0
    && empty($_SESSION['choice_skipped']);
$choiceMode = $choiceAutoOpen ? 'first-timer' : 'recommendation';
$choiceRanking = $choiceRanking ?? [];
$renderProductCard = static function (array $product, bool $showMatch = false) use ($choiceRanking): void {
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
        data-id="<?= (int) $product['product_id'] ?>"
        data-name="<?= e($product['product_name']) ?>"
        data-image="<?= e($image) ?>"
        data-base-price="<?= e($product['price']) ?>"
        data-options="<?= e($customizations) ?>"
    >

        <?php if ($showMatch && ($choiceRanking[(int) $product['product_id']]['status'] ?? '') === 'recommended'): ?>
            <span class="product-card__match">Best match</span>
        <?php endif; ?>

        <div class="product-card__image">

            <img
                src="<?= e($image) ?>"
                alt="<?= e($product['product_name']) ?>"
                loading="lazy"
                onerror="this.onerror=null;this.src='<?= e(brewski_product_image_fallback()) ?>';this.classList.add('is-placeholder')"
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
    <?php
};

require __DIR__ . '/../partials/header.php';

?>

    <main class="main-content">

        <section class="greeting">

            <div class="greeting__text">

                <h1>
                    Order Up, Bro <?= $display_first_name ?>!
                </h1>

                <p>
                    Get one drink recommendation or set up your preferences to find your best matches.
                </p>

            </div>

            <button
                type="button"
                class="greeting__help"
                id="choice-open"
                data-choice-mode="first-timer"
                aria-label="Get a drink recommendation"
            >
                <i data-lucide="help-circle"></i>
            </button>

        </section>

        <?php if ($choiceFirstTimer && empty($choiceAssessmentCompleted) && !empty($_SESSION['choice_skipped'])): ?>
            <p class="catalog-notice" role="status">
                Finish your taste profile to organize your menu.
                <button type="button" class="btn-link" id="choice-open-notice" data-choice-mode="recommendation">Find your best matches</button>
            </p>
        <?php endif; ?>

        <?php
        $bestMatchProducts = [];
        foreach ($choiceBestMatches as $candidate) {
            $bestMatchProduct = $productsById[(int) $candidate['product_id']] ?? null;
            if ($bestMatchProduct) {
                $bestMatchProducts[] = $bestMatchProduct;
            }
        }
        ?>
        <?php if ($bestMatchProducts): ?>
            <section class="category category--best-match">
                <h2 class="category__title">Your best matches</h2>
                <div class="product-grid">
                    <?php foreach ($bestMatchProducts as $bestMatchProduct): ?>
                        <?php $renderProductCard($bestMatchProduct, true); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

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
                        <?php $renderProductCard($product); ?>
                    <?php endforeach; ?>

                </div>

            </section>

        <?php endforeach; ?>

    </main>

<?php require __DIR__ . '/../partials/modal.php'; ?>

<?php require __DIR__ . '/../partials/choice-modal.php'; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
