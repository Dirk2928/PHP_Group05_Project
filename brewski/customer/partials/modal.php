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

            <div class="option-group">

                <h3>
                    Temperature
                </h3>

                <div
                    class="option-buttons"
                    data-group="temp"
                >

                    <button
                        type="button"
                        class="option-btn active"
                        data-value="Hot"
                        data-price="0"
                    >

                        <i data-lucide="flame"></i>

                        Hot

                    </button>

                    <button
                        type="button"
                        class="option-btn"
                        data-value="Iced"
                        data-price="0"
                    >

                        <i data-lucide="snowflake"></i>

                        Iced

                    </button>

                </div>

            </div>

            <div class="option-group">

                <h3>
                    Size
                </h3>

                <div
                    class="option-buttons"
                    data-group="size"
                >

                    <button
                        type="button"
                        class="option-btn active"
                        data-value="Regular"
                        data-price="0"
                    >
                        Regular
                    </button>

                    <button
                        type="button"
                        class="option-btn"
                        data-value="Large"
                        data-price="20"
                    >
                        Large (+₱20)
                    </button>

                </div>

            </div>

            <div class="option-group">

                <h3>
                    Sugar Level
                </h3>

                <div
                    class="option-buttons"
                    data-group="sugar"
                >

                    <button
                        type="button"
                        class="option-btn active"
                        data-value="100%"
                        data-price="0"
                    >
                        100%
                    </button>

                    <button
                        type="button"
                        class="option-btn"
                        data-value="75%"
                        data-price="0"
                    >
                        75%
                    </button>

                    <button
                        type="button"
                        class="option-btn"
                        data-value="50%"
                        data-price="0"
                    >
                        50%
                    </button>

                    <button
                        type="button"
                        class="option-btn"
                        data-value="25%"
                        data-price="0"
                    >
                        25%
                    </button>

                    <button
                        type="button"
                        class="option-btn"
                        data-value="0%"
                        data-price="0"
                    >
                        0%
                    </button>

                </div>

            </div>

            <div class="option-group">

                <h3>
                    Add-ons
                </h3>

                <div
                    class="option-buttons"
                    data-group="addons"
                >

                    <button
                        type="button"
                        class="option-btn"
                        data-value="Extra Shot"
                        data-price="30"
                    >
                        Extra Shot (+₱30)
                    </button>

                    <button
                        type="button"
                        class="option-btn"
                        data-value="Oat Milk"
                        data-price="25"
                    >
                        Oat Milk (+₱25)
                    </button>

                    <button
                        type="button"
                        class="option-btn"
                        data-value="Whipped Cream"
                        data-price="15"
                    >
                        Whipped Cream (+₱15)
                    </button>

                </div>

            </div>

            <div class="option-group">

                <h3>
                    Special Instructions
                </h3>

                <textarea
                    id="special-instructions"
                    class="special-instructions"
                    placeholder="e.g., Less ice, extra hot, no foam..."
                    rows="3"
                ></textarea>

            </div>

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

                Add to Cart

                <i data-lucide="arrow-right"></i>

            </button>

        </div>

    </div>

</div>
