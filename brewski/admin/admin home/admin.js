document.addEventListener('DOMContentLoaded', function () {


    var profileBtn = document.getElementById('profileBtn');
    var profileMenu = document.getElementById('profileMenu');


    var navItems = document.querySelectorAll('.nav-item[data-view]');


    var homeView = document.getElementById('homeView');
    var dynamicView = document.getElementById('dynamicView');
    var placeholderView = document.getElementById('placeholderView');
    var placeholderTitle = document.getElementById('placeholderTitle');


    var customerParent = document.getElementById('customerParent');
    var customerSubmenu = document.getElementById('customerSubmenu');
    var staffParent = document.getElementById('staffParent');
    var staffSubmenu = document.getElementById('staffSubmenu');






    function updateActiveNav(clickedBtn) {

        document.querySelectorAll('.nav-item').forEach(function (b) {
            b.classList.remove('active');
        });


        if (clickedBtn) {
            clickedBtn.classList.add('active');
        }



        if (clickedBtn && clickedBtn.classList.contains('nav-subitem')) {
            var parent = clickedBtn.closest('.submenu') === customerSubmenu
                ? customerParent
                : staffParent;

            if (parent) parent.classList.add('active');
        } else {
            customerParent.classList.remove('active');
            staffParent.classList.remove('active');
        }
    }







    function runScripts(container) {
        container.querySelectorAll('script').forEach(function (oldScript) {
            var newScript = document.createElement('script');


            Array.prototype.forEach.call(oldScript.attributes, function (attr) {
                newScript.setAttribute(attr.name, attr.value);
            });

            newScript.textContent = oldScript.textContent;


            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }




    var activeModal = null;











    function openModal(options) {

        if (activeModal) {
            activeModal.close();
        }

        var backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';

        var modal = document.createElement('div');
        modal.className = 'modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        var previouslyFocused = document.activeElement;
        var handle = { close: close, element: modal };

        function close() {
            document.removeEventListener('keydown', handleKeydown);
            backdrop.remove();

            if (activeModal === handle) {
                activeModal = null;
            }

            if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                previouslyFocused.focus();
            }
        }

        function handleKeydown(event) {
            if (event.key === 'Escape') {
                close();
            }
        }


        var header = document.createElement('div');
        header.className = 'modal-header';

        var title = document.createElement('h2');
        title.className = 'modal-title';
        title.textContent = options.title || '';

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'modal-close';
        closeBtn.setAttribute('aria-label', 'Close dialog');
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', close);

        header.appendChild(title);
        header.appendChild(closeBtn);


        var body = document.createElement('div');
        body.className = 'modal-body';

        if (typeof options.body === 'string') {
            body.innerHTML = options.body;
        } else if (options.body) {
            body.appendChild(options.body);
        }

        modal.appendChild(header);
        modal.appendChild(body);


        if (options.footer && options.footer.length) {
            var footer = document.createElement('div');
            footer.className = 'modal-footer';

            options.footer.forEach(function (buttonConfig) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn ' + (buttonConfig.className || 'btn-secondary');
                button.textContent = buttonConfig.label;

                button.addEventListener('click', function () {
                    var shouldClose = buttonConfig.onClick
                        ? buttonConfig.onClick(handle) !== false
                        : true;

                    if (shouldClose && buttonConfig.closeOnClick !== false) {
                        close();
                    }
                });

                footer.appendChild(button);
            });

            modal.appendChild(footer);
        }

        backdrop.appendChild(modal);


        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop) {
                close();
            }
        });

        document.body.appendChild(backdrop);
        document.addEventListener('keydown', handleKeydown);


        var focusTarget = modal.querySelector('.modal-body input, .modal-body select, .modal-body textarea')
            || modal.querySelector('button');

        if (focusTarget) {
            focusTarget.focus();
        }

        activeModal = handle;

        return handle;
    }


    window.openAdminModal = openModal;


    window.brewskiReloadView = function () {
        var activeItem = document.querySelector('.nav-item.active[data-view]');

        if (activeItem) {
            loadView(activeItem.getAttribute('data-view'));
        }
    };





    function loadView(viewIdentifier) {


        if (activeModal) {
            activeModal.close();
        }




        if (viewIdentifier === 'home') {
            homeView.classList.remove('hidden');
            dynamicView.classList.add('hidden');
            placeholderView.classList.add('hidden');


            dynamicView.innerHTML = '';
            return;
        }



        if (viewIdentifier.includes('.php') || viewIdentifier.startsWith('../')) {


            dynamicView.innerHTML = '<div style="padding: 20px; text-align: center;"><p>Loading...</p></div>';


            homeView.classList.add('hidden');
            placeholderView.classList.add('hidden');
            dynamicView.classList.remove('hidden');


            fetch(viewIdentifier)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.text();
                })
                .then(html => {

                    dynamicView.innerHTML = html;


                    runScripts(dynamicView);


                    window.scrollTo(0, 0);
                })
                .catch(error => {
                    console.error('Error loading view:', error);

                    dynamicView.innerHTML = `
                        <div style="padding: 20px; color: #dc2626;">
                            <h2>Error Loading Content</h2>
                            <p>${error.message}</p>
                            <button onclick="location.reload()" style="margin-top:10px; padding:8px 16px; cursor:pointer;">Reload Page</button>
                        </div>
                    `;
                });
        }

        else {
            homeView.classList.add('hidden');
            dynamicView.classList.add('hidden');
            placeholderView.classList.remove('hidden');


            placeholderTitle.textContent = viewIdentifier.charAt(0).toUpperCase() + viewIdentifier.slice(1);
        }
    }




    profileBtn.addEventListener('click', function (event) {
        event.stopPropagation();
        var isHidden = profileMenu.classList.toggle('hidden');
        profileBtn.setAttribute('aria-expanded', String(!isHidden));
    });


    document.addEventListener('click', function (event) {
        if (!profileBtn.contains(event.target) && !profileMenu.contains(event.target)) {
            profileMenu.classList.add('hidden');
            profileBtn.setAttribute('aria-expanded', 'false');
        }
    });


    if (customerParent) {
        customerParent.addEventListener('click', function () {
            var isOpen = customerSubmenu.classList.toggle('open');
            customerParent.setAttribute('aria-expanded', String(isOpen));
        });
    }


    if (staffParent) {
        staffParent.addEventListener('click', function () {
            var isOpen = staffSubmenu.classList.toggle('open');
            staffParent.setAttribute('aria-expanded', String(isOpen));
        });
    }


    navItems.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();


            var viewTarget = btn.getAttribute('data-view');


            updateActiveNav(btn);


            loadView(viewTarget);


        });
    });

});