(function () {
    // Sort search table
    var sel = document.getElementById('stl-sort');
    var tbl = document.getElementById('stl-search-table');
    if (sel && tbl) {
        sel.addEventListener('change', function () {
            var rows = Array.from(tbl.querySelectorAll('tbody tr'));
            var dir = this.value;
            rows.sort(function (a, b) {
                var ia = parseInt(a.dataset.idx), ib = parseInt(b.dataset.idx);
                var ra = parseInt(a.dataset.results), rb = parseInt(b.dataset.results);
                switch (dir) {
                    case 'time-desc': return ia - ib;
                    case 'time-asc': return ib - ia;
                    case 'results-desc': return rb - ra || ia - ib;
                    case 'results-asc': return ra - rb || ia - ib;
                    default: return 0;
                }
            });
            rows.forEach(function (r) {
                tbl.querySelector('tbody').appendChild(r); });
        });
    }

    // Restore wizard step progress from localStorage
    try {
        var stored = localStorage.getItem('stl_wizard_seen');
        if (stored) {
            var seen = JSON.parse(stored);
            document.querySelectorAll('.stl-wizard-step').forEach(function (step) {
                var num = step.dataset.step || (Array.from(step.parentNode.children).indexOf(step) + 1);
                if (seen.indexOf(num) !== -1) {
                    step.classList.add('stl-wizard-step--done');
                    var bullet = step.querySelector('.stl-wizard-step-bullet');
                    if (bullet) {
                        bullet.textContent = '✓';
                    }
                }
            });
        }
    } catch (e) {
    }

    // Mark visible steps as seen
    var wizardCard = document.getElementById('stl-openai-guide');
    if (wizardCard && wizardCard.style.display !== 'none') {
        setTimeout(function () {
            try {
                var seen = [];
                document.querySelectorAll('.stl-wizard-step').forEach(function (step) {
                    seen.push(step.dataset.step || (Array.from(step.parentNode.children).indexOf(step) + 1));
                });
                localStorage.setItem('stl_wizard_seen', JSON.stringify(seen));
            } catch (e) {
            }
        }, 500);
    }
})();

function stlCopy(id, btn)
{
    var el = document.getElementById(id);
    navigator.clipboard.writeText(el.textContent).then(function () {
        btn.classList.add('stl-copy-btn--copied');
        setTimeout(function () {
            btn.classList.remove('stl-copy-btn--copied');
        }, 1500);
    });
}

function stlCopyMCP()
{
    var el = document.getElementById('stl-mcp-url');
    var btn = document.getElementById('stl-copy-mcp-btn');
    if (!el || !btn) {
        return;
    }
    navigator.clipboard.writeText(el.textContent.trim()).then(function () {
        btn.classList.add('stl-copy-btn--copied');
        btn.textContent = '✓ Copied';
        setTimeout(function () {
            btn.classList.remove('stl-copy-btn--copied');
            btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="vertical-align:-2px" aria-hidden="true"><rect width="14" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Copy';
        }, 2000);
        var step2 = document.querySelector('.stl-wizard-step[data-step="2"]');
        if (step2) {
            step2.classList.add('stl-wizard-step--done');
            step2.querySelector('.stl-wizard-step-bullet').textContent = '✓';
        }
    });
}

// ── v1.10.0: one-click connect (copy + deep link in a single click) ──
function stlConnectCopyText(text, btn)
{
    var done = function () {
        if (btn) {
            btn.classList.add('stl-copy-btn--copied');
            setTimeout(function () {
                btn.classList.remove('stl-copy-btn--copied');
            }, 1500);
        }
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done).catch(function () {
            stlConnectCopyFallback(text);
            done();
        });
        return;
    }
    // Non-secure-context admin (http on LAN/IP): execCommand fallback.
    stlConnectCopyFallback(text);
    done();
}

function stlConnectCopyFallback(text)
{
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
    } catch (e) {
    }
    document.body.removeChild(ta);
}

function stlConnectChatGPT(btn)
{
    var el = document.getElementById('stl-endpoint');
    if (!el || !el.textContent.trim()) {
        return;
    }
    stlConnectCopyText(el.textContent.trim(), btn);
    window.open('https://chatgpt.com/#settings/Connectors', '_blank', 'noopener');
}

function stlConnectClaude(btn)
{
    var el = document.getElementById('stl-config');
    if (!el || !el.textContent.trim()) {
        return;
    }
    stlConnectCopyText(el.textContent.trim(), btn);
    window.open('https://claude.ai/settings/connectors', '_blank', 'noopener');
}

function stlToggleWizard()
{
    var card = document.getElementById('stl-openai-guide');
    var btn = document.getElementById('stl-wizard-toggle-btn');
    if (!card || !btn) {
        return;
    }
    if (card.style.display === 'none') {
        card.style.display = '';
        btn.classList.add('stl-wizard-toggle--active');
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        card.style.display = 'none';
        btn.classList.remove('stl-wizard-toggle--active');
    }
}

function stlCloseWizard()
{
    var card = document.getElementById('stl-openai-guide');
    var btn = document.getElementById('stl-wizard-toggle-btn');
    if (card) {
        card.style.display = 'none';
    }
    if (btn) {
        btn.classList.remove('stl-wizard-toggle--active');
    }
}

function stlDismissWizard()
{
    stlCloseWizard();
}

function stlDismissGuide()
{
    stlCloseWizard();
}
