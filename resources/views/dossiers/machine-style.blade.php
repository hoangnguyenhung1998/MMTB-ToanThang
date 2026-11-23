<style>
.machine-dossier { max-width: 1150px; margin-inline: auto; }
.machine-dossier .d-header { gap: 12px; align-items: center; }
.machine-dossier .machine-summary { display:flex; flex-wrap:wrap; align-items:center; gap: 6px 14px; background:#e7f1ff; color:#153255; padding:12px 16px; }
.machine-summary a { font-weight:650; }
.machine-dossier .machine-table { width:100%; }
.machine-dossier .machine-table button[data-open-paper] { white-space:nowrap; }
.machine-table table td { vertical-align:middle; }
.machine-table td:last-child { width:1%; }
.machine-dossier .machine-actions { display:flex; align-items:center; flex-wrap:nowrap; gap:6px; white-space:nowrap; }
.machine-dossier .machine-actions button,.machine-dossier .machine-table [data-open-paper] { padding:6px 9px; }
.machine-dossier .machine-new { background:#e5f5ec; color:#166534; border-color:#a9d6b8; margin:3px 0; }
.machine-dossier tr:target,.machine-dossier #unassigned-drivers:target { outline:2px solid #2a69be; outline-offset:-2px; scroll-margin-top:85px; }
.machine-dossier .machine-fold { border:1px solid #d6e1ef; border-radius:8px; margin-top:10px; padding:9px 12px; }
.machine-fold summary { font-weight:700; cursor:pointer; }
.machine-fold form { margin-top:12px; }
.machine-dossier dialog.machine-dialog { border:1px solid #bdcbe0; border-radius:12px; color:#13243d; background:#fff; padding:0; width:min(480px,calc(100vw - 24px)); max-height:calc(100dvh - 32px); margin:16px 16px 16px auto; box-shadow:0 16px 50px #132a4b38; }
.machine-dossier dialog.machine-page-dialog { width:min(620px,calc(100vw - 24px)); }
.machine-dossier dialog.machine-menu { width:min(320px,calc(100vw - 24px)); }
.machine-dialog::backdrop { background:#162b4629; }
.machine-dialog header { display:flex; align-items:center; justify-content:space-between; gap:12px; border-bottom:1px solid #d6e1ef; padding:13px 16px; }
.machine-dialog h3 { margin:0; font-size:1.15rem; }
.machine-dialog > form,.machine-dialog-body,.machine-menu-items { padding:16px; display:grid; gap:12px; }
.machine-menu-items a,.machine-menu-items button { text-align:left; }
.machine-dialog label { display:grid; gap:4px; }
.machine-dialog [hidden] { display:none; }
.machine-dialog footer { border-top:1px solid #d6e1ef; padding-top:12px; display:flex; justify-content:flex-end; }
.machine-controls { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.machine-controls label { display:flex; align-items:center; gap:4px; }
.machine-page-strip { min-height:150px; display:flex; gap:10px; overflow-x:auto; padding:4px 2px 9px; }
.machine-dossier .machine-page-strip button { flex:0 0 104px; padding:5px; background:#f1f6fd; color:#1a375a; border:1px solid #b9cae0; border-radius:7px; }
.machine-dossier .machine-page-strip button[aria-pressed="true"] { outline:2px solid #1761bf; outline-offset:1px; }
.machine-page-strip img { width:90px; height:125px; object-fit:contain; background:#fff; display:block; }
@media(max-width:560px) {
    .machine-dossier .d-header { flex-wrap:wrap; }
    .machine-dossier .d-header h1 { font-size:1.35rem; }
    .machine-dossier .machine-table thead { display:none; }
    .machine-dossier .machine-table table,.machine-dossier .machine-table tbody { display:block; }
    .machine-dossier .machine-table tr { display:flex; align-items:center; flex-wrap:wrap; padding:6px; }
    .machine-dossier .machine-table td { border:0; width:auto; padding:4px; }
    .machine-dossier .machine-table td:last-child { flex-basis:100%; }
    .machine-dossier dialog.machine-dialog { margin:12px auto; }
}
</style>
