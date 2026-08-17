/**
 * Track Flow — Accessible Client-side JavaScript Helpers
 * Focus management, keyboard navigation, dropdowns, modals, toasts.
 */

function copyToClipboard(text, btn) {
    if (typeof tfCopyText === 'function') {
        tfCopyText(text, btn);
        return;
    }
    window.prompt('Copy this text:', text == null ? '' : String(text));
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

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-geo-picker]').forEach(function (picker) {
        const summary = picker.querySelector('.tf-geo-picker-summary');
        const search = picker.querySelector('.tf-geo-picker-search');
        const chips = picker.querySelector('.tf-geo-picker-chips');
        const countEl = picker.querySelector('.tf-geo-picker-count');
        const options = () => Array.from(picker.querySelectorAll('.tf-geo-picker-option'));

        function checkedOptions() {
            return options().filter(opt => {
                const input = opt.querySelector('input');
                return input && input.checked;
            });
        }

        function refresh() {
            const selected = checkedOptions();
            options().forEach(opt => {
                const input = opt.querySelector('input');
                opt.classList.toggle('is-selected', !!(input && input.checked));
            });
            if (countEl) countEl.textContent = selected.length + ' selected';

            if (summary) {
                if (selected.length === 0) {
                    summary.innerHTML = '<span class="tf-geo-picker-placeholder">Select countries…</span>';
                } else {
                    const preview = selected.slice(0, 3).map(opt => {
                        const flag = opt.querySelector('.tf-flag');
                        const nameEl = opt.querySelector('.tf-geo-picker-name');
                        const name = nameEl ? nameEl.textContent.trim() : '';
                        const flagHtml = flag ? flag.outerHTML : '';
                        return '<span class="tf-geo-picker-preview">' + flagHtml + '<span>' + name + '</span></span>';
                    }).join('');
                    const extra = selected.length > 3
                        ? '<span class="tf-geo-picker-more">+' + (selected.length - 3) + '</span>'
                        : '';
                    summary.innerHTML = preview + extra;
                }
            }

            if (chips) {
                chips.innerHTML = selected.map(opt => {
                    const code = opt.querySelector('input').value;
                    const flag = opt.querySelector('.tf-flag');
                    const name = opt.querySelector('.tf-geo-picker-name').textContent.trim();
                    return '<span class="tf-geo-chip" data-code="' + code + '">' +
                        (flag ? flag.outerHTML : '') +
                        '<span>' + name + '</span>' +
                        '<button type="button" aria-label="Remove ' + name + '">&times;</button></span>';
                }).join('');
                chips.hidden = selected.length === 0;
            }
        }

        if (search) {
            search.addEventListener('input', function () {
                const term = search.value.toLowerCase().trim();
                options().forEach(function (opt) {
                    const hay = opt.textContent.toLowerCase();
                    opt.style.display = (!term || hay.includes(term)) ? '' : 'none';
                });
            });
            search.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') e.preventDefault();
            });
        }

        picker.addEventListener('change', function (e) {
            if (e.target.matches('input[type="checkbox"]')) refresh();
        });

        const selectAll = picker.querySelector('.tf-geo-picker-all');
        const clearAll = picker.querySelector('.tf-geo-picker-none');
        if (selectAll) {
            selectAll.addEventListener('click', function () {
                options().forEach(function (opt) {
                    if (opt.style.display !== 'none') opt.querySelector('input').checked = true;
                });
                refresh();
            });
        }
        if (clearAll) {
            clearAll.addEventListener('click', function () {
                options().forEach(opt => opt.querySelector('input').checked = false);
                refresh();
            });
        }
        if (chips) {
            chips.addEventListener('click', function (e) {
                const btn = e.target.closest('button');
                if (!btn) return;
                const chip = btn.closest('.tf-geo-chip');
                const input = picker.querySelector('input[value="' + chip.dataset.code + '"]');
                if (input) {
                    input.checked = false;
                    refresh();
                }
            });
        }

        refresh();
    });
});

function tfEscapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function tfCloseNicePanels(except) {
    document.querySelectorAll('.tf-nice-panel').forEach((panel) => {
        if (panel === except) return;
        panel.hidden = true;
        const wrap = panel.closest('.tf-nice');
        const trigger = wrap && wrap.querySelector('.tf-nice-trigger');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    });
}

