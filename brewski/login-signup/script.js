

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

            clearFieldErrors(signupForm);


            if (firstName) {

                const messages = [];
                const value = firstName.value.trim();

                if (value === '') {

                    messages.push('Enter your first name.');

                } else if (!isValidName(value)) {

                    messages.push(
                        'First name may only contain letters, spaces, hyphens, and apostrophes.'
                    );
                }

                setFieldErrors('first_name', messages);

                if (messages.length > 0) {
                    hasError = true;
                }
            }


            if (lastName) {

                const messages = [];
                const value = lastName.value.trim();

                if (value === '') {

                    messages.push('Enter your last name.');

                } else if (!isValidName(value)) {

                    messages.push(
                        'Last name may only contain letters, spaces, hyphens, and apostrophes.'
                    );
                }

                setFieldErrors('last_name', messages);

                if (messages.length > 0) {
                    hasError = true;
                }
            }


            if (email) {

                const messages = [];
                const value = email.value.trim();

                if (value === '') {

                    messages.push('Enter your email address.');

                } else if (!isValidEmail(value)) {

                    messages.push('Please enter a valid email address.');
                }

                setFieldErrors('email', messages);

                if (messages.length > 0) {
                    hasError = true;
                }
            }


            if (password) {

                const messages = [];

                if (password.value === '') {

                    messages.push('Enter a password.');

                } else {

                    passwordPolicyMessages(
                        password.value,
                        passwordPolicy(password)
                    ).forEach(message => {
                        messages.push(message);
                    });
                }

                setFieldErrors('password', messages);

                if (messages.length > 0) {
                    hasError = true;
                }
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



    function isValidName(value) {

        return /^[A-Za-zÀ-ÿ\s'-]+$/.test(value);
    }



    function passwordPolicy(field) {

        return {
            minLength: parseInt(field.dataset.minLength, 10) || 0,
            lowercase: parseInt(field.dataset.minLowercase, 10) || 0,
            uppercase: parseInt(field.dataset.minUppercase, 10) || 0,
            digits: parseInt(field.dataset.minDigits, 10) || 0,
            special: parseInt(field.dataset.minSpecial, 10) || 0,
            specialCharacters: field.dataset.specialCharacters || ''
        };
    }



    function passwordPolicyMessages(value, policy) {

        const messages = [];

        const counts = {
            lowercase: countMatches(value, /[a-z]/g),
            uppercase: countMatches(value, /[A-Z]/g),
            digits: countMatches(value, /[0-9]/g),
            special: countSpecialCharacters(value, policy.specialCharacters)
        };


        if (value.length < policy.minLength) {

            messages.push(
                'Password must be at least ' +
                policy.minLength +
                ' characters long.'
            );
        }


        [
            { key: 'lowercase', label: 'lowercase letter' },
            { key: 'uppercase', label: 'uppercase letter' },
            { key: 'digits', label: 'number' },
            { key: 'special', label: 'special character' }
        ].forEach(rule => {

            const required = policy[rule.key];

            if (required > 0 && counts[rule.key] < required) {

                messages.push(
                    'Password must include at least ' +
                    required +
                    ' ' +
                    rule.label +
                    (required === 1 ? '' : 's') +
                    '.'
                );
            }
        });


        return messages;
    }



    function countMatches(value, pattern) {

        const matches = value.match(pattern);

        return matches ? matches.length : 0;
    }



    function countSpecialCharacters(value, characters) {

        if (!characters) {
            return 0;
        }

        let count = 0;

        for (const character of value) {

            if (characters.indexOf(character) !== -1) {
                count++;
            }
        }

        return count;
    }



    function setFieldErrors(fieldId, messages) {

        const container = document.getElementById(fieldId + '-messages');
        const errorElement = document.getElementById(fieldId + '-error');
        const inputElement = document.getElementById(fieldId);
        const hasMessages = messages.length > 0;


        if (container) {

            container.innerHTML = '';

            messages.forEach(message => {

                const paragraph = document.createElement('p');

                paragraph.className = 'field__error';
                paragraph.textContent = message;

                container.appendChild(paragraph);
            });

            container.style.display = hasMessages ? 'block' : 'none';
        }


        if (errorElement) {

            errorElement.textContent = hasMessages ? messages.join(' ') : '';
            errorElement.style.display = hasMessages ? 'block' : 'none';
        }


        if (inputElement) {

            if (hasMessages) {

                inputElement.setAttribute('aria-invalid', 'true');

            } else {

                inputElement.removeAttribute('aria-invalid');
            }
        }
    }



    function clearFieldErrors(scope) {

        const root = scope || document;


        root.querySelectorAll('.field__messages').forEach(container => {

            container.innerHTML = '';
            container.style.display = 'none';
        });


        root.querySelectorAll('.field__error').forEach(errorElement => {

            errorElement.textContent = '';
            errorElement.style.display = 'none';
        });


        root.querySelectorAll('input').forEach(input => {

            input.removeAttribute('aria-invalid');
        });
    }



    function showError(fieldId, message) {

        setFieldErrors(fieldId, [message]);
    }

});