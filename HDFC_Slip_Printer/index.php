<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HDFC Deposit Slip Printer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        html, body { margin: 0; padding: 0; background: #e9ecef; }

        .toolbar {
            position: sticky; top: 0; z-index: 50;
            padding: 10px 16px; background: #212529; color: #fff;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .toolbar .title { font-weight: 600; margin-right: auto; }

        .layout {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 16px;
            padding: 16px;
            align-items: start;
        }
        @media (max-width: 1180px) { .layout { grid-template-columns: 1fr; } }

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

        /* A4 landscape: 297mm x 210mm. Top half holds the slip, bottom half stays blank. */
        .page {
            width: 297mm; height: 210mm;
            position: relative;
            background: #fff;
            margin: 0;
            box-shadow: 0 0 0 1px #ced4da inset;
        }
        .slip {
            width: 297mm; height: 105mm;
            position: relative;
            background-image: url('hdfc_slip_top.png');
            background-size: 297mm 105mm;
            background-repeat: no-repeat;
            background-position: 0 0;
            overflow: hidden;
        }
        .slip.no-bg { background-image: none; }

        /* Browser-only mm grid overlay */
        .slip.show-grid::before {
            content: '';
            position: absolute; inset: 0; pointer-events: none;
            background-image:
                linear-gradient(to right, rgba(255,0,0,0.40) 0, rgba(255,0,0,0.40) 0.2mm, transparent 0.2mm),
                linear-gradient(to right, rgba(255,0,0,0.15) 0, rgba(255,0,0,0.15) 0.1mm, transparent 0.1mm),
                linear-gradient(to bottom, rgba(0,0,255,0.40) 0, rgba(0,0,255,0.40) 0.2mm, transparent 0.2mm),
                linear-gradient(to bottom, rgba(0,0,255,0.15) 0, rgba(0,0,255,0.15) 0.1mm, transparent 0.1mm);
            background-size: 10mm 10mm, 1mm 1mm, 10mm 10mm, 1mm 1mm;
            background-position: 0 0;
        }
        .ruler-x, .ruler-y {
            position: absolute; pointer-events: none;
            font: 8px/1 'Courier New', monospace; color: #cc0000;
        }
        .ruler-x { top: 0; left: 0; width: 297mm; height: 4mm; }
        .ruler-x .tick { position: absolute; top: 0; transform: translateX(-50%); }
        .ruler-y { top: 0; left: 0; width: 5mm; height: 105mm; color: #0000cc; }
        .ruler-y .tick { position: absolute; left: 1px; transform: translateY(-50%); }
        .slip:not(.show-grid) .ruler-x,
        .slip:not(.show-grid) .ruler-y { display: none; }

        .blank-bottom {
            width: 297mm; height: 105mm;
            position: relative;
        }
        .blank-bottom::before {
            content: '';
            position: absolute; left: 0; right: 0; top: 0;
            border-top: 1px dashed #ced4da;
        }
        .blank-bottom .cut-hint {
            position: absolute; left: 50%; top: 0;
            transform: translateX(-50%);
            font-size: 8pt; color: #adb5bd;
            padding: 2px 6px;
        }

        .fld {
            position: absolute;
            color: #000;
            white-space: nowrap;
            line-height: 1;
            font-family: Arial, Helvetica, sans-serif;
        }
        .fld.mono {
            font-family: 'Courier New', Courier, monospace;
            font-weight: bold;
        }
        .fld.date {
            font-family: 'Courier New', Courier, monospace;
            font-weight: bold;
            font-size: 13pt;
        }
        .fld.words { white-space: normal; }

        /* Anchor markers — show exact (x,y) position of every field, even when empty */
        .anchor {
            position: absolute;
            width: 6px; height: 6px;
            margin: -3px 0 0 -3px;
            border: 1px solid #198754;
            background: rgba(25,135,84,0.25);
            border-radius: 50%;
            pointer-events: none;
        }
        .anchor.highlight {
            border-color: #dc3545; background: rgba(220,53,69,0.55);
            width: 10px; height: 10px; margin: -5px 0 0 -5px;
        }
        .slip:not(.show-anchors) .anchor { display: none; }

        .calib-section { border: 1px solid #dee2e6; border-radius: 6px; padding: 8px; margin-bottom: 10px; }
        .calib-section .title { font-weight: 600; font-size: 12px; color: #495057; margin-bottom: 6px; }
        .calib-row { display: grid; grid-template-columns: 90px 1fr 1fr 50px 50px; gap: 4px; align-items: center; margin-bottom: 4px; }
        .calib-row .name { font-size: 11px; color: #495057; }
        .calib-row input { font-size: 11px; padding: 2px 4px; }
        .calib-row.head { font-size: 10px; color: #adb5bd; margin-bottom: 2px; }

        /* Print rules */
        @media print {
            html, body { background: #fff; }
            .no-print { display: none !important; }
            .layout { display: block; padding: 0; }
            .preview-wrap { background: none; box-shadow: none; padding: 0; border: 0; overflow: visible; }
            .page { box-shadow: none !important; }
            .blank-bottom::before { display: none; }
            .blank-bottom .cut-hint { display: none; }
            .anchor { display: none !important; }
            .ruler-x, .ruler-y { display: none !important; }
            .slip::before { display: none !important; } /* hide grid */

            @page { size: A4 landscape; margin: 0; }
            body { margin: 0; }

            .slip {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>

<div class="toolbar no-print">
    <div class="title">HDFC Deposit Slip Printer</div>
    <span class="text-secondary small">A4 landscape &middot; top half = 1 customer + 1 bank copy</span>
    <button class="btn btn-sm btn-outline-light" id="toggleGridBtn">Show mm Grid</button>
    <button class="btn btn-sm btn-outline-light" id="toggleAnchorsBtn">Hide Anchors</button>
    <button class="btn btn-sm btn-outline-light" id="toggleBgBtn">Hide Form Background</button>
    <button class="btn btn-sm btn-success" id="printBtn">Print Slip</button>
</div>

<div class="layout">
    <div class="form-card no-print">
        <h5 class="mb-3">Slip Details</h5>

        <div class="mb-2">
            <label class="form-label small mb-1">Date</label>
            <input type="date" id="inpDate" class="form-control form-control-sm">
        </div>
        <div class="mb-2">
            <label class="form-label small mb-1">Account Number</label>
            <input type="text" id="inpAccount" class="form-control form-control-sm" placeholder="e.g. 50200013471530" maxlength="30">
        </div>
        <div class="mb-2">
            <label class="form-label small mb-1">Contact Number</label>
            <input type="text" id="inpContact" class="form-control form-control-sm" placeholder="e.g. 9374712601" maxlength="15">
        </div>
        <div class="mb-2">
            <label class="form-label small mb-1">Name</label>
            <input type="text" id="inpName" class="form-control form-control-sm" placeholder="e.g. DEEPAK TEXTILES" maxlength="60">
        </div>
        <div class="mb-2">
            <label class="form-label small mb-1">Bank &amp; Branch</label>
            <input type="text" id="inpBankBranch" class="form-control form-control-sm" placeholder="e.g. HDFC" maxlength="40">
        </div>
        <div class="mb-2">
            <label class="form-label small mb-1">Cheque Number</label>
            <input type="text" id="inpChequeNo" class="form-control form-control-sm" placeholder="e.g. 123456" maxlength="15">
        </div>
        <div class="mb-2">
            <label class="form-label small mb-1">Amount (Rupees in figures)</label>
            <input type="number" id="inpAmount" class="form-control form-control-sm" placeholder="e.g. 12500.00" step="0.01" min="0">
        </div>
        <div class="mb-2">
            <label class="form-label small mb-1">Amount in Words (auto)</label>
            <textarea id="inpWords" class="form-control form-control-sm" rows="2" readonly></textarea>
        </div>

        <!-- <h6 class="mt-3 mb-2 text-secondary">Global Calibration (mm)</h6> -->
        <div class="row g-2">
            <div class="col-6">
                <!-- <label class="form-label small mb-1">Offset X (←/→)</label> -->
                <input type="number" id="offX" class="form-control form-control-sm" value="0" step="0.5" hidden>
            </div>
            <div class="col-6">
                <!-- <label class="form-label small mb-1">Offset Y (↑/↓)</label> -->
                <input type="number" id="offY" class="form-control form-control-sm" value="0" step="0.5" hidden>
            </div>
        </div>
        <div class="form-text small mt-1">
            <!-- Shifts every field together. Useful when the whole printout is offset by a few mm. -->
        </div>

        <details class="mt-3" id="calibPanel" hidden>
            <summary class="small text-secondary">Per-field positions (mm) &mdash; click to expand</summary>
            <div class="alert alert-warning small mt-2 mb-2">
                <strong>How alignment works:</strong>
                Each field has an <code>(x, y)</code> in millimetres measured from the
                <strong>top-left corner</strong> of the slip.
                <ul class="mb-1 ps-3">
                    <li><strong>X bigger</strong> &rarr; moves <strong>right</strong></li>
                    <li><strong>X smaller</strong> &rarr; moves <strong>left</strong></li>
                    <li><strong>Y bigger</strong> &rarr; moves <strong>down</strong></li>
                    <li><strong>Y smaller</strong> &rarr; moves <strong>up</strong></li>
                </ul>
                Turn on the <em>mm Grid</em> button (top bar) to read off exact positions
                from the printed image. Each green dot in the preview is a field's anchor &mdash;
                that's where its first character starts. Click a row below to flash its dot red.
            </div>
            <div id="calibWrap"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="resetPosBtn">Reset to defaults</button>
        </details>

        <div class="alert alert-info py-2 small mt-3 mb-0" hidden>
            Print on a blank A4 sheet in <strong>landscape</strong>. Make sure
            <strong>Background graphics</strong> is enabled in the print dialog.
            Bottom half stays blank so you can cut the page in half.
        </div>
    </div>

    <div class="preview-wrap">
        <div class="page" id="page">
            <div class="slip show-anchors" id="slip">
                <!-- Customer Copy fields -->
                <div class="fld date" id="f_date_c"></div>
                <div class="fld mono" id="f_account_c"></div>
                <div class="fld" id="f_name_c"></div>
                <div class="fld" id="f_chqdetails_c"></div>
                <div class="fld mono" id="f_chqno_c"></div>
                <div class="fld mono" id="f_rupees_c"></div>
                <div class="fld mono" id="f_total_c"></div>
                <div class="fld words" id="f_words_c"></div>

                <!-- Bank Copy fields -->
                <div class="fld date" id="f_date_b"></div>
                <div class="fld mono" id="f_account_b"></div>
                <div class="fld mono" id="f_contact_b"></div>
                <div class="fld" id="f_name_b"></div>
                <div class="fld" id="f_bankbranch_b"></div>
                <div class="fld mono" id="f_chqno_b"></div>
                <div class="fld mono" id="f_rupees_b"></div>
                <div class="fld mono" id="f_total_b"></div>
                <div class="fld words" id="f_words_b"></div>

                <!-- Anchor markers + rulers (script-populated) -->
                <div id="anchors"></div>
                <div class="ruler-x" id="rulerX"></div>
                <div class="ruler-y" id="rulerY"></div>
            </div>
            <div class="blank-bottom">
                <span class="cut-hint no-print">— cut here — bottom half intentionally left blank —</span>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    /*
     * Coordinates are in millimetres relative to the slip top-left (0,0).
     * The slip is 297mm wide and 105mm tall.
     *
     * Each field has:
     *   x, y  : where its first character will start
     *   fs    : font size in points
     *   ls    : letter-spacing in mm (mainly for date boxes)
     *   w     : maximum width in mm before wrapping (only matters for the words line)
     */
    const DEFAULTS = {
        // ---------- CUSTOMER COPY (left half) ----------
        date_c:        { section: 'cust', label: 'Date',          x: 80.0,  y: 19.5, w: 35,  fs: 12, ls: 2.50 },
        account_c:     { section: 'cust', label: 'Account No.',   x: 37.5,  y: 29.5, w: 105, fs: 11, ls: 0.0  },
        name_c:        { section: 'cust', label: 'Name',          x: 52.0,  y: 40.0, w: 100, fs: 11, ls: 0.0  },
        chqdetails_c:  { section: 'cust', label: 'Bank/Branch',   x: 39.0,  y: 51.5, w: 44,  fs: 9,  ls: 0.0  },
        chqno_c:       { section: 'cust', label: 'Cheque No.',    x: 79.0,  y: 51.5, w: 25,  fs: 10, ls: 0.0  },
        rupees_c:      { section: 'cust', label: 'Rupees',        x: 97.0,  y: 51.5, w: 30,  fs: 10, ls: 0.0  },
        total_c:       { section: 'cust', label: 'Total Rs.',     x: 97.0,  y: 77.0, w: 30,  fs: 10, ls: 0.0  },
        words_c:       { section: 'cust', label: 'Rupees (words)',x: 55.0,  y: 80.5, w: 70, fs: 9,  ls: 0.0  },

        // ---------- BANK COPY (right half) ----------
        date_b:        { section: 'bank', label: 'Date',          x: 227.0, y: 19.0, w: 35,  fs: 12, ls: 2.50 },
        account_b:     { section: 'bank', label: 'Account No.',   x: 130.0, y: 29.5, w: 60,  fs: 11, ls: 0.0  },
        contact_b:     { section: 'bank', label: 'Contact No.',   x: 218.0, y: 27.5, w: 75,  fs: 11, ls: 0.0  },
        name_b:        { section: 'bank', label: 'Name',          x: 145.0, y: 40.5, w: 120, fs: 11, ls: 0.0  },
        bankbranch_b:  { section: 'bank', label: 'Bank & Branch', x: 130.0, y: 50  , w: 40,  fs: 9,  ls: 0.0  },
        chqno_b:       { section: 'bank', label: 'Cheque No.',    x: 185.0, y: 50  , w: 25,  fs: 10, ls: 0.0  },
        rupees_b:      { section: 'bank', label: 'Rupees',        x: 235.0, y: 50  , w: 27,  fs: 10, ls: 0.0  },
        total_b:       { section: 'bank', label: 'Total Rs.',     x: 235.0, y: 83  , w: 27,  fs: 10, ls: 0.0  },
        words_b:       { section: 'bank', label: 'Rupees (words)',x: 150.0, y: 78  , w: 65,  fs: 9,  ls: 0.0  }
    };

    const STORAGE_KEY = 'hdfc_slip_positions_v2';
    let state = loadState();

    const $ = (id) => document.getElementById(id);
    const inpDate       = $('inpDate');
    const inpAccount    = $('inpAccount');
    const inpContact    = $('inpContact');
    const inpName       = $('inpName');
    const inpBankBranch = $('inpBankBranch');
    const inpChequeNo   = $('inpChequeNo');
    const inpAmount     = $('inpAmount');
    const inpWords      = $('inpWords');
    const offX = $('offX');
    const offY = $('offY');
    const slipEl = $('slip');
    const calibWrap = $('calibWrap');
    const anchorsWrap = $('anchors');

    inpDate.value = new Date().toISOString().slice(0, 10);

    function loadState() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (raw) {
                const parsed = JSON.parse(raw);
                const merged = JSON.parse(JSON.stringify(DEFAULTS));
                Object.keys(parsed).forEach((k) => {
                    if (merged[k]) Object.assign(merged[k], parsed[k]);
                });
                return merged;
            }
        } catch (_) {}
        return JSON.parse(JSON.stringify(DEFAULTS));
    }
    function saveState() {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (_) {}
    }

    function buildRulers() {
        const rx = $('rulerX'), ry = $('rulerY');
        rx.innerHTML = ''; ry.innerHTML = '';
        for (let mm = 0; mm <= 297; mm += 10) {
            const t = document.createElement('span');
            t.className = 'tick'; t.textContent = mm;
            t.style.left = mm + 'mm';
            rx.appendChild(t);
        }
        for (let mm = 0; mm <= 105; mm += 10) {
            const t = document.createElement('span');
            t.className = 'tick'; t.textContent = mm;
            t.style.top = mm + 'mm';
            ry.appendChild(t);
        }
    }

    function buildAnchors() {
        anchorsWrap.innerHTML = '';
        Object.keys(state).forEach((key) => {
            const a = document.createElement('div');
            a.className = 'anchor';
            a.id = 'a_' + key;
            anchorsWrap.appendChild(a);
        });
    }

    function buildCalibrationUI() {
        calibWrap.innerHTML = '';
        const sections = [
            { id: 'cust', title: 'Customer Copy (left half, x: 0 – 148 mm)' },
            { id: 'bank', title: 'Bank Copy (right half, x: 148 – 297 mm)' }
        ];
        sections.forEach((s) => {
            const sec = document.createElement('div');
            sec.className = 'calib-section';
            sec.innerHTML =
                '<div class="title">' + s.title + '</div>' +
                '<div class="calib-row head"><div></div><div>X</div><div>Y</div><div>Font</div><div>L-Sp</div></div>';
            Object.keys(state).forEach((key) => {
                const cfg = state[key];
                if (cfg.section !== s.id) return;
                const row = document.createElement('div');
                row.className = 'calib-row';
                row.dataset.key = key;
                row.innerHTML =
                    '<div class="name">' + cfg.label + '</div>' +
                    '<input type="number" step="0.5" data-key="' + key + '" data-axis="x"  value="' + cfg.x + '">' +
                    '<input type="number" step="0.5" data-key="' + key + '" data-axis="y"  value="' + cfg.y + '">' +
                    '<input type="number" step="0.5" data-key="' + key + '" data-axis="fs" value="' + cfg.fs + '">' +
                    '<input type="number" step="0.05" data-key="' + key + '" data-axis="ls" value="' + cfg.ls + '">';
                sec.appendChild(row);
            });
            calibWrap.appendChild(sec);
        });
    }

    calibWrap.addEventListener('input', (e) => {
        const t = e.target;
        if (!t.dataset || !t.dataset.key) return;
        const v = parseFloat(t.value);
        if (!isNaN(v)) {
            state[t.dataset.key][t.dataset.axis] = v;
            saveState();
            render();
        }
    });
    calibWrap.addEventListener('focusin', (e) => {
        const t = e.target;
        if (!t.dataset || !t.dataset.key) return;
        document.querySelectorAll('.anchor.highlight').forEach((el) => el.classList.remove('highlight'));
        const a = document.getElementById('a_' + t.dataset.key);
        if (a) a.classList.add('highlight');
    });
    calibWrap.addEventListener('focusout', (e) => {
        const t = e.target;
        if (!t.dataset || !t.dataset.key) return;
        const a = document.getElementById('a_' + t.dataset.key);
        if (a) a.classList.remove('highlight');
    });

    $('resetPosBtn').addEventListener('click', () => {
        state = JSON.parse(JSON.stringify(DEFAULTS));
        saveState();
        buildCalibrationUI();
        render();
    });

    // ---------- Number to Indian words ----------
    function ones(n) {
        return ['', 'One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
                'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
                'Seventeen','Eighteen','Nineteen'][n];
    }
    function tens(n) {
        return ['', '', 'Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'][n];
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
        const parts = [];
        const crore = Math.floor(num / 10000000); num = num % 10000000;
        const lakh = Math.floor(num / 100000);    num = num % 100000;
        const thousand = Math.floor(num / 1000);  num = num % 1000;
        if (crore)    parts.push(threeDigit(crore) + ' Crore');
        if (lakh)     parts.push(threeDigit(lakh) + ' Lakh');
        if (thousand) parts.push(threeDigit(thousand) + ' Thousand');
        if (num)      parts.push(threeDigit(num));
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
    function formatRupees(amt) {
        if (isNaN(amt) || amt <= 0) return '';
        return amt.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function applyPos(el, key) {
        const p = state[key];
        const dx = parseFloat(offX.value) || 0;
        const dy = parseFloat(offY.value) || 0;
        el.style.left = (p.x + dx) + 'mm';
        el.style.top  = (p.y + dy) + 'mm';
        if (p.w)  el.style.maxWidth = p.w + 'mm';
        if (p.fs) el.style.fontSize = p.fs + 'pt';
        if (p.ls !== undefined && p.ls !== null) el.style.letterSpacing = p.ls + 'mm';

        const a = document.getElementById('a_' + key);
        if (a) {
            a.style.left = (p.x + dx) + 'mm';
            a.style.top  = (p.y + dy) + 'mm';
        }
    }

    function render() {
        const dateStr = formatDateDDMMYYYY(inpDate.value);
        const account = (inpAccount.value || '').trim();
        const contact = (inpContact.value || '').trim();
        const name = (inpName.value || '').toUpperCase();
        const bankBranch = (inpBankBranch.value || '').toUpperCase();
        const chequeNo = (inpChequeNo.value || '').trim();
        const amount = parseFloat(inpAmount.value);
        const amtFig = formatRupees(amount);
        const words = (!isNaN(amount) && amount > 0) ? amountToWords(amount) : '';
        inpWords.value = words;

        const text = {
            date_c: dateStr, date_b: dateStr,
            account_c: account, account_b: account,
            contact_b: contact,
            name_c: name, name_b: name,
            chqdetails_c: bankBranch, bankbranch_b: bankBranch,
            chqno_c: chequeNo, chqno_b: chequeNo,
            rupees_c: amtFig, rupees_b: amtFig,
            total_c: amtFig, total_b: amtFig,
            words_c: words, words_b: words
        };

        Object.keys(state).forEach((key) => {
            const el = document.getElementById('f_' + key);
            if (!el) return;
            el.textContent = text[key] || '';
            applyPos(el, key);
        });
    }

    [inpDate, inpAccount, inpContact, inpName, inpBankBranch, inpChequeNo, inpAmount, offX, offY]
        .forEach((el) => el.addEventListener('input', render));

    $('toggleGridBtn').addEventListener('click', (e) => {
        const on = slipEl.classList.toggle('show-grid');
        e.target.textContent = on ? 'Hide mm Grid' : 'Show mm Grid';
    });
    $('toggleAnchorsBtn').addEventListener('click', (e) => {
        const on = slipEl.classList.toggle('show-anchors');
        e.target.textContent = on ? 'Hide Anchors' : 'Show Anchors';
    });
    $('toggleBgBtn').addEventListener('click', (e) => {
        const off = slipEl.classList.toggle('no-bg');
        e.target.textContent = off ? 'Show Form Background' : 'Hide Form Background';
    });
    $('printBtn').addEventListener('click', () => { window.print(); });

    buildRulers();
    buildAnchors();
    buildCalibrationUI();
    render();
})();
</script>

</body>
</html>
