/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 *
 * Simplex GUI Client Dispatcher
 */

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('harvesterForm');
    const screen = document.getElementById('terminalScreen');
    const btnClear = document.getElementById('btnClear');
    const chkAutoScroll = document.getElementById('chkAutoScroll');
    const configSelect = document.getElementById('configSelect');
    const customConfigInput = document.getElementById('customConfigInput');

    // Toggle custom config text field
    if (configSelect && customConfigInput) {
        configSelect.addEventListener('change', () => {
            if (configSelect.value === '__custom__') {
                customConfigInput.style.display = 'block';
                customConfigInput.focus();
            } else {
                customConfigInput.style.display = 'none';
            }
        });
    }

    // Clear terminal screen
    if (btnClear && screen) {
        btnClear.addEventListener('click', () => {
            screen.innerHTML = '';
        });
    }

    // Intercept action button clicks for asynchronous AJAX execution
    const actionButtons = document.querySelectorAll('button.btn-action');
    actionButtons.forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const action = btn.getAttribute('data-action');
            if (!action) return;

            // Gather parameters
            const runBuild = document.getElementById('flagRunBuild')?.checked || false;
            const dryRun = document.getElementById('flagDryRun')?.checked || false;
            const defaultFallback = document.getElementById('flagDefaultFallback')?.checked || false;
            const allFlag = document.getElementById('flagAll')?.checked || false;
            const purgeFlag = document.getElementById('flagPurge')?.checked || false;
            const selectLatest = document.getElementById('flagSelectLatest')?.checked || false;
            const disableZip = document.getElementById('flagDisableZip')?.checked || false;
            const scanMaxDepth = document.getElementById('optScanMaxDepth')?.value.trim() || '';
            const specId = document.getElementById('optSpecId')?.value.trim() || '';

            let configPath = configSelect ? configSelect.value : '';
            if (configPath === '__custom__') {
                configPath = customConfigInput ? customConfigInput.value.trim() : '';
            }

            // Disable buttons during execution
            actionButtons.forEach(b => b.disabled = true);
            const originalText = btn.textContent;
            btn.textContent = '...';

            const payload = {
                action: action,
                runBuild: runBuild,
                dryRun: dryRun,
                defaultFallback: defaultFallback,
                all: allFlag,
                purge: purgeFlag,
                selectLatest: selectLatest,
                disableZip: disableZip,
                scanMaxDepth: scanMaxDepth,
                specId: specId,
                config: configPath
            };

            const timestamp = new Date().toLocaleTimeString();
            appendLog(`[${timestamp}] $ php harvester.php ${buildCliString(payload)}`, 'log-command');

            try {
                const response = await fetch('?api=1', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();
                if (data.output) {
                    appendLog(data.output, 'log-output');
                } else if (data.error) {
                    appendLog('[ERROR] ' + data.error, 'log-output error');
                }

                // If status data provided, update footer
                if (data.status) {
                    updateFooter(data.status);
                }
            } catch (err) {
                appendLog('[NETWORK/ENGINE ERROR] ' + err.message, 'log-output error');
            } finally {
                btn.textContent = originalText;
                actionButtons.forEach(b => b.disabled = false);
            }
        });
    });

    function buildCliString(p) {
        let cmd = p.action;
        if (p.runBuild) cmd += ' --RunBuild';
        if (p.dryRun) cmd += ' --DryRun';
        if (p.defaultFallback) cmd += ' --UseDefaultFallback';
        if (p.all) cmd += ' --all';
        if (p.purge) cmd += ' --purge';
        if (p.selectLatest) cmd += ' --select-latest';
        if (p.disableZip) cmd += ' --disable-zip';
        if (p.scanMaxDepth !== undefined && p.scanMaxDepth !== '') cmd += ` --scan-max-depth=${p.scanMaxDepth}`;
        if (p.specId) cmd += ` --id=${p.specId}`;
        if (p.config) cmd += ` --config=${p.config}`;
        return cmd;
    }

    function appendLog(text, className) {
        const div = document.createElement('div');
        div.className = className;
        div.textContent = text;
        screen.appendChild(div);

        if (chkAutoScroll && chkAutoScroll.checked) {
            screen.scrollTop = screen.scrollHeight;
        }
    }

    function updateFooter(s) {
        const elStaged = document.getElementById('statStaged');
        const elCommitted = document.getElementById('statCommitted');
        const elEjected = document.getElementById('statEjected');
        if (elStaged && s.staged !== undefined) elStaged.textContent = s.staged;
        if (elCommitted && s.committed !== undefined) elCommitted.textContent = s.committed;
        if (elEjected && s.ejected !== undefined) elEjected.textContent = s.ejected;
    }
});
