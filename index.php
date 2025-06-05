<?php
session_start();

// Handle PDF upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    // Remove existing PDF
    if (isset($_SESSION['pdf_file']) && file_exists($_SESSION['pdf_file'])) {
        unlink($_SESSION['pdf_file']);
    }

    if ($_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        die("Upload failed with error code " . $_FILES['pdf_file']['error']);
    }

    $file_info = pathinfo($_FILES['pdf_file']['name']);
    if (strtolower($file_info['extension']) !== 'pdf') {
        die("Only PDF files are allowed");
    }

    $temp_file = tempnam(sys_get_temp_dir(), 'pdf_');
    move_uploaded_file($_FILES['pdf_file']['tmp_name'], $temp_file);

    $_SESSION['pdf_file'] = $temp_file;
    $_SESSION['pdf_file_name'] = $_FILES['pdf_file']['name'];
    $_SESSION['upload_success'] = true;

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$uploadSuccess = isset($_SESSION['upload_success']);
$pdfFileName = $_SESSION['pdf_file_name'] ?? '';
$hasPdf = isset($_SESSION['pdf_file']);

unset($_SESSION['upload_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bill PDF Viewer</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <link rel="stylesheet" type="text/css" href="style.css">
    <script>
        const hasPdf = <?php echo json_encode($hasPdf); ?>;
    </script>
</head>
<body>
<div class="container">
    <h1>Bill PDF Viewer</h1>

    <?php if ($uploadSuccess): ?>
        <p style="color: green;">✅ PDF uploaded successfully.</p>
    <?php endif; ?>

    <?php if ($pdfFileName): ?>
        <p>📄 Uploaded file: <strong><?php echo htmlspecialchars($pdfFileName); ?></strong></p>
    <?php endif; ?>

    <form action="" method="post" enctype="multipart/form-data">
        <label for="pdf_file">Upload Bill PDF:</label>
        <input type="file" name="pdf_file" id="pdf_file" accept=".pdf" required>
        <button type="submit">Upload</button>
    </form>

    <form action="clean.php" method="post" style="margin-top: 20px;">
        <button type="submit">Reset / Upload New PDF</button>
    </form>

    <div id="searchSection" style="margin-top: 20px;">
        <input type="text" id="searchNumber" placeholder="Enter bill number(s), separated by commas">
        <button type="button" onclick="searchPage()">Search</button>
        <div id="status" style="margin-top: 10px;"></div>
    </div>

    <div id="pdfViewer" style="margin-top: 20px;"></div>
    <button id="downloadBtn" style="display: none; margin-top: 10px;">Download All Results as PDF</button>
</div>

<script>
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.worker.min.js';

    let pdfDoc = null;
    const pageMap = {};

    function loadPdf() {
        const status = document.getElementById('status');
        status.textContent = "Processing PDF...";

        fetch('get_pdf.php')
            .then(response => {
                if (!response.ok) throw new Error("Failed to fetch PDF");
                return response.blob();
            })
            .then(blob => {
                const reader = new FileReader();
                reader.onload = function () {
                    const typedarray = new Uint8Array(this.result);

                    pdfjsLib.getDocument(typedarray).promise.then(function (pdf) {
                        pdfDoc = pdf;
                        status.textContent = `PDF loaded (${pdf.numPages} pages). Extracting numbers...`;

                        const promises = [];
                        for (let i = 1; i <= pdf.numPages; i++) {
                            promises.push(
                                pdf.getPage(i).then(page =>
                                    page.getTextContent().then(tc => {
                                        const text = tc.items.map(it => it.str).join(' ');
                                        const nums = text.match(/\b\d{8,12}\b/g) || [];
                                        nums.forEach(num => {
                                            if (!pageMap[num]) pageMap[num] = i;
                                        });
                                    })
                                )
                            );
                        }

                        Promise.all(promises).then(() => {
                            status.textContent = `Ready. Found ${Object.keys(pageMap).length} unique bill numbers.`;
                            console.log("Number-page map:", pageMap);
                        });
                    }).catch(err => {
                        status.textContent = "Error loading PDF: " + err.message;
                    });
                };
                reader.readAsArrayBuffer(blob);
            })
            .catch(error => {
                status.textContent = "Error: " + error.message;
            });
    }

    function searchPage() {
        const input = document.getElementById('searchNumber').value.trim();
        const status = document.getElementById('status');
        const viewer = document.getElementById('pdfViewer');
        viewer.innerHTML = '';
        document.getElementById('downloadBtn').style.display = 'none';

        if (!pdfDoc) {
            status.textContent = "PDF not loaded yet.";
            return;
        }

        const numbers = input.split(',').map(n => n.trim()).filter(Boolean);
        if (numbers.length === 0) {
            status.textContent = "Enter at least one number.";
            return;
        }

        const foundPages = new Set();
        numbers.forEach(num => {
            if (pageMap[num]) {
                foundPages.add(pageMap[num]);
            }
        });

        if (foundPages.size === 0) {
            status.textContent = "No matching bill numbers found.";
            return;
        }

        status.textContent = `Found on page(s): ${Array.from(foundPages).sort().join(', ')}`;
        document.getElementById('downloadBtn').style.display = 'inline-block';

        foundPages.forEach(pageNum => {
            pdfDoc.getPage(pageNum).then(page => {
                const viewport = page.getViewport({ scale: 1.5 });
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = viewport.width;
                canvas.height = viewport.height;

                page.render({ canvasContext: ctx, viewport: viewport }).promise.then(() => {
                    viewer.appendChild(canvas);
                    viewer.appendChild(document.createElement('hr'));
                });
            });
        });
    }

    document.getElementById('downloadBtn').addEventListener('click', () => {
        const viewer = document.getElementById('pdfViewer');
        const canvases = viewer.querySelectorAll('canvas');
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF();

        let added = false;

        canvases.forEach((canvas, index) => {
            const imgData = canvas.toDataURL('image/png');
            const imgProps = pdf.getImageProperties(imgData);
            const pdfWidth = pdf.internal.pageSize.getWidth();
            const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;

            if (index > 0) pdf.addPage();
            pdf.addImage(imgData, 'PNG', 0, 0, pdfWidth, pdfHeight);
            added = true;
        });

        if (added) {
            pdf.save('matched_bills.pdf');
        }
    });

    window.onload = () => {
        if (hasPdf) loadPdf();
    };
</script>
</body>
</html>
