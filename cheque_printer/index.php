<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HDFC Cheque Printer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        html, body { margin: 0; padding: 0; background: #e9ecef; }
        .toolbar {
            position: sticky; top: 0; z-index: 50;
            padding: 10px 16px; background: #212529; color: #fff;
            display: flex; align-items: center; gap: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .toolbar .title { font-weight: 600; margin-right: auto; }
        .layout { display: grid; grid-template-columns: 360px 1fr; gap: 16px; padding: 16px; align-items: start; }
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
        .form-card {
            background: #fff; padding: 16px; border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
        .form-card h6 { margin-top: 12px; }
        .preview-wrap {
            background: #fff; padding: 16px; border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            overflow: auto;
        }

        /* Cheque sizes (CTS-2010): 203.2mm x 93.13mm */
        .cheque {
            width: 203.2mm; height: 93.13mm;
            background: #f7f9fc;
            position: relative;
            border: 1px solid #b6c2d2;
            overflow: hidden;
            font-family: 'Courier New', Courier, monospace;
            color: #000;
            margin: 0;
        }
        .cheque .grid {
            position: absolute; inset: 0;
            background-image:
                linear-gradient(to right, rgba(0,0,0,0.05) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(0,0,0,0.05) 1px, transparent 1px);
            background-size: 10mm 10mm;
            pointer-events: none;
        }
        .cheque .hint {
            position: absolute; font-size: 7pt; color: #b0b0b0;
            pointer-events: none; user-select: none;
        }
        .fld {
            position: absolute;
            font-size: 12pt;
            color: #000;
            white-space: nowrap;
            line-height: 1;
        }
        .fld.date {
            font-family: 'Courier New', Courier, monospace;
            font-weight: bold;
            letter-spacing: 2.4mm;
            font-size: 13pt;
        }
        .fld.payee {
            font-family: Arial, Helvetica, sans-serif;
            font-weight: bold;
            font-size: 13pt;
        }
        .fld.words {
            font-family: Arial, Helvetica, sans-serif;
            font-weight: bold;
            font-size: 11pt;
            white-space: normal;
        }
        .fld.figures {
            font-family: 'Courier New', Courier, monospace;
            font-weight: bold;
            font-size: 13pt;
        }

        /* Print: portrait page = cheque rotated 90 deg so date box prints first */
        @media print {
            html, body { background: #fff; }
            .no-print { display: none !important; }
            .layout { display: block; padding: 0; }
            .preview-wrap { background: none; box-shadow: none; padding: 0; border: 0; overflow: visible; }

            @page {
                size: 93.13mm 203.2mm;
                margin: 0;
            }
            body { margin: 0; }

            .cheque {
                background: transparent !important;
                border: 0 !important;
                position: absolute; top: 0; left: 0;
                transform-origin: 0 0;
                transform: translate(0, 203.2mm) rotate(-90deg);
            }
            .cheque .grid, .cheque .hint { display: none !important; }
        }
    </style>
</head>
<body>

<div class="toolbar no-print">
    <div class="title">HDFC Cheque Printer</div>
    <span class="text-secondary small">Feed cheque vertically, date box first</span>
    <button class="btn btn-sm btn-outline-light" id="toggleGridBtn">Hide Grid</button>
    <button class="btn btn-sm btn-success" id="printBtn">Print Cheque</button>
</div>

<div class="layout">
    <div class="form-card no-print">
        <h5 class="mb-3">Cheque Details</h5>

        <div class="mb-2">
            <label class="form-label small mb-1">Date</label>
            <input type="date" id="inpDate" class="form-control form-control-sm">
        </div>

        <div class="mb-2">
            <label class="form-label small mb-1">Pay (Payee Name)</label>
            <input type="text" id="inpPayee" class="form-control form-control-sm" placeholder="e.g. Reliance Industries Ltd" maxlength="80">
        </div>

        <div class="mb-2">
            <label class="form-label small mb-1">Amount (Figures)</label>
            <input type="number" id="inpAmount" class="form-control form-control-sm" placeholder="e.g. 12500.00" step="0.01" min="0">
        </div>

        <div class="mb-2">
            <label class="form-label small mb-1">Amount in Words (auto)</label>
            <textarea id="inpWords" class="form-control form-control-sm" rows="2" readonly></textarea>
        </div>

        <h6 class="mt-3 mb-2 text-secondary">Calibration (mm)</h6>
        <div class="row g-2">
            <div class="col-6">
                <label class="form-label small mb-1">Offset X</label>
                <input type="number" id="offX" class="form-control form-control-sm" value="0" step="0.5">
            </div>
            <div class="col-6">
                <label class="form-label small mb-1">Offset Y</label>
                <input type="number" id="offY" class="form-control form-control-sm" value="0" step="0.5">
            </div>
        </div>

        <details class="mt-3">
            <summary class="small text-secondary">Field positions (mm)</summary>
            <div class="row g-1 mt-2" id="fieldPosWrap"></div>
        </details>

        <div class="alert alert-info py-2 small mt-3 mb-0">
            Use a blank A4 first as a calibration overlay before printing on a real cheque.
        </div>
    </div>

    <div class="preview-wrap">
        <div class="cheque" id="cheque">
            <div class="grid" id="grid"></div>

            <div class="hint" style="left:2mm; top:2mm;">HDFC Cheque (CTS-2010) preview &mdash; print rotates to portrait, date first</div>
            <div class="hint" style="left:163mm; top:6mm;">DATE &rarr;</div>
            <div class="hint" style="left:6mm; top:23mm;">PAY &rarr;</div>
            <div class="hint" style="left:6mm; top:34mm;">RUPEES &rarr;</div>
            <div class="hint" style="left:163mm; top:43mm;">&#8377; &rarr;</div>

            <div class="fld date"    id="fldDate"></div>
            <div class="fld payee"   id="fldPayee"></div>
            <div class="fld words"   id="fldWords"></div>
            <div class="fld figures" id="fldFigures"></div>
        </div>
    </div>
</div>

<script>
(function () {
    const DEFAULTS = {
        date:    { x: 163.5, y: 8.5,  maxW: 35 },
        payee:   { x: 24,    y: 24,   maxW: 130 },
        words:   { x: 24,    y: 35,   maxW: 135 },
        figures: { x: 167,   y: 44,   maxW: 32 }
    };

    const fields = ['date', 'payee', 'words', 'figures'];
    const state = JSON.parse(JSON.stringify(DEFAULTS));

    const $ = (id) => document.getElementById(id);
    const inpDate = $('inpDate');
    const inpPayee = $('inpPayee');
    const inpAmount = $('inpAmount');
    const inpWords = $('inpWords');
    const offX = $('offX');
    const offY = $('offY');
    const fieldPosWrap = $('fieldPosWrap');

    const today = new Date();
    const isoToday = today.toISOString().slice(0, 10);
    inpDate.value = isoToday;

    fields.forEach((f) => {
        ['x', 'y'].forEach((axis) => {
            const col = document.createElement('div');
            col.className = 'col-6';
            col.innerHTML =
                '<label class="form-label small mb-0">' + f + ' ' + axis.toUpperCase() + '</label>' +
                '<input type="number" step="0.5" class="form-control form-control-sm" data-field="' + f + '" data-axis="' + axis + '" value="' + state[f][axis] + '">';
            fieldPosWrap.appendChild(col);
        });
    });

    fieldPosWrap.addEventListener('input', (e) => {
        const t = e.target;
        if (!t.dataset || !t.dataset.field) return;
        const v = parseFloat(t.value);
        if (!isNaN(v)) {
            state[t.dataset.field][t.dataset.axis] = v;
            render();
        }
    });

    function ones(n) {
        const a = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
                   'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
                   'Seventeen', 'Eighteen', 'Nineteen'];
        return a[n];
    }
    function tens(n) {
        return ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'][n];
    }
    function twoDigit(n) {
        if (n < 20) return ones(n);
        const t = Math.floor(n / 10);
        const o = n % 10;
        return tens(t) + (o ? ' ' + ones(o) : '');
    }
    function threeDigit(n) {
        let r = '';
        if (n >= 100) {
            r += ones(Math.floor(n / 100)) + ' Hundred';
            n = n % 100;
            if (n) r += ' ';
        }
        if (n) r += twoDigit(n);
        return r;
    }
    function indianWords(num) {
        num = Math.floor(num);
        if (num === 0) return 'Zero';
        let parts = [];
        const crore = Math.floor(num / 10000000);
        num = num % 10000000;
        const lakh = Math.floor(num / 100000);
        num = num % 100000;
        const thousand = Math.floor(num / 1000);
        num = num % 1000;
        const hundred = num;
        if (crore)    parts.push(threeDigit(crore) + ' Crore');
        if (lakh)     parts.push(threeDigit(lakh) + ' Lakh');
        if (thousand) parts.push(threeDigit(thousand) + ' Thousand');
        if (hundred)  parts.push(threeDigit(hundred));
        return parts.join(' ');
    }
    function amountToWords(amt) {
        const rupees = Math.floor(amt);
        const paise = Math.round((amt - rupees) * 100);
        let s = 'Rupees ' + indianWords(rupees);
        if (paise > 0) s += ' and ' + indianWords(paise) + ' Paise';
        s += ' Only';
        return s;
    }

    function formatDateDDMMYYYY(iso) {
        if (!iso) return '';
        const parts = iso.split('-');
        if (parts.length !== 3) return '';
        return parts[2] + parts[1] + parts[0];
    }

    function applyPos(el, key) {
        const p = state[key];
        const dx = parseFloat(offX.value) || 0;
        const dy = parseFloat(offY.value) || 0;
        el.style.left = (p.x + dx) + 'mm';
        el.style.top  = (p.y + dy) + 'mm';
        if (p.maxW) el.style.maxWidth = p.maxW + 'mm';
    }

    function render() {
        const fldDate    = $('fldDate');
        const fldPayee   = $('fldPayee');
        const fldWords   = $('fldWords');
        const fldFigures = $('fldFigures');

        fldDate.textContent = formatDateDDMMYYYY(inpDate.value);
        fldPayee.textContent = (inpPayee.value || '').toUpperCase();

        const amount = parseFloat(inpAmount.value);
        if (!isNaN(amount) && amount > 0) {
            inpWords.value = amountToWords(amount);
            fldFigures.textContent = '**' + amount.toFixed(2) + '/-';
        } else {
            inpWords.value = '';
            fldFigures.textContent = '';
        }
        fldWords.textContent = inpWords.value;

        applyPos(fldDate,    'date');
        applyPos(fldPayee,   'payee');
        applyPos(fldWords,   'words');
        applyPos(fldFigures, 'figures');
    }

    [inpDate, inpPayee, inpAmount, offX, offY].forEach((el) => {
        el.addEventListener('input', render);
    });

    $('toggleGridBtn').addEventListener('click', (e) => {
        const grid = $('grid');
        const hints = document.querySelectorAll('.cheque .hint');
        const hidden = grid.style.display === 'none';
        grid.style.display = hidden ? 'block' : 'none';
        hints.forEach((h) => { h.style.display = hidden ? 'block' : 'none'; });
        e.target.textContent = hidden ? 'Hide Grid' : 'Show Grid';
    });

    $('printBtn').addEventListener('click', () => {
        const grid = $('grid');
        const hints = document.querySelectorAll('.cheque .hint');
        const prevGrid = grid.style.display;
        grid.style.display = 'none';
        hints.forEach((h) => { h.style.display = 'none'; });
        window.print();
        setTimeout(() => {
            grid.style.display = prevGrid || 'block';
            hints.forEach((h) => { h.style.display = 'block'; });
        }, 300);
    });

    render();
})();
</script>

</body>
</html>
