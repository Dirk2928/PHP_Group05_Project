<?php

require_once __DIR__ . '/../login-signup/session_init.php';

$startIsLoggedIn = brewski_is_logged_in();
$startRole = brewski_current_role();
$startHomeUrl = brewski_home_for_role($startRole);
$startIsCustomer = $startRole === 'CUSTOMER';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brewski</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(BREWSKI_BASE_URL . '/images/brewskilogo.png', ENT_QUOTES, 'UTF-8') ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Poppins:wght@500;600;700&family=Questrial&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../customer/styles.css">
    <link rel="stylesheet" href="start.css">

    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <header class="topbar">
        <div class="topbar__brand">
            <img
                class="topbar__logo"
                src="../images/brewskilogo.png"
                alt="Brewski Logo"
            >
            brewski
        </div>

        <nav class="topbar__nav">
            <?php if ($startIsLoggedIn): ?>
            <a href="<?= htmlspecialchars($startHomeUrl, ENT_QUOTES, 'UTF-8') ?>" class="topbar__link">
                <i data-lucide="home"></i>
                <?= $startIsCustomer ? 'Home' : 'Dashboard' ?>
            </a>
            <?php endif; ?>
            <?php if (!$startIsLoggedIn || $startIsCustomer): ?>
            <a
                href="../customer/customer_menu/customermenu.php"
                class="topbar__link"
            >
                <i data-lucide="coffee"></i>
                Menu
            </a>

            <a
                href="<?= htmlspecialchars($startIsLoggedIn ? '../customer/customer_menu/customermenu.php' : '../login-signup/signup.php', ENT_QUOTES, 'UTF-8') ?>"
                class="topbar__link"
            >
                <i data-lucide="shopping-bag"></i>
                Order Now
            </a>
            <?php endif; ?>
        </nav>
    </header>

    <div class="video-wrap" oncontextmenu="return false;">
        <video
            id="startVideo"
            autoplay
            muted
            loop
            playsinline
            preload="auto"
            disablepictureinpicture
            controlslist="nodownload nofullscreen noremoteplayback"
        >
            <source src="/Start-screen/coffee-video.mp4" type="video/mp4">
            Your browser does not support the video tag.
        </video>

        <div class="video-overlay">
            <h1 class="video-overlay__tagline">
                Brewed to Perfection. Fueling Your Inner Brew.
            </h1>

            <a href="<?= htmlspecialchars($startIsLoggedIn ? $startHomeUrl : '../login-signup/signup.php', ENT_QUOTES, 'UTF-8') ?>" class="start-button">
                <?= $startIsLoggedIn ? ($startIsCustomer ? 'Back to Home' : 'Go to Dashboard') : 'Get Started' ?>
            </a>
        </div>
    </div>

    <section class="picks" id="topPicks">
        <div class="picks__inner">
            <div class="picks__intro">
                <h2 class="picks__title">Top Brews for this Month</h2>

                <p class="picks__text">
                    The cups our customers keep coming back for. Pick a favorite
                    and have it ready when you are.
                </p>

                <div class="picks__controls">
                    <button type="button" class="picks__arrow" id="picksPrev" aria-label="Previous drink">
                        <i data-lucide="chevron-left"></i>
                    </button>

                    <div class="picks__dots" id="picksDots"></div>

                    <button type="button" class="picks__arrow" id="picksNext" aria-label="Next drink">
                        <i data-lucide="chevron-right"></i>
                    </button>
                </div>
            </div>

            <div class="picks__viewport" id="picksViewport">
                <div class="picks__track" id="picksTrack">
                    <article class="pick-card">
                        <div class="pick-card__image">
                            <img
                                src="../images/caramel.png"
                                alt="Caramel Macchiato"
                                onerror="this.style.visibility='hidden'"
                            >
                        </div>
                        <div class="pick-card__body">
                            <h3 class="pick-card__name">Caramel Macchiato</h3>
                            <div class="pick-card__meta">
                                <span class="pick-card__tag">
                                    <span class="pick-card__tag-icon"><i data-lucide="star"></i></span>
                                    Top Picks
                                </span>
                                <span>Iced | Hot</span>
                            </div>
                        </div>
                    </article>

                    <article class="pick-card">
                        <div class="pick-card__image">
                            <img
                                src="../images/cafelatte.png"
                                alt="Spanish Latte"
                                onerror="this.style.visibility='hidden'"
                            >
                        </div>
                        <div class="pick-card__body">
                            <h3 class="pick-card__name">Spanish Latte</h3>
                            <div class="pick-card__meta">
                                <span class="pick-card__tag">
                                    <span class="pick-card__tag-icon"><i data-lucide="star"></i></span>
                                    Top Picks
                                </span>
                                <span>Iced | Hot</span>
                            </div>
                        </div>
                    </article>

                    <article class="pick-card">
                        <div class="pick-card__image">
                            <img
                                src="../images/matchalatte.png"
                                alt="Matcha Latte"
                                onerror="this.style.visibility='hidden'"
                            >
                        </div>
                        <div class="pick-card__body">
                            <h3 class="pick-card__name">Matcha Latte</h3>
                            <div class="pick-card__meta">
                                <span class="pick-card__tag">
                                    <span class="pick-card__tag-icon"><i data-lucide="star"></i></span>
                                    Top Picks
                                </span>
                                <span>Iced | Hot</span>
                            </div>
                        </div>
                    </article>

                    <article class="pick-card">
                        <div class="pick-card__image">
                            <img
                                src="../images/mocha.png"
                                alt="Mocha Frappe"
                                onerror="this.style.visibility='hidden'"
                            >
                        </div>
                        <div class="pick-card__body">
                            <h3 class="pick-card__name">Mocha Frappe</h3>
                            <div class="pick-card__meta">
                                <span class="pick-card__tag">
                                    <span class="pick-card__tag-icon"><i data-lucide="star"></i></span>
                                    Top Picks
                                </span>
                                <span>Iced | Hot</span>
                            </div>
                        </div>
                    </article>

                    <article class="pick-card">
                        <div class="pick-card__image">
                            <img
                                src="../images/icedcoffee.png"
                                alt="Hazelnut Cold Brew"
                                onerror="this.style.visibility='hidden'"
                            >
                        </div>
                        <div class="pick-card__body">
                            <h3 class="pick-card__name">Hazelnut Cold Brew</h3>
                            <div class="pick-card__meta">
                                <span class="pick-card__tag">
                                    <span class="pick-card__tag-icon"><i data-lucide="star"></i></span>
                                    Top Picks
                                </span>
                                <span>Iced | Hot</span>
                            </div>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="about" id="about" aria-labelledby="aboutTitle">
        <div class="about__inner">
            <div class="about__copy">
                <p class="about__eyebrow">A little more than your daily coffee</p>
                <h2 class="about__title" id="aboutTitle">Your next favorite brew starts here.</h2>
                <p class="about__text">
                    Brewski makes it easy to find a drink that feels like yours. Explore the café menu,
                    customize each order, and discover recommendations shaped around your tastes.
                    Order ahead for a simple store pickup, then follow your order as it is prepared.
                </p>

                <div class="about__features">
                    <div class="about__feature">
                        <span class="about__feature-number">01</span>
                        <div>
                            <h3>Find your flavor</h3>
                            <p>Get drink ideas that fit your preferences.</p>
                        </div>
                    </div>
                    <div class="about__feature">
                        <span class="about__feature-number">02</span>
                        <div>
                            <h3>Make it yours</h3>
                            <p>Choose sizes, add-ons, and customizations.</p>
                        </div>
                    </div>
                    <div class="about__feature">
                        <span class="about__feature-number">03</span>
                        <div>
                            <h3>Order with ease</h3>
                            <p>Pay securely and track your pickup order.</p>
                        </div>
                    </div>
                </div>

                <a class="about__link" href="../customer/customer_menu/customermenu.php">
                    Explore the menu <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                </a>
            </div>

            <div class="about__mascot">
                <div class="about__mascot-glow" aria-hidden="true"></div>
                <img src="../images/mascot.png" alt="Brewski's smiling coffee cup mascot wearing a cozy sweater">
                <p class="about__mascot-caption">Your cozy corner for good coffee.</p>
            </div>
        </div>
    </section>

    <script>
        const video = document.getElementById('startVideo');
        video.muted = true;
        video.play().catch(function () {
            document.addEventListener('click', function () { video.play(); }, { once: true });
        });

        if (window.lucide) {
            window.lucide.createIcons();
        }

        const viewport = document.getElementById('picksViewport');
        const track = document.getElementById('picksTrack');
        const dotsBox = document.getElementById('picksDots');
        const prevBtn = document.getElementById('picksPrev');
        const nextBtn = document.getElementById('picksNext');
        const cards = Array.from(track.children);
        let index = 0;

        cards.forEach(function (card, i) {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'picks__dot';
            dot.setAttribute('aria-label', 'Show drink ' + (i + 1));
            dot.addEventListener('click', function () { go(i); });
            dotsBox.appendChild(dot);

            card.addEventListener('click', function () { go(i); });
        });

        const dots = Array.from(dotsBox.children);

        function stepSize() {
            const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
            return cards[0].offsetWidth + gap;
        }

        function go(target) {
            index = Math.max(0, Math.min(cards.length - 1, target));
            track.style.transform = 'translateX(' + (-index * stepSize()) + 'px)';

            cards.forEach(function (card, n) {
                card.dataset.distance = Math.abs(n - index);
            });

            dots.forEach(function (dot, n) {
                dot.classList.toggle('is-active', n === index);
            });

            prevBtn.disabled = index === 0;
            nextBtn.disabled = index === cards.length - 1;
        }

        prevBtn.addEventListener('click', function () { go(index - 1); });
        nextBtn.addEventListener('click', function () { go(index + 1); });
        window.addEventListener('resize', function () { go(index); });

        let startX = null;

        viewport.addEventListener('pointerdown', function (e) {
            startX = e.clientX;
        });

        viewport.addEventListener('pointerup', function (e) {
            if (startX === null) return;
            const dx = e.clientX - startX;
            startX = null;
            if (Math.abs(dx) > 50) {
                go(dx < 0 ? index + 1 : index - 1);
            }
        });

        viewport.addEventListener('pointercancel', function () { startX = null; });

        go(0);
    </script>

    <?php require_once __DIR__ . '/../cookie-consent/cookie-consent.php'; ?>
</body>
</html>
