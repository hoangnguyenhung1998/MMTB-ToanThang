<script>
document.querySelector('a[href="#dossier-history"]')?.addEventListener('click', () => {
    document.getElementById('dossier-history').open = true;
});
document.querySelectorAll('[data-machine-expiry]').forEach(form => {
    const sync = () => {
        const precision = form.elements.expiry_precision.value;
        form.querySelectorAll('[data-expiry-mode]').forEach(label => {
            label.hidden = label.dataset.expiryMode !== precision;
            label.querySelector('input').disabled = label.hidden;
        });
    };
    form.addEventListener('change', sync);
    sync();
});
document.querySelectorAll('[data-open-paper]').forEach(button => button.addEventListener('click', () => {
    const dialog = document.getElementById(button.dataset.openPaper);
    const parent = button.closest('dialog');
    parent?.close();
    dialog.showModal();
}));
document.querySelectorAll('[data-close-paper]').forEach(button => button.addEventListener('click', () => {
    button.closest('dialog').close();
}));
document.querySelectorAll('[data-page-editor]').forEach(form => {
    const strip = form.querySelector('.machine-page-strip');
    const picker = form.querySelector('[data-source-page]');
    const note = form.querySelector('[data-page-note]');
    const order = form.elements.page_order;
    const original = order.value.split(',').filter(Boolean);
    const pages = [...original];
    const transfers = new Set();
    let selected = pages[0];
    function render() {
        order.value = pages.join(',');
        if (form.elements.transfer) form.elements.transfer.value = transfers.size ? '1' : '0';
        strip.replaceChildren(...pages.map(page => {
            const card = document.createElement('button');
            card.type = 'button';
            card.setAttribute('aria-pressed', String(page === selected));
            const image = document.createElement('img');
            image.src = picker.options[Number(page) - 1].dataset.thumbnail;
            image.alt = '';
            image.loading = 'lazy';
            card.append(image, 'Trang ' + page);
            card.addEventListener('click', () => { selected = page; render(); });
            return card;
        }));
        form.querySelector('[type="submit"], footer button').disabled = !pages.length;
    }
    form.querySelectorAll('[data-page-action]').forEach(button => button.addEventListener('click', () => {
        const action = button.dataset.pageAction;
        const index = pages.indexOf(selected);
        note.textContent = '';
        if (action === 'add' || action === 'transfer') {
            const page = picker.value;
            if (pages.includes(page)) { note.textContent = 'Trang đã có trong giấy.'; return; }
            pages.push(page);
            if (action === 'transfer') transfers.add(page);
            selected = page;
        } else if (action === 'remove' && index >= 0) {
            if (pages.length === 1) { note.textContent = 'Giữ lại ít nhất một trang trong giấy.'; return; }
            transfers.delete(selected);
            pages.splice(index, 1);
            selected = pages[Math.min(index, pages.length - 1)];
        } else {
            const next = index + (action === 'up' ? -1 : 1);
            if (index >= 0 && next >= 0 && next < pages.length) [pages[index], pages[next]] = [pages[next], pages[index]];
        }
        render();
    }));
    form.closest('dialog').addEventListener('close', () => {
        pages.splice(0, pages.length, ...original);
        transfers.clear();
        selected = pages[0];
        note.textContent = '';
        render();
    });
    render();
});
</script>
