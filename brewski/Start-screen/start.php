<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brewski</title>

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
            <a
                href="../customer/customer_home/customerhome.php"
                class="topbar__link"
            >
                <i data-lucide="home"></i>
                Home
            </a>

            <a
                href="../customer/customer_menu/customermenu.php"
                class="topbar__link"
            >
                <i data-lucide="coffee"></i>
                Menu
            </a>

            <a
                href="../login-signup/signup.php"
                class="topbar__link"
            >
                <i data-lucide="shopping-bag"></i>
                Order Now
            </a>
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
            <source src="coffee-video.mp4" type="video/mp4">
            Your browser does not support the video tag.
        </video>

        <div class="video-overlay">
            <h1 class="video-overlay__tagline">
                Brewed to Perfection. Fueling Your Inner Brew.
            </h1>

            <a href="../customer/customer_home/customerhome.php" class="start-button">
                Get Started
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
<!--
    <section class="devs" id="devs">
        <div class="devs__inner">
            <h2 class="devs__title">Developed by</h2>

            <div class="devs__grid">
                <article
                    class="dev-card"
                    tabindex="0"
                    role="button"
                    aria-haspopup="dialog"
                    data-role="Role Title"
                    data-desc="Write two or three sentences here about what this person built or handled in Brewski."
                >
                    <div class="dev-card__order">
                        <span>Order</span>
                        <span>01</span>
                    </div>

                    <div class="dev-card__photo">
                        <img
                            src="../images/devs/julius.jpg"
                            alt="Biena Rose Bahay"
                            onerror="this.style.visibility='hidden'"
                        >
                    </div>

                    <h3 class="dev-card__name">Biena Rose Bahay</h3>
                    <p class="dev-card__role">Developer</p>

                    <span class="dev-card__more">
                        View details
                        <i data-lucide="arrow-up-right"></i>
                    </span>
                </article>

                <article
                    class="dev-card"
                    tabindex="0"
                    role="button"
                    aria-haspopup="dialog"
                    data-role="Role Title"
                    data-desc="Write two or three sentences here about what this person built or handled in Brewski."
                >
                    <div class="dev-card__order">
                        <span>Order</span>
                        <span>02</span>
                    </div>

                    <div class="dev-card__photo">
                        <img
                            src="../images/devs/johnmark.jpg"
                            alt="Yther Ballesteros"
                            onerror="this.style.visibility='hidden'"
                        >
                    </div>

                    <h3 class="dev-card__name">Yther Ballesteros</h3>
                    <p class="dev-card__role">Developer</p>

                    <span class="dev-card__more">
                        View details
                        <i data-lucide="arrow-up-right"></i>
                    </span>
                </article>

                <article
                    class="dev-card"
                    tabindex="0"
                    role="button"
                    aria-haspopup="dialog"
                    data-role="Role Title"
                    data-desc="Write two or three sentences here about what this person built or handled in Brewski."
                >
                    <div class="dev-card__order">
                        <span>Order</span>
                        <span>03</span>
                    </div>

                    <div class="dev-card__photo">
                        <img
                            src="../images/devs/dev3.jpg"
                            alt="Dirk Maverick Cruz"
                            onerror="this.style.visibility='hidden'"
                        >
                    </div>

                    <h3 class="dev-card__name">Dirk Maverick Cruz</h3>
                    <p class="dev-card__role">Developer</p>

                    <span class="dev-card__more">
                        View details
                        <i data-lucide="arrow-up-right"></i>
                    </span>
                </article>

                <article
                    class="dev-card"
                    tabindex="0"
                    role="button"
                    aria-haspopup="dialog"
                    data-role="Role Title"
                    data-desc="Write two or three sentences here about what this person built or handled in Brewski."
                >
                    <div class="dev-card__order">
                        <span>Order</span>
                        <span>04</span>
                    </div>

                    <div class="dev-card__photo">
                        <img
                            src="../images/devs/dev4.jpg"
                            alt="Gerald Saligan"
                            onerror="this.style.visibility='hidden'"
                        >
                    </div>

                    <h3 class="dev-card__name">Gerald Saligan</h3>
                    <p class="dev-card__role">Developer</p>

                    <span class="dev-card__more">
                        View details
                        <i data-lucide="arrow-up-right"></i>
                    </span>
                </article>
            </div>

            <p class="devs__footer">&copy; 2026 Brewski</p>
        </div>
    </section>

    <dialog class="dev-modal" id="devModal" aria-labelledby="devModalName">
        <button type="button" class="dev-modal__close" id="devModalClose" aria-label="Close">
            <i data-lucide="x"></i>
        </button>

        <div class="dev-modal__photo">
            <img id="devModalPhoto" alt="">
        </div>

        <div class="dev-modal__content">
            <p class="dev-modal__order" id="devModalOrder"></p>
            <h3 class="dev-modal__name" id="devModalName"></h3>
            <p class="dev-modal__role" id="devModalRole"></p>
            <p class="dev-modal__desc" id="devModalDesc"></p>
        </div>
    </dialog>
-->
    

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

        (function () {
            const devCards = Array.from(document.querySelectorAll('.dev-card'));
            const modal = document.getElementById('devModal');
            const closeBtn = document.getElementById('devModalClose');
            const photo = document.getElementById('devModalPhoto');
            const order = document.getElementById('devModalOrder');
            const name = document.getElementById('devModalName');
            const role = document.getElementById('devModalRole');
            const desc = document.getElementById('devModalDesc');

            function openDev(card) {
                const img = card.querySelector('.dev-card__photo img');
                const orderNumber = card.querySelector('.dev-card__order span:last-child');

                photo.style.visibility = '';
                photo.src = img.getAttribute('src');
                photo.alt = img.alt;

                order.textContent = 'Order ' + orderNumber.textContent;
                name.textContent = card.querySelector('.dev-card__name').textContent;
                role.textContent = card.dataset.role || '';
                desc.textContent = card.dataset.desc || '';

                modal.showModal();
            }

            devCards.forEach(function (card) {
                card.addEventListener('click', function () { openDev(card); });
                card.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        openDev(card);
                    }
                });
            });

            photo.addEventListener('error', function () {
                photo.style.visibility = 'hidden';
            });

            closeBtn.addEventListener('click', function () { modal.close(); });

            modal.addEventListener('click', function (e) {
                if (e.target === modal) modal.close();
            });
        })();
    </script>
</body>
</html>