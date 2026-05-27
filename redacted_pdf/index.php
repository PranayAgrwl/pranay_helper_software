<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redacted PDF</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        html, body { margin: 0; padding: 0; background: #e9ecef; }
        .toolbar {
            position: sticky; top: 0; z-index: 50;
            display: flex; align-items: center; gap: 8px;
            padding: 10px 16px; background: #212529; color: #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        .toolbar .title { font-weight: 600; margin-right: auto; }
        .hint { font-size: 0.85rem; color: #adb5bd; margin-left: 12px; }
        .a4-wrap { display: flex; justify-content: center; padding: 24px 12px; }
        .a4 {
            width: 210mm; height: 297mm;
            background: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 6px 18px rgba(0,0,0,0.18);
            cursor: crosshair;
        }
        .crap-layer {
            position: absolute; inset: 0;
            padding: 6mm 4mm 6mm 6mm;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 8.5pt; line-height: 1.1; font-weight: 700;
            color: #111;
            text-align: justify;
            white-space: pre-wrap; word-break: break-word;
            user-select: none; pointer-events: none;
        }
        .crap-layer p, .redact-box .crap-clone p {
            margin: 0 0 1mm 0;
        }
        .white-cloth {
            position: absolute; inset: 0;
            background: #ffffff;
            pointer-events: none;
        }
        .preview-mode .white-cloth { background: rgba(255,255,255,0.92); }
        .redact-box {
            position: absolute;
            overflow: hidden;
            background: #ffffff;
            outline: 1px dashed #888;
        }
        .redact-box .crap-clone {
            position: absolute;
            width: 210mm; height: 297mm;
            padding: 6mm 4mm 6mm 6mm;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 8.5pt; line-height: 1.1; font-weight: 700;
            color: #111;
            text-align: justify;
            white-space: pre-wrap; word-break: break-word;
            user-select: none; pointer-events: none;
        }
        .redact-box .close-btn {
            position: absolute; top: 2px; right: 2px;
            width: 20px; height: 20px; border-radius: 50%;
            background: #dc3545; color: #fff;
            font-size: 13px; line-height: 20px; font-weight: bold;
            text-align: center; cursor: pointer; user-select: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
        .drawing-rect {
            position: absolute;
            outline: 1px dashed #0d6efd;
            background: rgba(13,110,253,0.08);
            pointer-events: none;
        }

        @media print {
            html, body { background: #ffffff; }
            .toolbar, .no-print { display: none !important; }
            .a4-wrap { padding: 0; }
            .a4 {
                width: 210mm; height: 297mm;
                box-shadow: none;
                page-break-after: avoid;
            }
            .crap-layer, .white-cloth { display: none !important; }
            .redact-box { outline: none !important; background: #ffffff; }
            .redact-box .close-btn { display: none !important; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>

<div class="toolbar no-print">
    <div class="title">Redacted PDF</div>
    <span class="hint">Click and drag on the page to draw redaction boxes</span>
    <button class="btn btn-sm btn-outline-light" id="togglePreviewBtn">Peek Underlying</button>
    <button class="btn btn-sm btn-warning" id="undoBtn">Undo Last</button>
    <button class="btn btn-sm btn-danger" id="clearBtn">Clear All</button>
    <button class="btn btn-sm btn-success" id="printBtn">Print / Save PDF</button>
</div>

<div class="a4-wrap">
    <div class="a4" id="a4">
        <div class="crap-layer" id="crapLayer"></div>
        <div class="white-cloth" id="whiteCloth"></div>
    </div>
</div>

<script>
(function () {
    const LOREM_PARA = [
        "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.",
        "Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.",
        "Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo.",
        "Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet.",
        "At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores et quas molestias excepturi sint occaecati cupiditate non provident.",
        "Temporibus autem quibusdam et aut officiis debitis aut rerum necessitatibus saepe eveniet ut et voluptates repudiandae sint et molestiae non recusandae. Itaque earum rerum hic tenetur a sapiente delectus.",
        "Curabitur pretium tincidunt lacus. Nulla gravida orci a odio. Nullam varius, turpis et commodo pharetra, est eros bibendum elit, nec luctus magna felis sollicitudin mauris. Integer in mauris eu nibh euismod gravida.",
        "Duis ac tellus et risus vulputate vehicula. Donec lobortis risus a elit. Etiam tempor. Ut ullamcorper, ligula eu tempor congue, eros est euismod turpis, id tincidunt sapien risus a quam. Maecenas fermentum consequat mi."
    ];

    const lorem = [];
    for (let i = 0; i < 80; i++) {
        lorem.push(LOREM_PARA[i % LOREM_PARA.length]);
    }
    const LOREM = lorem.join(" ");

    const a4 = document.getElementById("a4");
    const crapLayer = document.getElementById("crapLayer");
    const undoBtn = document.getElementById("undoBtn");
    const clearBtn = document.getElementById("clearBtn");
    const printBtn = document.getElementById("printBtn");
    const togglePreviewBtn = document.getElementById("togglePreviewBtn");

    crapLayer.textContent = LOREM;

    const boxes = [];
    let drawing = false;
    let startX = 0, startY = 0;
    let activeRect = null;

    function pageCoords(e) {
        const r = a4.getBoundingClientRect();
        return {
            x: Math.max(0, Math.min(r.width,  e.clientX - r.left)),
            y: Math.max(0, Math.min(r.height, e.clientY - r.top))
        };
    }

    a4.addEventListener("mousedown", function (e) {
        if (e.target.classList && e.target.classList.contains("close-btn")) return;
        if (e.button !== 0) return;
        const p = pageCoords(e);
        startX = p.x; startY = p.y;
        drawing = true;
        activeRect = document.createElement("div");
        activeRect.className = "drawing-rect no-print";
        activeRect.style.left = startX + "px";
        activeRect.style.top = startY + "px";
        activeRect.style.width = "0px";
        activeRect.style.height = "0px";
        a4.appendChild(activeRect);
        e.preventDefault();
    });

    document.addEventListener("mousemove", function (e) {
        if (!drawing || !activeRect) return;
        const p = pageCoords(e);
        const x = Math.min(startX, p.x);
        const y = Math.min(startY, p.y);
        const w = Math.abs(p.x - startX);
        const h = Math.abs(p.y - startY);
        activeRect.style.left = x + "px";
        activeRect.style.top = y + "px";
        activeRect.style.width = w + "px";
        activeRect.style.height = h + "px";
    });

    document.addEventListener("mouseup", function () {
        if (!drawing || !activeRect) return;
        const rect = {
            left:   parseFloat(activeRect.style.left)   || 0,
            top:    parseFloat(activeRect.style.top)    || 0,
            width:  parseFloat(activeRect.style.width)  || 0,
            height: parseFloat(activeRect.style.height) || 0
        };
        activeRect.remove();
        activeRect = null;
        drawing = false;
        if (rect.width < 6 || rect.height < 6) return;
        createBox(rect);
    });

    function createBox(r) {
        const box = document.createElement("div");
        box.className = "redact-box";
        box.style.left = r.left + "px";
        box.style.top = r.top + "px";
        box.style.width = r.width + "px";
        box.style.height = r.height + "px";

        const clone = document.createElement("div");
        clone.className = "crap-clone";
        clone.textContent = LOREM;
        clone.style.left = (-r.left) + "px";
        clone.style.top = (-r.top) + "px";
        box.appendChild(clone);

        const closeBtn = document.createElement("div");
        closeBtn.className = "close-btn no-print";
        closeBtn.textContent = "\u00d7";
        closeBtn.title = "Remove this box";
        closeBtn.addEventListener("mousedown", function (e) {
            e.stopPropagation();
        });
        closeBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            box.remove();
            const idx = boxes.indexOf(box);
            if (idx >= 0) boxes.splice(idx, 1);
        });
        box.appendChild(closeBtn);

        a4.appendChild(box);
        boxes.push(box);
    }

    undoBtn.addEventListener("click", function () {
        const last = boxes.pop();
        if (last) last.remove();
    });

    clearBtn.addEventListener("click", function () {
        while (boxes.length) {
            const b = boxes.pop();
            b.remove();
        }
    });

    let preview = false;
    togglePreviewBtn.addEventListener("click", function () {
        preview = !preview;
        a4.classList.toggle("preview-mode", preview);
        togglePreviewBtn.textContent = preview ? "Hide Underlying" : "Peek Underlying";
    });

    printBtn.addEventListener("click", function () {
        window.print();
    });
})();
</script>

</body>
</html>
