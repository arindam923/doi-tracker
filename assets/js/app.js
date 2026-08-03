/**
 * Ternfluenzy — Client-side JavaScript Helpers
 */

// ─── Clipboard Copy ───
function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
        btn.classList.add('copied');
        setTimeout(() => {
            btn.innerHTML = originalHTML;
            btn.classList.remove('copied');
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
        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
        btn.classList.add('copied');
        setTimeout(() => {
            btn.innerHTML = originalHTML;
            btn.classList.remove('copied');
        }, 2000);
    });
}

// ─── Confirmation Dialog ───
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// ─── Toast Notification ───
function showToast(type, message) {
    let container = document.querySelector('.tf-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'tf-toast-container';
        document.body.appendChild(container);
    }

    const validTypes = ['success', 'danger', 'warning', 'info'];
    const t = validTypes.includes(type) ? type : 'info';

    const toast = document.createElement('div');
    toast.className = `tf-toast toast-${t}`;
    toast.setAttribute('role', 'status');
    toast.innerHTML = `
        <div class="alert-body">${message}</div>
        <button type="button" aria-label="Dismiss" onclick="this.parentElement.remove()">
            <i class="bi bi-x-lg"></i>
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
    document.querySelector('.tf-sidebar, #sidebar').classList.toggle('show');
}

// ─── Chart.js Helper ───
function createLineChart(canvasId, labels, datasets, options = {}) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top' },
        },
        scales: {
            y: { beginAtZero: true, ticks: { precision: 0 } },
        },
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
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            valid = false;
        } else {
            field.classList.remove('is-invalid');
        }
    });

    return valid;
}

// ─── Dropdowns ───
function closeAllDropdowns(except) {
    document.querySelectorAll('.tf-dropdown-menu').forEach(menu => {
        if (menu === except) return;
        menu.hidden = true;
        const trigger = menu.parentElement && menu.parentElement.querySelector('.tf-dropdown-trigger');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    });
}

function toggleDropdown(button) {
    const wrapper = button.closest('.tf-dropdown');
    if (!wrapper) return;
    const menu = wrapper.querySelector('.tf-dropdown-menu');
    if (!menu) return;

    const isOpen = !menu.hidden;
    closeAllDropdowns(menu);
    menu.hidden = isOpen;
    button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
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

// ─── Initialize on DOM Ready ───
document.addEventListener('DOMContentLoaded', () => {
    // Auto-dismiss alerts after 5s
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity .3s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });

    // Copy buttons
    document.querySelectorAll('[data-copy]').forEach(btn => {
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

    // Dropdown triggers
    document.querySelectorAll('.tf-dropdown-trigger').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleDropdown(btn);
        });
    });

    // Modal triggers: data-tf-modal-open="modalId"
    document.querySelectorAll('[data-tf-modal-open]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            openModal(btn.dataset.tfModalOpen);
        });
    });

    // Modal close buttons: [data-tf-modal-close]
    document.querySelectorAll('[data-tf-modal-close]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const modal = btn.closest('.tf-modal');
            if (modal) closeModal(modal);
        });
    });

    // Escape closes the topmost open modal
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const open = document.querySelectorAll('.tf-modal:not([hidden])');
        if (open.length === 0) return;
        closeModal(open[open.length - 1]);
    });

    // Click outside dropdowns closes them
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.tf-dropdown')) {
            closeAllDropdowns();
        }
    });
});