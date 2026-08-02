document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (event) {
        const target = event.target.closest('[data-confirm]');
        if (!target) return;
        if (!window.confirm(target.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    document.querySelectorAll('[data-assignee-selector]').forEach(function (container) {
        const search = container.querySelector('[data-assignee-search]');
        const select = container.querySelector('[data-assignee-select]');
        const clear = container.querySelector('[data-assignee-clear]');
        const empty = container.querySelector('[data-assignee-empty]');

        if (!search || !select) return;

        const initialValue = select.value;
        const users = Array.from(select.options)
            .filter(function (option) { return option.value !== ''; })
            .map(function (option) {
                return {
                    value: option.value,
                    label: option.textContent || '',
                    search: option.getAttribute('data-search') || option.textContent || ''
                };
            });

        let selectedValue = initialValue;

        function normalized(value) {
            let text = String(value || '');
            if (typeof text.normalize === 'function') {
                text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }
            return text.toLowerCase();
        }

        function appendOption(value, label, selected) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            option.selected = selected;
            select.appendChild(option);
        }

        function renderOptions() {
            const term = normalized(search.value.trim());
            const matches = users.filter(function (user) {
                return term === '' || normalized(user.search).includes(term);
            });

            select.innerHTML = '';
            appendOption('', 'Nessun assegnatario', selectedValue === '');

            const selectedUser = users.find(function (user) {
                return user.value === selectedValue;
            });

            if (selectedUser && !matches.some(function (user) { return user.value === selectedValue; })) {
                appendOption(selectedUser.value, selectedUser.label, true);
            }

            matches.forEach(function (user) {
                appendOption(user.value, user.label, user.value === selectedValue);
            });

            if (empty) {
                empty.classList.toggle('d-none', matches.length !== 0);
            }
        }

        search.addEventListener('input', renderOptions);

        select.addEventListener('change', function () {
            selectedValue = select.value;
        });

        if (clear) {
            clear.addEventListener('click', function () {
                selectedValue = '';
                search.value = '';
                renderOptions();
                select.value = '';
                search.focus();
            });
        }

        renderOptions();
    });
});

window.copyText = function (text, button) {
    if (!navigator.clipboard) {
        window.prompt('Copia il testo:', text);
        return;
    }

    navigator.clipboard.writeText(text).then(function () {
        const original = button.innerHTML;
        button.innerHTML = '<i class="bi bi-check2"></i> Copiato';
        setTimeout(function () { button.innerHTML = original; }, 1300);
    });
};
