/* Small progressive enhancements. Every feature also works without JavaScript. */
(function () {
    'use strict';

    // Mobile sidebar
    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () { document.body.classList.add('sidebar-open'); });
    });
    document.querySelectorAll('[data-sidebar-close]').forEach(function (el) {
        el.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); });
    });

    /*
     * Confirmation modal for links and forms with data-confirm.
     * Optional attributes on the same element:
     *   data-confirm-title    heading of the modal        (default "Please confirm")
     *   data-confirm-ok       label of the confirm button (default "Continue")
     *   data-confirm-variant  "danger" for destructive actions
     * Uses <dialog> (focus trap, Esc to close); falls back to window.confirm without it.
     */
    var modal = null;
    var pendingAction = null;

    function buildModal() {
        var d = document.createElement('dialog');
        d.className = 'modal';
        d.setAttribute('aria-labelledby', 'modal-title');
        d.setAttribute('aria-describedby', 'modal-message');
        d.innerHTML =
            '<div class="modal-body">' +
                '<span class="modal-icon" aria-hidden="true"></span>' +
                '<div><h2 id="modal-title"></h2><p id="modal-message"></p></div>' +
            '</div>' +
            '<div class="modal-actions">' +
                '<button type="button" class="btn btn-secondary" data-modal-cancel>Cancel</button>' +
                '<button type="button" class="btn" data-modal-ok></button>' +
            '</div>';
        document.body.appendChild(d);
        d.querySelector('[data-modal-cancel]').addEventListener('click', function () { d.close('cancel'); });
        d.querySelector('[data-modal-ok]').addEventListener('click', function () { d.close('ok'); });
        // Clicking the dimmed backdrop cancels
        d.addEventListener('click', function (e) { if (e.target === d) { d.close('cancel'); } });
        d.addEventListener('close', function () {
            var action = pendingAction;
            pendingAction = null;
            if (d.returnValue === 'ok' && action) { action(); }
        });
        return d;
    }

    function askToConfirm(el, onConfirm) {
        var message = el.getAttribute('data-confirm');
        if (typeof HTMLDialogElement === 'undefined') {
            if (window.confirm(message)) { onConfirm(); }
            return;
        }
        modal = modal || buildModal();
        var danger = el.getAttribute('data-confirm-variant') === 'danger';
        var ok = modal.querySelector('[data-modal-ok]');
        modal.querySelector('#modal-title').textContent = el.getAttribute('data-confirm-title') || 'Please confirm';
        modal.querySelector('#modal-message').textContent = message;
        modal.querySelector('.modal-icon').innerHTML = danger
            ? '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
            : '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
        modal.classList.toggle('modal-danger', danger);
        ok.textContent = el.getAttribute('data-confirm-ok') || 'Continue';
        ok.className = 'btn' + (danger ? ' btn-danger' : '');
        modal.returnValue = '';
        pendingAction = onConfirm;
        modal.showModal();
        // Destructive actions start on Cancel so Enter can't delete by accident.
        modal.querySelector(danger ? '[data-modal-cancel]' : '[data-modal-ok]').focus();
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-confirm]');
        if (!link) { return; }
        e.preventDefault();
        askToConfirm(link, function () { window.location.href = link.href; });
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.hasAttribute('data-confirm') && form.getAttribute('data-confirmed') !== '1') {
            e.preventDefault();
            var submitter = e.submitter || null;
            askToConfirm(form, function () {
                form.setAttribute('data-confirmed', '1');
                if (form.requestSubmit) {
                    form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                } else {
                    form.submit();
                }
            });
            return;
        }
        form.removeAttribute('data-confirmed');
        // Prevent accidental double submission
        form.querySelectorAll('button[type=submit]').forEach(function (b) {
            setTimeout(function () { b.disabled = true; b.setAttribute('data-auto-disabled', ''); }, 0);
        });
    });

    // Re-enable buttons when the page is restored from the back/forward cache
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('[data-auto-disabled]').forEach(function (b) { b.disabled = false; b.removeAttribute('data-auto-disabled'); });
    });

    // Dismissible alerts; success messages fade out on their own after a few seconds
    var dismiss = function (alert) {
        if (!alert || !alert.parentNode) { return; }
        alert.classList.add('is-leaving');
        setTimeout(function () { alert.remove(); }, 300);
    };
    document.querySelectorAll('[data-dismiss]').forEach(function (btn) {
        btn.addEventListener('click', function () { dismiss(btn.closest('.alert')); });
    });
    document.querySelectorAll('[data-flash="success"]').forEach(function (alert) {
        setTimeout(function () { dismiss(alert); }, 6000);
    });

    // Press "/" to jump to the page's search box (ignored while typing in a field)
    var searchBox = document.querySelector('.content input[type=search], .content input[name=q], .content input[name=id_number]');
    var kbdHint = document.querySelector('[data-kbd-hint]');
    if (searchBox && kbdHint) { kbdHint.hidden = false; }
    document.addEventListener('keydown', function (e) {
        if (e.key !== '/' || !searchBox || e.ctrlKey || e.metaKey || e.altKey) { return; }
        var tag = (document.activeElement && document.activeElement.tagName) || '';
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || document.activeElement.isContentEditable) { return; }
        e.preventDefault();
        searchBox.focus();
        searchBox.select();
    });

    // Print buttons
    document.querySelectorAll('[data-print]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.print(); });
    });

    // Live filtering of <select> options: <input data-filter-select="selectId">
    document.querySelectorAll('[data-filter-select]').forEach(function (input) {
        var select = document.getElementById(input.getAttribute('data-filter-select'));
        if (!select) { return; }
        input.addEventListener('input', function () {
            var term = input.value.trim().toLowerCase();
            var firstVisible = null;
            Array.prototype.forEach.call(select.options, function (opt) {
                if (!opt.value) { return; }
                var show = opt.text.toLowerCase().indexOf(term) !== -1;
                opt.hidden = !show;
                if (show && !firstVisible) { firstVisible = opt; }
            });
            if (firstVisible && select.selectedOptions.length && select.selectedOptions[0].hidden) {
                select.value = firstVisible.value;
                select.dispatchEvent(new Event('change'));
            }
        });
    });
})();
