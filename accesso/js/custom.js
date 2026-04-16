(function (window, document) {
    'use strict';

    if (typeof window.showMsg !== 'function') {
        window.showMsg = function (text, ok) {
            var msg = String(text || '').trim();
            if (!msg) {
                return;
            }

            var root = document.getElementById('cv-toast-msg');
            if (!root) {
                root = document.createElement('div');
                root.id = 'cv-toast-msg';
                root.className = 'cv-toast-msg';
                document.body.appendChild(root);
            }

            root.textContent = msg;
            root.classList.remove('cv-toast-success', 'cv-toast-error');
            root.classList.add(ok ? 'cv-toast-success' : 'cv-toast-error');
            root.classList.add('cv-toast-show');

            window.clearTimeout(window.showMsg._timer);
            window.showMsg._timer = window.setTimeout(function () {
                root.classList.remove('cv-toast-show');
            }, 3200);
        };
    }

    function updateToggleButton(button, input) {
        var icon = button.querySelector('i');
        var isVisible = input.type === 'text';

        button.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
        button.setAttribute('aria-label', isVisible ? 'Nascondi password' : 'Mostra password');

        if (!icon) {
            return;
        }

        icon.classList.remove('fa-eye', 'fa-eye-slash');
        icon.classList.add(isVisible ? 'fa-eye-slash' : 'fa-eye');
    }

    function togglePassword(button) {
        var targetId = button.getAttribute('data-password-toggle');
        if (!targetId) {
            return;
        }

        var input = document.getElementById(targetId);
        if (!input) {
            return;
        }

        input.type = input.type === 'password' ? 'text' : 'password';
        updateToggleButton(button, input);
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-password-toggle]');
        if (!button) {
            return;
        }

        event.preventDefault();
        togglePassword(button);
    });

    document.addEventListener('DOMContentLoaded', function () {
        function setupModernDateInputs() {
            if (window.flatpickr && window.flatpickr.l10ns && window.flatpickr.l10ns.it) {
                window.flatpickr.localize(window.flatpickr.l10ns.it);
            }

            var dateInputs = document.querySelectorAll('input[type="date"]');
            Array.prototype.forEach.call(dateInputs, function (input) {
                if (!input || input.getAttribute('data-cv-date-upgraded') === '1') {
                    return;
                }

                input.classList.add('cv-modern-date-input');
                input.setAttribute('data-cv-date-upgraded', '1');

                if (window.flatpickr && !input._flatpickr && !input.disabled) {
                    try {
                        window.flatpickr(input, {
                            dateFormat: 'Y-m-d',
                            disableMobile: true,
                            monthSelectorType: 'static',
                            prevArrow: '<i class="fa fa-angle-left"></i>',
                            nextArrow: '<i class="fa fa-angle-right"></i>',
                            onReady: function (selectedDates, dateStr, instance) {
                                if (instance && instance.calendarContainer) {
                                    instance.calendarContainer.classList.add('cv-flatpickr-popup');
                                }
                            }
                        });
                    } catch (error) {
                        // fallback to native input date
                    }
                }

                var openPicker = function () {
                    if (this._flatpickr && typeof this._flatpickr.open === 'function') {
                        this._flatpickr.open();
                        return;
                    }
                    if (typeof this.showPicker === 'function') {
                        try {
                            this.showPicker();
                        } catch (error) {
                            // Some browsers block showPicker in specific contexts.
                        }
                    }
                };

                input.addEventListener('focus', openPicker);
                input.addEventListener('click', openPicker);
                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        openPicker.call(this);
                    }
                });
            });
        }

        var toggles = document.querySelectorAll('[data-password-toggle]');
        Array.prototype.forEach.call(toggles, function (button) {
            var targetId = button.getAttribute('data-password-toggle');
            var input = targetId ? document.getElementById(targetId) : null;
            if (input) {
                updateToggleButton(button, input);
            }
        });

        setupModernDateInputs();

        function toggleDrawer(drawerId, shouldOpen) {
            if (!drawerId) {
                return;
            }

            var drawer = document.getElementById(drawerId);
            if (!drawer) {
                return;
            }

            var open = typeof shouldOpen === 'boolean'
                ? shouldOpen
                : !drawer.classList.contains('is-open');

            if (open) {
                var openedDrawers = document.querySelectorAll('.cv-side-drawer.is-open');
                Array.prototype.forEach.call(openedDrawers, function (openedDrawer) {
                    if (openedDrawer.id !== drawerId) {
                        toggleDrawer(openedDrawer.id, false);
                    }
                });
            }

            drawer.classList.toggle('is-open', open);
            drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
            var backdrops = document.querySelectorAll('[data-cv-drawer-close="' + drawerId + '"].cv-side-drawer-backdrop');
            Array.prototype.forEach.call(backdrops, function (backdrop) {
                backdrop.classList.toggle('is-open', open);
            });

            var toggles = document.querySelectorAll('[data-cv-drawer-toggle="' + drawerId + '"]');
            Array.prototype.forEach.call(toggles, function (button) {
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
            });

            var anyOpen = document.querySelector('.cv-side-drawer.is-open') !== null;
            document.body.classList.toggle('cv-drawer-open', anyOpen);
        }

        document.addEventListener('click', function (event) {
            var openButton = event.target.closest('[data-cv-drawer-toggle]');
            if (openButton) {
                event.preventDefault();
                toggleDrawer(openButton.getAttribute('data-cv-drawer-toggle'));
                return;
            }

            var closeButton = event.target.closest('[data-cv-drawer-close]');
            if (closeButton) {
                event.preventDefault();
                toggleDrawer(closeButton.getAttribute('data-cv-drawer-close'), false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            var drawers = document.querySelectorAll('.cv-side-drawer.is-open');
            Array.prototype.forEach.call(drawers, function (drawer) {
                toggleDrawer(drawer.id, false);
            });
        });
    });
}(window, document));
