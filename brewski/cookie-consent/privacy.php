<?php

require_once __DIR__ . '/../login-signup/session_init.php';
require_once __DIR__ . '/consent-state.php';

$idleMinutes = max(1, (int) round(SESSION_IDLE_TIMEOUT / 60));
$absoluteHours = max(1, (int) round(SESSION_ABSOLUTE_TIMEOUT / 3600));
$maxLoginAttempts = brewski_max_login_attempts();

$homeUrl = BREWSKI_BASE_URL . '/Start-screen/start.php';
$accountUrl = BREWSKI_BASE_URL . '/customer/customer_profile/customer_profile.php';

$currentChoice = brewski_consent_choice();

if ($currentChoice === 'accept') {
    $choiceLabel = 'Accepted';
} elseif ($currentChoice === 'reject') {
    $choiceLabel = 'Rejected';
} else {
    $choiceLabel = 'Not chosen yet';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Privacy Policy | brewski</title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Questrial&display=swap"
        rel="stylesheet"
    >

    <style>
        :root {
            --espresso: #1e110a;
            --latte: #f1e2ca;
            --cream: #faf6ee;
            --mocha: #71492a;
            --font-brand: "Fredoka", "Century Gothic", sans-serif;
            --font-body: "Questrial", "Century Gothic", "Segoe UI", sans-serif;
        }

        *,
        *::before,
        *::after { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--cream);
            color: var(--espresso);
            font-family: var(--font-body);
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
        }

        .legal-topbar {
            background: var(--espresso);
            color: var(--latte);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0 1.5rem;
            height: 4.5rem;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .legal-topbar__brand {
            font-family: var(--font-brand);
            font-size: 1.4rem;
            font-weight: 600;
        }

        .legal-topbar__brand span { color: var(--latte); opacity: 0.75; }

        .legal-topbar__link {
            color: var(--latte);
            font-size: 0.85rem;
            text-decoration: none;
            border: 1px solid rgba(241, 226, 202, 0.5);
            border-radius: 999px;
            padding: 0.45rem 1.1rem;
        }

        .legal-topbar__link:hover { background: rgba(241, 226, 202, 0.14); }

        .legal {
            max-width: 46rem;
            margin: 0 auto;
            padding: 3rem 1.5rem 5rem;
        }

        .legal__title {
            font-family: var(--font-brand);
            font-size: clamp(1.8rem, 4vw, 2.4rem);
            margin: 0 0 0.4rem;
        }

        .legal__meta {
            margin: 0 0 2rem;
            font-size: 0.85rem;
            color: var(--mocha);
        }

        .legal__status {
            margin: 0 0 2rem;
            padding: 0.9rem 1.1rem;
            border-left: 3px solid var(--mocha);
            background: var(--latte);
            font-size: 0.9rem;
        }

        .legal h2 {
            font-family: var(--font-brand);
            font-size: 1.15rem;
            margin: 2.4rem 0 0.6rem;
        }

        .legal h3 {
            font-size: 0.95rem;
            margin: 1.4rem 0 0.3rem;
        }

        .legal p { margin: 0.6rem 0; }

        .legal ul {
            margin: 0.6rem 0;
            padding-left: 1.2rem;
        }

        .legal li { margin: 0.35rem 0; }

        .legal code {
            background: var(--latte);
            border-radius: 4px;
            padding: 0.1rem 0.35rem;
            font-size: 0.85em;
        }

        .legal a { color: var(--mocha); }

        .legal__note {
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(113, 73, 42, 0.25);
            font-size: 0.85rem;
            color: var(--mocha);
        }
    </style>

</head>

<body>

<header class="legal-topbar">

    <p class="legal-topbar__brand">
        brew<span>ski</span>
    </p>

    <a
        class="legal-topbar__link"
        href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>"
    >
        Back to the shop
    </a>

</header>

<main class="legal">

    <h1 class="legal__title">
        Privacy Policy
    </h1>

    <p class="legal__meta">
        Version <?php echo htmlspecialchars(BREWSKI_CONSENT_VERSION, ENT_QUOTES, 'UTF-8'); ?>
        &middot; Effective 16 October 2026
    </p>

    <p class="legal__status">
        <strong>Your cookie choice:</strong>

        <?php echo htmlspecialchars($choiceLabel, ENT_QUOTES, 'UTF-8'); ?>.

        <?php if ($currentChoice !== ''): ?>

            To change it, clear the

            <code><?php echo htmlspecialchars(BREWSKI_CONSENT_COOKIE, ENT_QUOTES, 'UTF-8'); ?></code>

            cookie in your browser and the notice will ask again.

        <?php else: ?>

            The notice appears at the bottom of the shop pages until you choose.

        <?php endif; ?>

    </p>

    <p>
        Brewski is a coffee ordering system. To take orders we have to keep a small amount of
        information about you. This page explains exactly what is stored, why it is stored, and
        how long it stays.
    </p>

    <h2>Information we collect</h2>

    <h3>Your account</h3>

    <ul>
        <li>Your first name, last name, and email address.</li>
        <li>Your password, stored as a one-way hash. The password itself is never stored or emailed.</li>
        <li>Your role (customer, staff, or administrator).</li>
    </ul>

    <h3>Account verification and sign-in security</h3>

    <ul>
        <li>
            When you sign up we email you an activation link. The token in that link is stored
            hashed and expires after 24 hours.
        </li>
        <li>
            If you use an authenticator app, your secret key is stored encrypted and your recovery
            codes are stored hashed.
        </li>
        <li>
            We count failed sign-in attempts. After
            <?php echo (int) $maxLoginAttempts; ?>
            wrong passwords the account is locked until an administrator unlocks it.
        </li>
    </ul>

    <h3>Orders and delivery</h3>

    <ul>
        <li>Your cart and the items, sizes, and add-ons you choose.</li>
        <li>Your order history, totals, and payment method label (cash on delivery).</li>
        <li>Your delivery address, contact number, and any delivery notes you add.</li>
        <li>Delivery status updates made by staff.</li>
    </ul>

    <h3>Preferences</h3>

    <ul>
        <li>Any taste preferences you tick on a product, so the menu can remember them.</li>
    </ul>

    <h2>Cookies we set</h2>

    <p>
        Brewski sets two cookies and no advertising or analytics cookies.
    </p>

    <ul>
        <li>
            <code>PHPSESSID</code> &mdash; keeps you signed in between pages. It is marked
            <code>HttpOnly</code> and <code>SameSite=Lax</code>, and <code>Secure</code> when the
            site is served over HTTPS. It ends when you log out, and also after
            <?php echo (int) $idleMinutes; ?>
            minutes with no activity or
            <?php echo (int) $absoluteHours; ?>
            hours in total.
        </li>
        <li>
            <code><?php echo htmlspecialchars(BREWSKI_CONSENT_COOKIE, ENT_QUOTES, 'UTF-8'); ?></code>
            &mdash; records whether you accepted or rejected this notice, and which version of it
            you saw. It lasts 12 months.
        </li>
    </ul>

    <p>
        Both cookies are needed for the site to behave the way you expect, so rejecting the notice
        does not switch them off. It records your answer and stops the notice from being shown
        again.
    </p>

    <h2>Services that help us run the site</h2>

    <ul>
        <li>
            <strong>Mailjet</strong> &mdash; delivers our transactional email, such as activation
            links and sign-in codes. Your email address is sent to them for that purpose only.
        </li>
        <li>
            <strong>Google Fonts</strong> &mdash; serves the fonts used on our pages. Your browser
            requests the font files from Google.
        </li>
        <li>
            <strong>unpkg</strong> &mdash; serves the icon library used on the landing screen.
        </li>
    </ul>

    <p>
        We do not sell your information, and we do not share it with anyone else.
    </p>

    <h2>How long we keep it</h2>

    <ul>
        <li>Account details stay until you ask us to delete the account.</li>
        <li>Activation tokens expire after 24 hours; one-time sign-in codes expire within minutes.</li>
        <li>
            Order, delivery, and transaction records are kept for the life of the account so staff
            can look up past orders and handle disputes.
        </li>
        <li>The cookie choice above is forgotten after 12 months, and then we ask again.</li>
    </ul>

    <h2>Your choices</h2>

    <ul>
        <li>Accept or reject the cookie notice, as described at the top of this page.</li>
        <li>
            Update your name, email, password, and address yourself on your
            <a href="<?php echo htmlspecialchars($accountUrl, ENT_QUOTES, 'UTF-8'); ?>">account</a>
            page.
        </li>
        <li>
            Ask us to delete your account and the information listed above by contacting an
            administrator.
        </li>
    </ul>

    <h2>Contact</h2>

    <p>
        Questions about this policy, or a request to see or remove your information, can be raised
        with the Brewski administrator through the store.
    </p>

    <p class="legal__note">
        This policy covers the Brewski ordering system only. It is written to match what the
        system actually stores, and it will be updated here if that changes &mdash; the version
        number above tells you which text you are reading.
    </p>

</main>

</body>

</html>
