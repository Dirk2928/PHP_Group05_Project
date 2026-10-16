<?php

?>

    <p
        style="
            margin: 0;
            padding: 1rem 1.5rem 1.5rem;
            text-align: center;
            font-size: 0.8rem;
            color: var(--mocha, #71492a);
        "
    >

        <a
            href="<?php echo htmlspecialchars(BREWSKI_BASE_URL . '/cookie-consent/privacy.php', ENT_QUOTES, 'UTF-8'); ?>"
            style="color: inherit;"
        >
            Privacy Policy
        </a>

    </p>

    <?php require_once __DIR__ . '/../../cookie-consent/cookie-consent.php'; ?>

    <script src="../customer_cart/cart.js"></script>
    <script src="../script.js"></script>
    <script src="../partials/choice.js"></script>

</body>

</html>
