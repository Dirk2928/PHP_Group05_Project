<?php

require_once __DIR__ . '/consent-state.php';

if (!brewski_consent_required() || session_status() !== PHP_SESSION_ACTIVE) {
    return;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$consentBase = defined('BREWSKI_BASE_URL') ? BREWSKI_BASE_URL : '';
$consentToken = (string) $_SESSION['csrf_token'];
$consentAction = $consentBase . '/cookie-consent/consent.php';
$consentPolicy = $consentBase . '/cookie-consent/privacy.php';
$consentReturn = brewski_consent_request_path();
?>
<div class="brewski-consent" role="region" aria-label="Cookie notice">

    <p class="brewski-consent__text">

        Brewski uses a session cookie to keep you signed in and a consent cookie to remember
        this choice. Read the

        <a
            class="brewski-consent__link"
            href="<?php echo htmlspecialchars($consentPolicy, ENT_QUOTES, 'UTF-8'); ?>"
        >Privacy Policy</a>

        for what we store and why.

    </p>

    <form
        class="brewski-consent__actions"
        method="POST"
        action="<?php echo htmlspecialchars($consentAction, ENT_QUOTES, 'UTF-8'); ?>"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo htmlspecialchars($consentToken, ENT_QUOTES, 'UTF-8'); ?>"
        >

        <input
            type="hidden"
            name="return"
            value="<?php echo htmlspecialchars($consentReturn, ENT_QUOTES, 'UTF-8'); ?>"
        >

        <button
            type="submit"
            name="choice"
            value="reject"
            class="brewski-consent__button brewski-consent__button--quiet"
        >
            Reject
        </button>

        <button
            type="submit"
            name="choice"
            value="accept"
            class="brewski-consent__button"
        >
            Accept
        </button>

    </form>

</div>

<style>
    .brewski-consent {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 300;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.5rem;
        background: #c8763a;
        background: linear-gradient(180deg, #dd9247 0%, #c8763a 100%);
        color: var(--espresso, #1e110a);
        font-family: var(--font-body, "Century Gothic", "Questrial", "Segoe UI", sans-serif);
        font-size: 0.85rem;
        line-height: 1.5;
        box-shadow: 0 -8px 28px rgba(30, 17, 10, 0.35);
    }

    .brewski-consent__text {
        margin: 0;
        max-width: 62ch;
    }

    .brewski-consent__link {
        color: var(--espresso, #1e110a);
        text-decoration: underline;
        text-underline-offset: 2px;
    }

    .brewski-consent__actions {
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }

    .brewski-consent__button {
        border: 1px solid transparent;
        border-radius: 999px;
        padding: 0.55rem 1.4rem;
        font: inherit;
        font-weight: 600;
        cursor: pointer;
        background: var(--espresso, #1e110a);
        color: var(--latte, #f1e2ca);
    }

    .brewski-consent__button:hover {
        background: var(--dark-mocha, #3c3027);
    }

    .brewski-consent__button--quiet {
        background: transparent;
        border-color: rgba(30, 17, 10, 0.55);
        color: var(--espresso, #1e110a);
    }

    .brewski-consent__button--quiet:hover {
        background: rgba(30, 17, 10, 0.12);
    }

    .brewski-consent__button:focus-visible {
        outline: 2px solid var(--espresso, #1e110a);
        outline-offset: 2px;
    }

    body:has(.brewski-consent) {
        padding-bottom: 7rem;
    }

    @media (max-width: 640px) {
        .brewski-consent {
            padding: 0.9rem 1rem;
        }

        .brewski-consent__actions {
            width: 100%;
            justify-content: flex-end;
        }

        body:has(.brewski-consent) {
            padding-bottom: 11rem;
        }
    }
</style>
