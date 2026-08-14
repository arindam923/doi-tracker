/**
 * Track Flow — Accessible Client-side JavaScript Helpers
 * Focus management, keyboard navigation, dropdowns, modals, toasts, copy.
 */

// ─── Clipboard Copy ───
function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i><span class="tf-visually-hidden">Copied</span>';
        btn.classList.add('copied');
        btn.setAttribute('aria-label', 'Copied');
        setTimeout(() => {
            btn.innerHTML = originalHTML;
            btn.classList.remove('copied');
            btn.setAttribute('aria-label', 'Copy');
        }, 2000);
    }).catch(() => {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);

        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i><span class="tf-visually-hidden">Copied</span>';
        btn.classList.add('copied');
        setTimeout(() => {
            btn.innerHTML = originalHTML;
            btn.classList.remove('copied');
        }, 2000);
    });
}

// ─── Toast Notification ───
function showToast(type, message) {
    let container = document.querySelector('.tf-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'tf-toast-container';
        container.setAttribute('aria-live', 'polite');
        container.setAttribute('aria-atomic', 'true');
        document.body.appendChild(container);
    }

    const validTypes = ['success', 'danger', 'warning', 'info'];
    const t = validTypes.includes(type) ? type : 'info';

    const toast = document.createElement('div');
    toast.className = `tf-toast toast-${t}`;
    toast.setAttribute('role', t === 'danger' ? 'alert' : 'status');
    toast.setAttribute('aria-live', t === 'danger' ? 'assertive' : 'polite');
    toast.innerHTML = `
        <div class="alert-body">${message}</div>
        <button type="button" aria-label="Dismiss" onclick="this.parentElement.remove()">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.transition = 'opacity .3s, transform .3s';
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(.5rem)';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// ─── Mobile Sidebar Toggle ───
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const toggleBtn = document.querySelector('[aria-controls="sidebar"]');
    if (!sidebar) return;

    const isOpen = sidebar.classList.contains('is-open');
    sidebar.classList.toggle('is-open', !isOpen);
    sidebar.classList.toggle('sidebar-mobile-hidden', isOpen);
    if (backdrop) backdrop.classList.toggle('is-visible', !isOpen);
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', String(!isOpen));

    if (!isOpen) {
        // Focus first nav link when opening
        const firstLink = sidebar.querySelector('.tf-nav-link');
        if (firstLink) firstLink.focus();
    }
}

// ─── Chart.js Helper ───
function createLineChart(canvasId, labels, datasets, options = {}) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    };

    return new Chart(ctx, {
        type: options.type || 'line',
        data: { labels, datasets },
        options: { ...defaultOptions, ...options },
    });
}

// ─── Form Validation ───
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return true;

    const requiredFields = form.querySelectorAll('[required]');
    let valid = true;

    requiredFields.forEach(field => {
        const isEmpty = !field.value.trim();
        field.classList.toggle('is-invalid', isEmpty);
        if (isEmpty) {
            field.setAttribute('aria-invalid', 'true');
        } else {
            field.removeAttribute('aria-invalid');
        }
        // Associate feedback if present
        const feedbackId = field.id ? `${field.id}-feedback` : null;
        if (feedbackId) {
            const feedback = document.getElementById(feedbackId);
            if (feedback) feedback.hidden = !isEmpty;
        }
        if (isEmpty) valid = false;
    });

    return valid;
}

// ─── Dropdowns ───
let activeDropdown = null;

function closeAllDropdowns(exceptMenu) {
    document.querySelectorAll('.tf-dropdown-menu').forEach(menu => {
        if (menu === exceptMenu) return;
        menu.hidden = true;
        menu.classList.remove('tf-dropdown-menu-floating', 'is-above', 'is-below');
        menu.style.top = '';
        menu.style.left = '';
        menu.style.right = '';
        menu.style.maxHeight = '';
    });
    document.querySelectorAll('.tf-dropdown-trigger[aria-expanded="true"]').forEach(t => {
        t.setAttribute('aria-expanded', 'false');
    });
    if (activeDropdown && activeDropdown.menu !== exceptMenu) {
        activeDropdown = null;
    }
}

function positionDropdown(trigger, menu) {
    if (!trigger || !menu) return;

    if (menu.parentElement !== document.body) {
        document.body.appendChild(menu);
    }

    menu.classList.add('tf-dropdown-menu-floating');

    const wasHidden = menu.hidden;
    if (wasHidden) {
        menu.style.visibility = 'hidden';
        menu.hidden = false;
    }

    const rect = trigger.getBoundingClientRect();
    const gutter = 8;
    const viewportW = window.innerWidth;
    const viewportH = window.innerHeight;

    menu.style.maxHeight = `calc(100vh - ${gutter * 2}px)`;

    const menuW = menu.offsetWidth || 220;
    const menuH = menu.offsetHeight || 250;

    let left = rect.right - menuW;
    if (left + menuW > viewportW - gutter) left = viewportW - menuW - gutter;
    if (left < gutter) left = gutter;

    const spaceAbove = rect.top - gutter;
    const spaceBelow = viewportH - rect.bottom - gutter;
    const opensAbove = spaceAbove > spaceBelow && spaceAbove >= menuH;

    let top = 0;
    let direction = 'below';

    if (opensAbove || (spaceBelow < menuH && spaceAbove > spaceBelow)) {
        direction = 'above';
        top = rect.top - menuH - 4;
        if (top < gutter) {
            top = gutter;
            menu.style.maxHeight = `${Math.max(100, spaceAbove)}px`;
        }
    } else {
        direction = 'below';
        top = rect.bottom + 4;
        if (top + menuH > viewportH - gutter) {
            menu.style.maxHeight = `${Math.max(100, spaceBelow)}px`;
        }
    }

    menu.style.left = `${Math.round(left)}px`;
    menu.style.top = `${Math.round(top)}px`;
    menu.style.right = 'auto';
    menu.classList.toggle('is-above', direction === 'above');
    menu.classList.toggle('is-below', direction === 'below');

    if (wasHidden) {
        menu.style.visibility = '';
    }

    trigger.setAttribute('aria-expanded', 'true');
    activeDropdown = { trigger, menu };
}

function toggleDropdown(trigger) {
    const wrapper = trigger.closest('.tf-dropdown');
    let menu = trigger._tfMenu;

    if (!menu && wrapper) {
        menu = wrapper.querySelector('.tf-dropdown-menu');
    }
    if (!menu && trigger.nextElementSibling && trigger.nextElementSibling.classList.contains('tf-dropdown-menu')) {
        menu = trigger.nextElementSibling;
    }
    if (!menu) return;

    trigger._tfMenu = menu;
    menu._tfTrigger = trigger;

    const isOpen = !menu.hidden && activeDropdown && activeDropdown.menu === menu;

    closeAllDropdowns(menu);

    if (isOpen) {
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        activeDropdown = null;
    } else {
        positionDropdown(trigger, menu);
        menu.hidden = false;
        // Focus first menu item
        const firstItem = menu.querySelector('[role="menuitem"]');
        if (firstItem) setTimeout(() => firstItem.focus(), 0);
    }
}

function focusNextMenuItem(menu, current, direction) {
    const items = Array.from(menu.querySelectorAll('[role="menuitem"]'));
    if (!items.length) return;
    const index = items.indexOf(current);
    let nextIndex = index + direction;
    if (nextIndex < 0) nextIndex = items.length - 1;
    if (nextIndex >= items.length) nextIndex = 0;
    items[nextIndex].focus();
}

// ─── Modals ───
function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    const focusable = modal.querySelector('input, select, textarea, button, a[href]');
    if (focusable) setTimeout(() => focusable.focus(), 50);
}

function closeModal(id) {
    const modal = typeof id === 'string' ? document.getElementById(id) : id;
    if (!modal) return;
    modal.hidden = true;
    document.body.style.overflow = '';
}

function _closeAnyOpenModal() {
    document.querySelectorAll('.tf-modal:not([hidden])').forEach(m => closeModal(m));
}

function getModalFocusables(modal) {
    return Array.from(modal.querySelectorAll(
        'a[href]:not([disabled]), button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    ));
}

// ─── Initialize on DOM Ready ───
document.addEventListener('DOMContentLoaded', () => {
    // Auto-dismiss Bootstrap alerts after 5s
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity .3s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });

    // Copy buttons
    document.querySelectorAll('[data-copy]').forEach(btn => {
        if (!btn.hasAttribute('aria-label')) btn.setAttribute('aria-label', 'Copy to clipboard');
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            copyToClipboard(btn.dataset.copy, btn);
        });
    });

    // Confirm dialogs
    document.querySelectorAll('[data-confirm]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            if (!confirm(btn.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    // Global Event Delegation for Dropdowns and Modals
    document.addEventListener('click', (e) => {
        // Dropdown trigger
        const trigger = e.target.closest('.tf-dropdown-trigger');
        if (trigger) {
            e.preventDefault();
            e.stopPropagation();
            toggleDropdown(trigger);
            return;
        }

        // Modal open trigger
        const modalBtn = e.target.closest('[data-tf-modal-open]');
        if (modalBtn) {
            e.preventDefault();
            closeAllDropdowns();
            openModal(modalBtn.dataset.tfModalOpen);
            return;
        }

        // Modal close trigger
        const modalCloseBtn = e.target.closest('[data-tf-modal-close]');
        if (modalCloseBtn) {
            e.preventDefault();
            const modal = modalCloseBtn.closest('.tf-modal');
            if (modal) closeModal(modal);
            return;
        }

        // Dropdown item click
        const dropdownItem = e.target.closest('.tf-dropdown-item');
        if (dropdownItem) {
            setTimeout(() => closeAllDropdowns(), 50);
            return;
        }

        // Click outside active dropdown
        if (!e.target.closest('.tf-dropdown-menu')) {
            closeAllDropdowns();
        }
    });

    // Keyboard navigation
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (activeDropdown) {
                const trigger = activeDropdown.trigger;
                closeAllDropdowns();
                if (trigger) trigger.focus();
                return;
            }
            const openModals = document.querySelectorAll('.tf-modal:not([hidden])');
            if (openModals.length > 0) {
                closeModal(openModals[openModals.length - 1]);
            }
            return;
        }

        // Dropdown arrow navigation
        if (activeDropdown && activeDropdown.menu && !activeDropdown.menu.hidden) {
            const focused = document.activeElement;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                focusNextMenuItem(activeDropdown.menu, focused, 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                focusNextMenuItem(activeDropdown.menu, focused, -1);
            } else if (e.key === 'Home') {
                e.preventDefault();
                const first = activeDropdown.menu.querySelector('[role="menuitem"]');
                if (first) first.focus();
            } else if (e.key === 'End') {
                e.preventDefault();
                const items = activeDropdown.menu.querySelectorAll('[role="menuitem"]');
                if (items.length) items[items.length - 1].focus();
            }
        }

        // Modal focus trap
        const openModalEl = document.querySelector('.tf-modal:not([hidden])');
        if (openModalEl && e.key === 'Tab') {
            const focusables = getModalFocusables(openModalEl);
            if (focusables.length === 0) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    // Smooth repositioning on scroll/resize
    let scrollAnimationFrame = false;
    const updatePosition = () => {
        if (activeDropdown && activeDropdown.trigger && activeDropdown.menu && !activeDropdown.menu.hidden) {
            positionDropdown(activeDropdown.trigger, activeDropdown.menu);
        }
        scrollAnimationFrame = false;
    };

    window.addEventListener('scroll', () => {
        if (!scrollAnimationFrame) {
            requestAnimationFrame(updatePosition);
            scrollAnimationFrame = true;
        }
    }, true);

    window.addEventListener('resize', () => {
        if (!scrollAnimationFrame) {
            requestAnimationFrame(updatePosition);
            scrollAnimationFrame = true;
        }
    });
});
