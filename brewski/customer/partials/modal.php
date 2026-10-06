<?php

?>

<div
    class="modal-overlay"
    id="customization-modal"
>

    <div class="modal">

        <div class="modal__header">

            <div>

                <h2 id="modal-product-name">
                    Customize your drink
                </h2>

                <p class="modal__subtitle">
                    Make it your own
                </p>

            </div>

            <button
                type="button"
                class="modal__close"
                id="modal-close"
                aria-label="Close customization"
            >

                <i data-lucide="x"></i>

            </button>

        </div>

        <div class="modal__body">

            <section class="option-group drink-quantity">
                <h3>Drink Quantity</h3>
                <div class="quantity-stepper quantity-stepper--drink" aria-label="Drink quantity">
                    <button type="button" class="quantity-stepper__button" data-drink-action="decrease" aria-label="Remove one drink">−</button>
                    <output class="quantity-stepper__value" id="drink-quantity-value" aria-live="polite">1</output>
                    <button type="button" class="quantity-stepper__button" data-drink-action="increase" aria-label="Add one drink">+</button>
                </div>
                <p class="addon-option__hint">Selected options apply to each drink. Add-on quantities are per drink.</p>
            </section>

            <section class="option-group" data-option-group="temperature" hidden>
                <h3>Temperature</h3>
                <div class="option-buttons" data-group="temp"></div>
            </section>

            <section class="option-group" data-option-group="size" hidden>
                <h3>Size</h3>
                <div class="option-buttons" data-group="size"></div>
            </section>

            <section class="option-group" data-option-group="sugar" hidden>
                <h3>Sugar Level</h3>
                <div class="option-buttons" data-group="sugar"></div>
            </section>

            <section class="option-group" data-option-group="addon" hidden>
                <h3>Add-ons</h3>
                <p class="addon-option__hint">Set the add-on quantity for each drink.</p>
                <div class="option-buttons" data-group="addons"></div>
            </section>

        </div>

        <div class="modal__footer">

            <div class="modal__total">

                <span class="modal__total-label">
                    Total
                </span>

                <span id="modal-total-price">
                    ₱0
                </span>

            </div>

            <button
                type="button"
                class="btn-confirm"
                id="btn-confirm-add"
            >

                <span>Add to Cart</span>

                <i data-lucide="arrow-right"></i>

            </button>

        </div>

    </div>

</div>