function tfEnhanceSelect(select) {
    if (!select || select.dataset.tfNice === 'off' || select.closest('.tf-nice')) return;

    const isMulti = select.multiple;
    const wrap = document.createElement('div');
    wrap.className = 'tf-nice' + (isMulti ? ' is-multi' : '');
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('tf-nice-native');
    select.tabIndex = -1;

    const optionsOf = () => Array.from(select.options);

    if (!isMulti) {
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'tf-nice-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.innerHTML = '<span class="tf-nice-trigger-label"></span><i class="bi bi-chevron-down tf-nice-chevron" aria-hidden="true"></i>';
        wrap.appendChild(trigger);

        const panel = document.createElement('div');
        panel.className = 'tf-nice-panel';
        panel.hidden = true;
        panel.innerHTML = '<input type="search" class="tf-nice-search form-control" placeholder="Search…" autocomplete="off"><ul class="tf-nice-options" role="listbox"></ul>';
        wrap.appendChild(panel);

        const labelEl = trigger.querySelector('.tf-nice-trigger-label');
        const search = panel.querySelector('.tf-nice-search');
        const list = panel.querySelector('.tf-nice-options');

        function selectedText() {
            const opt = select.options[select.selectedIndex];
            return opt ? opt.textContent.trim() : '';
        }

        function syncTrigger() {
            const text = selectedText();
            const empty = !select.value;
            labelEl.textContent = text || 'Select…';
            labelEl.classList.toggle('is-placeholder', empty);
        }

        function render(term) {
            const q = (term || '').toLowerCase().trim();
            const html = optionsOf().map((opt, idx) => {
                const text = opt.textContent.trim();
                if (q && !text.toLowerCase().includes(q)) return '';
                const selected = opt.selected ? ' is-selected' : '';
                return '<li><button type="button" class="tf-nice-option' + selected + '" data-index="' + idx + '" role="option" aria-selected="' + (opt.selected ? 'true' : 'false') + '">' + tfEscapeHtml(text) + '</button></li>';
            }).join('');
            list.innerHTML = html || '<li class="tf-nice-empty">No matches</li>';
        }

        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const open = panel.hidden;
            tfCloseNicePanels(panel);
            panel.hidden = !open;
            trigger.setAttribute('aria-expanded', String(open));
            if (open) {
                render(search.value);
                setTimeout(() => search.focus(), 0);
            }
        });

        search.addEventListener('input', () => render(search.value));
        search.addEventListener('click', (e) => e.stopPropagation());
        panel.addEventListener('click', (e) => e.stopPropagation());

        list.addEventListener('click', (e) => {
            const btn = e.target.closest('.tf-nice-option');
            if (!btn) return;
            const opt = select.options[Number(btn.dataset.index)];
            if (!opt) return;
            select.selectedIndex = Number(btn.dataset.index);
            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncTrigger();
            tfCloseNicePanels();
        });

        select.addEventListener('change', syncTrigger);
        syncTrigger();
        return;
    }

    const box = document.createElement('div');
    box.className = 'tf-nice-multi';
    box.innerHTML = '<div class="tf-nice-chips"></div>' +
        '<input type="search" class="tf-nice-search form-control" placeholder="Search recipients…" autocomplete="off">' +
        '<ul class="tf-nice-options"></ul>' +
        '<div class="tf-nice-toolbar"><span class="tf-nice-count">0 selected</span><span><button type="button" class="tf-nice-all">Select visible</button> · <button type="button" class="tf-nice-none">Clear</button></span></div>';
    wrap.appendChild(box);

    const chips = box.querySelector('.tf-nice-chips');
    const search = box.querySelector('.tf-nice-search');
    const list = box.querySelector('.tf-nice-options');
    const countEl = box.querySelector('.tf-nice-count');

    function selectedOptions() {
        return optionsOf().filter((o) => o.selected && o.value !== '');
    }

    function parseLabel(text) {
        const match = text.match(/^(.*)\(([^)]+)\)\s*$/);
        if (!match) return { title: text, meta: '' };
        return { title: match[1].trim(), meta: match[2].trim() };
    }

    function renderChips() {
        const selected = selectedOptions();
        chips.innerHTML = selected.map((opt) => {
            const parsed = parseLabel(opt.textContent.trim());
            return '<span class="tf-nice-chip" data-value="' + tfEscapeHtml(opt.value) + '"><span>' + tfEscapeHtml(parsed.title || opt.textContent.trim()) + '</span><button type="button" aria-label="Remove">&times;</button></span>';
        }).join('');
        countEl.textContent = selected.length + ' selected';
        select.dispatchEvent(new Event('tf-nice-sync'));
    }

    function renderList() {
        const q = search.value.toLowerCase().trim();
        const html = optionsOf().map((opt, idx) => {
            if (!opt.value) return '';
            const text = opt.textContent.trim();
            if (q && !text.toLowerCase().includes(q)) return '';
            const parsed = parseLabel(text);
            const selected = opt.selected ? ' is-selected' : '';
            const meta = parsed.meta ? '<span class="tf-nice-option-meta">' + tfEscapeHtml(parsed.meta) + '</span>' : '';
            return '<li><button type="button" class="tf-nice-option' + selected + '" data-index="' + idx + '"><span>' + tfEscapeHtml(parsed.title) + meta + '</span></button></li>';
        }).join('');
        list.innerHTML = html || '<li class="tf-nice-empty">No matches</li>';
    }

    function refresh() {
        renderChips();
        renderList();
    }

    search.addEventListener('input', renderList);
    search.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') e.preventDefault();
    });

    list.addEventListener('click', (e) => {
        const btn = e.target.closest('.tf-nice-option');
        if (!btn) return;
        const opt = select.options[Number(btn.dataset.index)];
        if (!opt) return;
        opt.selected = !opt.selected;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        refresh();
    });

    chips.addEventListener('click', (e) => {
        const btn = e.target.closest('button');
        if (!btn) return;
        const chip = btn.closest('.tf-nice-chip');
        const opt = optionsOf().find((o) => o.value === chip.dataset.value);
        if (opt) {
            opt.selected = false;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            refresh();
        }
    });

    box.querySelector('.tf-nice-all').addEventListener('click', () => {
        list.querySelectorAll('.tf-nice-option').forEach((btn) => {
            const opt = select.options[Number(btn.dataset.index)];
            if (opt) opt.selected = true;
        });
        select.dispatchEvent(new Event('change', { bubbles: true }));
        refresh();
    });
    box.querySelector('.tf-nice-none').addEventListener('click', () => {
        optionsOf().forEach((o) => { o.selected = false; });
        select.dispatchEvent(new Event('change', { bubbles: true }));
        refresh();
    });

    refresh();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select.form-select').forEach(tfEnhanceSelect);
    document.addEventListener('click', () => tfCloseNicePanels());
});
