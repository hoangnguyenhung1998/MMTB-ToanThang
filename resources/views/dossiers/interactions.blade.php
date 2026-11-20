@once
<script>
document.addEventListener('DOMContentLoaded', () => {
    const monitors = [...document.querySelectorAll('[data-dossier-progress]')];
    let timer;
    let request;
    async function poll(force = false) {
        clearTimeout(timer);
        if (document.hidden && !force) return;
        request?.abort();
        const current = new AbortController();
        request = current;
        await Promise.all(monitors.filter(panel => panel.dataset.active === '1' || force).map(async panel => {
            if (panel.contains(document.activeElement) && !force) return;
            try {
                const response = await fetch(panel.dataset.dossierProgress, {headers: {Accept: 'application/json'}, cache: 'no-store', signal: current.signal});
                if (!response.ok) return;
                const result = await response.json();
                panel.querySelector('tbody').innerHTML = result.html;
                for (const status of document.querySelectorAll('[data-paper-progress]')) {
                    const row = [...panel.querySelectorAll('[data-progress-key]')].find(item => item.dataset.progressKey === status.dataset.paperProgress);
                    if (row) {
                        status.href = row.dataset.progressLink;
                        status.textContent = row.dataset.progressLabel;
                        status.hidden = false;
                    }
                }
                panel.dataset.active = result.active ? '1' : '0';
                panel.hidden = !result.html.trim();
            } catch { /* Keep server-confirmed information visible until the next poll. */ }
        }));
        if (request === current && monitors.some(panel => panel.dataset.active === '1')) timer = setTimeout(poll, 4000);
    }
    poll();
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(true); });

    function upload(url, data, update) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url);
            xhr.responseType = 'json';
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').content);
            xhr.upload.addEventListener('progress', event => {
                update(event.lengthComputable ? `Đang tải ${Math.floor(100 * event.loaded / event.total)}%` : 'Đang tải…');
            });
            xhr.upload.addEventListener('load', () => update('Đã gửi · chờ máy chủ xác nhận lưu PDF'));
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300 && xhr.response?.url) resolve(xhr.response);
                else reject(Error(Object.values(xhr.response?.errors ?? {}).flat()[0] ?? xhr.response?.message ?? 'Máy chủ chưa xác nhận nhận PDF.'));
            };
            xhr.onerror = xhr.onabort = () => reject(Error('Kết nối bị gián đoạn trước khi xác nhận nhận PDF.'));
            xhr.send(data);
        });
    }
    for (const form of document.querySelectorAll('[data-dossier-upload]')) {
        const submit = form.querySelector('button[type="submit"]');
        const output = form.querySelector('[data-upload-status]');
        const files = form.querySelector('input[type="file"]');
        async function send(file, row) {
            const data = new FormData(form);
            data.set('file', file);
            try {
                const response = await upload(form.action, data, status => { row.textContent = `${file.name} · ${status}`; });
                const link = document.createElement('a');
                link.href = response.url;
                link.textContent = response.duplicate ? 'Mở file đã có' : 'Mở tiến độ';
                row.replaceChildren(`${file.name} · ${response.duplicate ? 'File đã tồn tại' : 'Đã nhận PDF'} · `, link);
                poll(true);
            } catch (error) {
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.textContent = 'Thử lại';
                retry.onclick = () => send(file, row);
                row.replaceChildren(`${file.name} · ${error.message} `, retry);
            }
        }
        form.addEventListener('submit', async event => {
            event.preventDefault();
            submit.disabled = true;
            for (const file of files.files) {
                const row = document.createElement('div');
                output.append(row);
                await send(file, row);
            }
            submit.disabled = false;
        });
    }
    for (const form of document.querySelectorAll('[data-dossier-expiry]')) {
        const type = form.elements.type_id;
        const fields = form.querySelector('[data-expiry-fields]');
        const sync = () => {
            fields.hidden = type.selectedOptions[0]?.dataset.tracked !== '1';
            fields.disabled = fields.hidden;
        };
        type.addEventListener('change', sync);
        sync();
    }
    for (const picker of document.querySelectorAll('[data-existing-paper]')) {
        const form = picker.form;
        const link = form.querySelector('[data-replace-link]');
        const sync = () => {
            const url = picker.selectedOptions[0]?.dataset.replace;
            link.hidden = !url;
            if (url) link.href = url;
            form.querySelector('button[type="submit"]').disabled = !!url;
        };
        picker.addEventListener('change', sync);
        sync();
    }
});
</script>
@endonce
