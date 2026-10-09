document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('otp-form');
    const codeField = document.getElementById('otp');
    const digits = Array.from(document.querySelectorAll('.otp-input'));

    if (!form || !codeField || digits.length !== 6) {
        return;
    }

    const syncCode = () => {
        codeField.value = digits.map((input) => input.value).join('');
    };

    const fillFrom = (startIndex, value) => {
        const cleanValue = value.replace(/\D/g, '');
        if (cleanValue.length >= digits.length) {
            startIndex = 0;
        }
        const pastedDigits = cleanValue.slice(0, digits.length - startIndex);
        for (let offset = 0; offset < digits.length - startIndex; offset += 1) {
            digits[startIndex + offset].value = pastedDigits[offset] || '';
        }
        syncCode();

        const nextInput = digits[Math.min(startIndex + pastedDigits.length, digits.length - 1)];
        nextInput.focus();
        if (pastedDigits.length === digits.length - startIndex) {
            nextInput.select();
        }
    };

    digits.forEach((input, index) => {
        input.addEventListener('input', () => {
            const enteredDigits = input.value.replace(/\D/g, '');
            if (enteredDigits.length > 1) {
                fillFrom(index, enteredDigits);
                return;
            }

            input.value = enteredDigits;
            syncCode();
            if (enteredDigits && index < digits.length - 1) {
                digits[index + 1].focus();
            }
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace' && input.value === '' && index > 0) {
                event.preventDefault();
                digits[index - 1].focus();
                digits[index - 1].value = '';
                syncCode();
            } else if (event.key === 'ArrowLeft' && index > 0) {
                event.preventDefault();
                digits[index - 1].focus();
            } else if (event.key === 'ArrowRight' && index < digits.length - 1) {
                event.preventDefault();
                digits[index + 1].focus();
            }
        });

        input.addEventListener('paste', (event) => {
            event.preventDefault();
            fillFrom(index, event.clipboardData.getData('text'));
        });
    });

    form.addEventListener('submit', syncCode);
    syncCode();
});
