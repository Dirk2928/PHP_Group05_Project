

document.addEventListener('DOMContentLoaded', () => {


    const loginForm = document.getElementById('login-form');

    if (loginForm) {

        loginForm.addEventListener('submit', function (event) {

            const email = document.getElementById('email');
            const password = document.getElementById('password');

            const emailError = document.getElementById('email-error');
            const passwordError = document.getElementById('password-error');

            let hasError = false;


            if (emailError) {
                emailError.textContent = '';
                emailError.style.display = 'none';
            }

            if (passwordError) {
                passwordError.textContent = '';
                passwordError.style.display = 'none';
            }

            email.removeAttribute('aria-invalid');
            password.removeAttribute('aria-invalid');


            if (email.value.trim() === '') {

                showError(
                    'email',
                    'Enter your email address.'
                );

                hasError = true;

            } else if (!isValidEmail(email.value.trim())) {

                showError(
                    'email',
                    'Please enter a valid email address.'
                );

                hasError = true;
            }


            if (password.value === '') {

                showError(
                    'password',
                    'Enter your password.'
                );

                hasError = true;
            }


            if (hasError) {
                event.preventDefault();
                return;
            }


            const submitBtn = loginForm.querySelector('.btn');

            if (submitBtn) {
                submitBtn.textContent = 'Logging in...';
                submitBtn.disabled = true;
            }

        });
    }


const passwordInput  = document.getElementById('password');
const passwordToggle = document.getElementById('toggle-password');

if (passwordInput && passwordToggle) {

    passwordToggle.addEventListener('click', () => {

        const isHidden = passwordInput.type === 'password';

        passwordInput.type = isHidden ? 'text' : 'password';

        passwordToggle.textContent = isHidden ? '👁' : '👁';
        passwordToggle.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        passwordToggle.setAttribute(
            'aria-label',
            isHidden ? 'Hide password' : 'Show password'
        );


        passwordInput.focus();
    });
}



    const signupForm = document.getElementById('signup-form');

    if (signupForm) {

        signupForm.addEventListener('submit', function (event) {

            const firstName = document.getElementById('first_name');
            const lastName = document.getElementById('last_name');
            const email = document.getElementById('email');
            const password = document.getElementById('password');

            let hasError = false;


            document.querySelectorAll('.field__error').forEach(error => {
                error.textContent = '';
                error.style.display = 'none';
            });

            document.querySelectorAll('input').forEach(input => {
                input.removeAttribute('aria-invalid');
            });


            if (firstName.value.trim() === '') {

                showError(
                    'first_name',
                    'Enter your first name.'
                );

                hasError = true;
            }


            if (lastName.value.trim() === '') {

                showError(
                    'last_name',
                    'Enter your last name.'
                );

                hasError = true;
            }


            if (email.value.trim() === '') {

                showError(
                    'email',
                    'Enter your email address.'
                );

                hasError = true;

            } else if (!isValidEmail(email.value.trim())) {

                showError(
                    'email',
                    'Please enter a valid email address.'
                );

                hasError = true;
            }


            const minPasswordLength = parseInt(password.dataset.minLength, 10) || 12;

            if (password.value.length < minPasswordLength) {

                showError(
                    'password',
                    'Use at least ' + minPasswordLength + ' characters.'
                );

                hasError = true;
            }


            if (hasError) {

                event.preventDefault();
                return;
            }


            const submitBtn = signupForm.querySelector('.btn');

            if (submitBtn) {
                submitBtn.textContent = 'Creating account...';
                submitBtn.disabled = true;
            }

        });
    }



    function isValidEmail(email) {

        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }



    function showError(fieldId, message) {

        const errorElement =
            document.getElementById(fieldId + '-error');

        const inputElement =
            document.getElementById(fieldId);


        if (errorElement) {

            errorElement.textContent = message;
            errorElement.style.display = 'block';
        }


        if (inputElement) {

            inputElement.setAttribute(
                'aria-invalid',
                'true'
            );
        }
    }

});